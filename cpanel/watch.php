<?php
/**
 * نسخة cPanel من بوت مراقبة سعر USDT.
 * تُشغَّل عن طريق Cron Job كل دقيقة: كل تشغيلة تفحص السعر مرة واحدة وتنتهي.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$cfg = require __DIR__ . '/config.php';

// قيم افتراضية للإعدادات الجديدة، حتى يعمل config.php القديم بدون تعديل
$cfg += [
    // تحديث دوري بالسعر كل كم دقيقة إذا لم تُرسل أي رسالة (0 = إيقاف)
    'update_minutes' => 60,
    // تنبيه القفزة السريعة: تغيّر بهذه النسبة أو أكثر خلال هذه المدة
    'spike_percent' => 2.0,
    'spike_window_minutes' => 60,
    // مبالغ الحاسبة بالدولار ([] = إخفاء الحاسبة)
    'calc_amounts' => [10, 50, 100],
    'timezone' => 'Africa/Khartoum',
    // الأسماء الظاهرة في الرسائل
    'asset_label' => 'الدولار',
    'currency_label' => 'جنيه',
    // توقيع القناة أسفل كل رسالة (null = يؤخذ من chat_id إذا كان @username، '' = بدون توقيع)
    'channel_tag' => null,
];
if ($cfg['channel_tag'] === null) {
    $cfg['channel_tag'] = strpos((string)$cfg['chat_id'], '@') === 0 ? $cfg['chat_id'] : '';
}
date_default_timezone_set($cfg['timezone']);

const BINANCE_URL = 'https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search';
// عدد المحاولات الفاشلة المتتالية قبل تحذير القناة
const FAILURES_BEFORE_WARNING = 10;

// منع تشغيل نسختين في نفس الوقت
$lock = fopen(__DIR__ . '/watch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "نسخة أخرى من السكربت شغالة الآن، جرّب بعد ثوانٍ.\n";
    exit(0);
}

// php watch.php --test  → يرسل رسالة تجريبية للقناة ويطبع نتيجة كل خطوة
$testMode = in_array('--test', $argv ?? [], true);

if (!function_exists('http_post_json')) {
    function http_post_json(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) p2p-watch/0.1',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("اتصال فاشل: $err");
        }
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException("HTTP $code: " . substr($raw, 0, 200));
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('رد غير مفهوم');
        }
        return $data;
    }
}

/**
 * BUY  = إعلانات تشتري منها USDT (تبويب "شراء" في التطبيق)
 * SELL = إعلانات تبيع لها USDT (تبويب "بيع")
 * يرجع وسيط أسعار أفضل top_n إعلان حتى لا يتأثر بإعلان شاذ أو مروَّج.
 */
function fetch_price(array $cfg, string $tradeType): float
{
    $resp = http_post_json(BINANCE_URL, [
        'fiat' => $cfg['fiat'],
        'asset' => $cfg['asset'],
        'tradeType' => $tradeType,
        'page' => 1,
        'rows' => 20,
        'payTypes' => $cfg['pay_types'],
        'countries' => [],
        'publisherType' => null,
        'proMerchantAds' => false,
        'shieldMerchantAds' => false,
    ]);

    $prices = [];
    foreach ($resp['data'] ?? [] as $item) {
        $orders = (float)($item['advertiser']['monthOrderCount'] ?? 0);
        $price = (float)($item['adv']['price'] ?? 0);
        // الإعلان المروَّج يحمل علامة privilege في رد Binance
        $promoted = !empty($item['adv']['privilegeType']) || !empty($item['adv']['privilegeDesc']);
        if (!$promoted && $orders >= $cfg['min_orders'] && $price > 0) {
            $prices[] = $price;
        }
    }
    if (!$prices) {
        throw new RuntimeException("لا توجد إعلانات $tradeType مطابقة");
    }

    // نأخذ أفضل top_n سعر فعلياً (الأرخص للشراء، الأعلى للبيع) بدل أول top_n في القائمة،
    // فالإعلان المروَّج الموضوع أعلى القائمة بسعر أسوأ يُستبعد حتى لو لم تكن عليه علامة.
    $tradeType === 'BUY' ? sort($prices) : rsort($prices);
    $prices = array_slice($prices, 0, $cfg['top_n']);
    sort($prices);
    $n = count($prices);
    $mid = intdiv($n, 2);
    return $n % 2 ? $prices[$mid] : ($prices[$mid - 1] + $prices[$mid]) / 2;
}

