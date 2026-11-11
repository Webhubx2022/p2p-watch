<?php
/**
 * نسخة cPanel من بوت مراقبة سعر USDT في Binance P2P.
 * تُشغَّل عن طريق Cron Job كل دقيقة: كل تشغيلة تفحص السعر مرة واحدة وتنتهي.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$cfg = require __DIR__ . '/config.php';

const BINANCE_URL = 'https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search';
// عدد المحاولات الفاشلة المتتالية قبل تحذير القناة
const FAILURES_BEFORE_WARNING = 10;

// منع تشغيل نسختين في نفس الوقت
$lock = fopen(__DIR__ . '/watch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

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
        if ($orders >= $cfg['min_orders'] && $price > 0) {
            $prices[] = $price;
        }
        if (count($prices) >= $cfg['top_n']) {
            break;
        }
    }
    if (!$prices) {
        throw new RuntimeException("لا توجد إعلانات $tradeType مطابقة");
    }

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

function change_line(string $label, float $cur, float $ref, string $fiat): string
{
    $p = pct($cur, $ref);
    $arrow = $p > 0 ? '🔺' : ($p < 0 ? '🔻' : '▪️');
    return sprintf(
        "%s %s: <b>%s</b> %s\n     كان %s (%+.2f%%)",
        $arrow, $label, number_format($cur, 2), $fiat, number_format($ref, 2), $p
    );
}

// ---------- الحالة ----------

$stateFile = __DIR__ . '/state.json';
$state = (is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null) ?: [];
$state += ['buy_ref' => null, 'sell_ref' => null, 'failures' => 0, 'outage_reported' => false];

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
            '⚠️ <b>تنبيه:</b> البوت غير قادر على قراءة أسعار Binance P2P حالياً. راقب السعر يدوياً حتى تعود الخدمة.'
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
$asset = $cfg['asset'];

// أول تشغيل: نثبت السعر المرجعي ونرسل رسالة بداية
if ($state['buy_ref'] === null || $state['sell_ref'] === null) {
    $msg = "🤖 <b>بدأت مراقبة $asset/$fiat</b>\n\n"
        . '🟢 شراء: <b>' . number_format($buy, 2) . "</b> $fiat\n"
        . '🔴 بيع: <b>' . number_format($sell, 2) . "</b> $fiat\n\n"
        . "سيصلك تنبيه عند تغيّر السعر {$cfg['threshold_percent']}% أو أكثر.";
    if (send_telegram($cfg, $msg)) {
        $state['buy_ref'] = $buy;
        $state['sell_ref'] = $sell;
    }
    $save();
    exit(0);
}

$buyPct = pct($buy, $state['buy_ref']);
$sellPct = pct($sell, $state['sell_ref']);
$buyMoved = abs($buyPct) >= $cfg['threshold_percent'];
$sellMoved = abs($sellPct) >= $cfg['threshold_percent'];

if ($buyMoved || $sellMoved) {
    $mainPct = $buyMoved ? $buyPct : $sellPct;
    $title = $mainPct > 0 ? "🚨📈 <b>ارتفاع سعر $asset</b>" : "🚨📉 <b>انخفاض سعر $asset</b>";
    $msg = "$title\n\n"
        . change_line('شراء', $buy, $state['buy_ref'], $fiat) . "\n"
        . change_line('بيع', $sell, $state['sell_ref'], $fiat) . "\n\n"
        . "<i>Binance P2P — $fiat</i>";

    // لو فشل الإرسال لا نحدّث المرجع، فيعاد التنبيه في التشغيلة القادمة
    if (send_telegram($cfg, $msg)) {
        $state['buy_ref'] = $buy;
        $state['sell_ref'] = $sell;
    }
}

$save();