function send_telegram(array $cfg, string $text): bool
{
    try {
        http_post_json("https://api.telegram.org/bot{$cfg['bot_token']}/sendMessage", [
            'chat_id' => $cfg['chat_id'],
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
        return true;
    } catch (Throwable $e) {
        fwrite(STDERR, 'فشل الإرسال لتلجرام: ' . $e->getMessage() . "\n");
        return false;
    }
}

function pct(float $cur, float $ref): float
{
    return ($cur - $ref) / $ref * 100;
}

/** 10774.00 → "10,774" و 10650.99 → "10,650.99" */
function fmt(float $v): string
{
    $s = number_format($v, 2);
    return substr($s, -3) === '.00' ? substr($s, 0, -3) : $s;
}

/** "▲ 70 جنيه (0.65%)" */
function move_text(array $cfg, float $cur, float $from): string
{
    $d = $cur - $from;
    $arrow = $d > 0 ? '🔺' : ($d < 0 ? '🔻' : '▪️');
    return sprintf('%s %s %s (%.2f%%)', $arrow, fmt(abs($d)), $cfg['currency_label'], abs(pct($cur, $from)));
}

/** بطاقة الأسعار؛ الرقم داخل <code> ليُنسخ بضغطة واحدة */
function price_card(float $buy, float $sell, ?float $buyRef = null, ?float $sellRef = null): string
{
    $row = function (string $dot, string $label, float $cur, ?float $ref): string {
        $line = "$dot <b>$label</b>   <code>" . fmt($cur) . '</code>';
        if ($ref !== null) {
            $line .= "\n       كان " . fmt($ref);
        }
        return $line;
    };
    return '<blockquote>' . $row('🟢', 'شراء', $buy, $buyRef) . "\n" . $row('🔴', 'بيع', $sell, $sellRef) . '</blockquote>';
}

/** حاسبة سريعة: كم يساوي 10$ و50$ و100$ بسعر الشراء الحالي */
function calc_card(array $cfg, float $buy): string
{
    if (!$cfg['calc_amounts']) {
        return '';
    }
    $lines = ["🧮 <b>كم يساوي بال{$cfg['currency_label']}؟</b>"];
    foreach ($cfg['calc_amounts'] as $usd) {
        $lines[] = "$usd$ = " . number_format($usd * $buy) . " {$cfg['currency_label']}";
    }
    return "\n<blockquote>" . implode("\n", $lines) . '</blockquote>';
}

function footer(array $cfg): string
{
    return "\n🕒 " . date('H:i') . ($cfg['channel_tag'] !== '' ? "  •  {$cfg['channel_tag']}" : '');
}

// ---------- الحالة ----------

$stateFile = __DIR__ . '/state.json';
$state = (is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null) ?: [];
$state += [
    'buy_ref' => null,
    'sell_ref' => null,
    'failures' => 0,
    'outage_reported' => false,
    // وقت آخر رسالة أُرسلت للقناة
    'last_post' => 0,
    // أسعار الشراء خلال نافذة القفزة السريعة: [[وقت, سعر], ...]
    'history' => [],
];

$save = function () use (&$state, $stateFile) {
    file_put_contents($stateFile, json_encode($state), LOCK_EX);
};

// ---------- الفحص ----------

try {
    $buy = fetch_price($cfg, 'BUY');
    $sell = fetch_price($cfg, 'SELL');
} catch (Throwable $e) {
    $state['failures']++;
    fwrite(STDERR, "فشل جلب السعر ({$state['failures']}): " . $e->getMessage() . "\n");
    if ($state['failures'] >= FAILURES_BEFORE_WARNING && !$state['outage_reported']) {
        $state['outage_reported'] = send_telegram(
            $cfg,
            '⚠️ <b>تنبيه:</b> تعذّر تحديث الأسعار حالياً. راقب السعر يدوياً حتى تعود الخدمة.'
        );
    }
    $save();
    exit(1);
}

if ($state['outage_reported']) {
    send_telegram($cfg, '✅ عادت مراقبة الأسعار للعمل.');
}
$state['failures'] = 0;
$state['outage_reported'] = false;

$fiat = $cfg['fiat'];
$name = $cfg['asset_label'];
$now = time();

echo 'Binance OK — شراء=' . number_format($buy, 2) . ' بيع=' . number_format($sell, 2)
    . ' | المرجع: شراء=' . ($state['buy_ref'] ?? '-') . ' بيع=' . ($state['sell_ref'] ?? '-') . "\n";

if ($testMode) {
    $ok = send_telegram(
        $cfg,
        "🧪 رسالة تجريبية\nشراء: " . number_format($buy, 2) . " $fiat\nبيع: " . number_format($sell, 2) . " $fiat"
    );
    echo $ok
        ? "Telegram OK — وصلت الرسالة إلى {$cfg['chat_id']}\n"
        : "Telegram FAILED — السبب في السطر أعلاه (chat_id الحالي: {$cfg['chat_id']})\n";
    exit($ok ? 0 : 1);
}

// أول تشغيل: نثبت السعر المرجعي ونرسل رسالة بداية
if ($state['buy_ref'] === null || $state['sell_ref'] === null) {
    $msg = "🤖 <b>بدأت مراقبة سعر $name</b>\n"
        . price_card($buy, $sell)
        . "\n🔔 سيصلك تنبيه عند تغيّر السعر {$cfg['threshold_percent']}% أو أكثر."
        . footer($cfg);
    if (send_telegram($cfg, $msg)) {
        $state['buy_ref'] = $buy;
        $state['sell_ref'] = $sell;
        $state['last_post'] = $now;
        $state['history'] = [[$now, $buy]];
    }
    $save();
    exit(0);
}

// ---------- القفزة السريعة ----------
// نقارن سعر الشراء الحالي بكل سعر مسجّل داخل النافذة الزمنية ونأخذ أكبر فرق

$cut = $now - $cfg['spike_window_minutes'] * 60;
$state['history'] = array_values(array_filter($state['history'], fn($h) => $h[0] >= $cut));

$spike = null;
foreach ($state['history'] as [$ts, $price]) {
    $d = pct($buy, $price);
    if (abs($d) >= $cfg['spike_percent'] && ($spike === null || abs($d) > abs($spike['pct']))) {
        $spike = ['pct' => $d, 'from' => $price, 'minutes' => max(1, (int)round(($now - $ts) / 60))];
    }
}
$state['history'][] = [$now, $buy];

// ---------- التنبيهات ----------

$buyPct = pct($buy, $state['buy_ref']);
$sellPct = pct($sell, $state['sell_ref']);
$buyMoved = abs($buyPct) >= $cfg['threshold_percent'];
$sellMoved = abs($sellPct) >= $cfg['threshold_percent'];

$cards = price_card($buy, $sell, $state['buy_ref'], $state['sell_ref']) . calc_card($cfg, $buy);

$msg = null;
$isAlert = false;

if ($spike) {
    $up = $spike['pct'] > 0;
    $msg = ($up ? "⚡️📈 <b>تحرك سريع — $name قفز</b>" : "⚡️📉 <b>تحرك سريع — $name هبط</b>")
        . "\n<b>" . move_text($cfg, $buy, $spike['from']) . "</b> خلال {$spike['minutes']} دقيقة\n"
        . $cards
        . "\n⏰ <i>راجع أسعار متجرك الآن</i>"
        . footer($cfg);
    $isAlert = true;
} elseif ($buyMoved || $sellMoved) {
    [$cur, $ref] = $buyMoved ? [$buy, $state['buy_ref']] : [$sell, $state['sell_ref']];
    $up = $cur > $ref;
    $msg = ($up ? "📈 <b>$name ارتفع</b>" : "📉 <b>$name انخفض</b>")
        . "\n<b>" . move_text($cfg, $cur, $ref) . "</b>\n"
        . $cards . footer($cfg);
    $isAlert = true;
} elseif ($cfg['update_minutes'] > 0 && $now - $state['last_post'] >= $cfg['update_minutes'] * 60) {
    // تحديث دوري: لا يغيّر السعر المرجعي، حتى لا يضيع التغيّر البطيء المتراكم
    $drift = abs($buyPct) < 0.05
        ? '▪️ مستقر منذ آخر تنبيه'
        : move_text($cfg, $buy, $state['buy_ref']) . ' منذ آخر تنبيه';
    $msg = "📊 <b>سعر $name الآن</b>\n$drift\n"
        . price_card($buy, $sell) . calc_card($cfg, $buy) . footer($cfg);
}

// لو فشل الإرسال لا نحدّث شيئاً، فتُعاد المحاولة في التشغيلة القادمة
if ($msg !== null && send_telegram($cfg, $msg)) {
    $state['last_post'] = $now;
    if ($isAlert) {
        $state['buy_ref'] = $buy;
        $state['sell_ref'] = $sell;
    }
    if ($spike) {
        // نبدأ نافذة جديدة حتى لا يتكرر تنبيه القفزة كل دقيقة
        $state['history'] = [[$now, $buy]];
    }
}

$save();
