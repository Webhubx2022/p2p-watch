//! بوت مراقبة سعر USDT في سوق Binance P2P وإرسال تنبيه لقناة تلجرام
//! عند ارتفاع أو انخفاض السعر بنسبة محددة.

use std::{env, fs, time::Duration};

use serde::{Deserialize, Serialize};
use serde_json::json;

type Res<T> = Result<T, Box<dyn std::error::Error + Send + Sync>>;

const BINANCE_URL: &str = "https://p2p.binance.com/bapi/c2c/v2/friendly/c2c/adv/search";

// ---------- الإعدادات ----------

struct Config {
    bot_token: String,
    chat_id: String,
    fiat: String,
    asset: String,
    /// كل كم ثانية نفحص السوق
    poll_seconds: u64,
    /// نسبة التغيّر (%) التي يُرسل عندها التنبيه
    threshold_percent: f64,
    /// عدد الإعلانات التي يُحسب منها السعر (الوسيط)
    top_n: usize,
    /// تجاهل المعلنين الذين طلباتهم الشهرية أقل من هذا العدد
    min_orders: f64,
    /// طرق الدفع (اختياري)، مثال: BankofKhartoum
    pay_types: Vec<String>,
    state_file: String,
}

fn var_or(key: &str, default: &str) -> String {
    env::var(key)
        .ok()
        .filter(|v| !v.trim().is_empty())
        .unwrap_or_else(|| default.to_string())
}

impl Config {
    fn load() -> Res<Self> {
        Ok(Self {
            bot_token: env::var("TELEGRAM_BOT_TOKEN").map_err(|_| "TELEGRAM_BOT_TOKEN غير موجود")?,
            chat_id: env::var("TELEGRAM_CHAT_ID").map_err(|_| "TELEGRAM_CHAT_ID غير موجود")?,
            fiat: var_or("FIAT", "SDG"),
            asset: var_or("ASSET", "USDT"),
            poll_seconds: var_or("POLL_SECONDS", "60").parse()?,
            threshold_percent: var_or("THRESHOLD_PERCENT", "1.0").parse()?,
            top_n: var_or("TOP_N", "5").parse()?,
            min_orders: var_or("MIN_ORDERS", "10").parse()?,
            pay_types: var_or("PAY_TYPES", "")
                .split(',')
                .map(|s| s.trim().to_string())
                .filter(|s| !s.is_empty())
                .collect(),
            state_file: var_or("STATE_FILE", "state.json"),
        })
    }
}

// ---------- Binance P2P ----------

#[derive(Deserialize)]
struct SearchResponse {
    #[serde(default)]
    data: Option<Vec<Item>>,
}

#[derive(Deserialize)]
struct Item {
    adv: Adv,
    #[serde(default)]
    advertiser: Option<Advertiser>,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Adv {
    price: String,
    /// علامة الإعلان المروَّج في رد Binance
    #[serde(default)]
    privilege_type: Option<serde_json::Value>,
    #[serde(default)]
    privilege_desc: Option<serde_json::Value>,
}

fn is_set(v: &Option<serde_json::Value>) -> bool {
    match v {
        None | Some(serde_json::Value::Null) => false,
        Some(serde_json::Value::String(s)) => !s.is_empty(),
        Some(_) => true,
    }
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Advertiser {
    #[serde(default)]
    month_order_count: Option<f64>,
}

/// trade_type = "BUY"  → إعلانات تشتري منها USDT (تبويب "شراء" في التطبيق)
/// trade_type = "SELL" → إعلانات تبيع لها USDT (تبويب "بيع")
/// يرجع وسيط أسعار أفضل top_n إعلان، حتى لا يتأثر السعر بإعلان شاذ أو مروَّج.
async fn fetch_price(http: &reqwest::Client, cfg: &Config, trade_type: &str) -> Res<f64> {
    let body = json!({
        "fiat": cfg.fiat,
        "asset": cfg.asset,
        "tradeType": trade_type,
        "page": 1,
        "rows": 20,
        "payTypes": cfg.pay_types,
        "countries": [],
        "publisherType": null,
        "proMerchantAds": false,
        "shieldMerchantAds": false,
    });

    let resp: SearchResponse = http
        .post(BINANCE_URL)
        .json(&body)
        .send()
        .await?
        .error_for_status()?
        .json()
        .await?;

    let mut prices: Vec<f64> = resp
        .data
        .unwrap_or_default()
        .into_iter()
        .filter(|it| {
            it.advertiser
                .as_ref()
                .and_then(|a| a.month_order_count)
                .unwrap_or(0.0)
                >= cfg.min_orders
        })
        .filter(|it| !is_set(&it.adv.privilege_type) && !is_set(&it.adv.privilege_desc))
        .filter_map(|it| it.adv.price.parse::<f64>().ok())
        .collect();

    if prices.is_empty() {
        return Err(format!("لا توجد إعلانات {trade_type} مطابقة").into());
    }

    // نأخذ أفضل top_n سعر فعلياً (الأرخص للشراء، الأعلى للبيع) بدل أول top_n في القائمة،
    // فالإعلان المروَّج الموضوع أعلى القائمة بسعر أسوأ يُستبعد حتى لو لم تكن عليه علامة.
    if trade_type == "BUY" {
        prices.sort_by(|a, b| a.total_cmp(b));
    } else {
        prices.sort_by(|a, b| b.total_cmp(a));
    }
    prices.truncate(cfg.top_n);
    prices.sort_by(|a, b| a.total_cmp(b));
    let mid = prices.len() / 2;
    Ok(if prices.len() % 2 == 1 {
        prices[mid]
    } else {
        (prices[mid - 1] + prices[mid]) / 2.0
    })
}

// ---------- Telegram ----------

async fn send_telegram(http: &reqwest::Client, cfg: &Config, text: &str) -> Res<()> {
    let url = format!("https://api.telegram.org/bot{}/sendMessage", cfg.bot_token);
    let resp = http
        .post(url)
        .json(&json!({
            "chat_id": cfg.chat_id,
            "text": text,
            "parse_mode": "HTML",
            "disable_web_page_preview": true,
        }))
        .send()
        .await?;
    if !resp.status().is_success() {
        let status = resp.status();
        let body = resp.text().await.unwrap_or_default();
        return Err(format!("Telegram {status}: {body}").into());
    }
    Ok(())
}

// ---------- الحالة (آخر سعر تم التنبيه عنده) ----------

#[derive(Serialize, Deserialize, Default)]
struct State {
    buy_ref: Option<f64>,
    sell_ref: Option<f64>,
}

impl State {
    fn load(path: &str) -> Self {
        fs::read_to_string(path)
            .ok()
            .and_then(|s| serde_json::from_str(&s).ok())
            .unwrap_or_default()
    }

    fn save(&self, path: &str) {
        if let Ok(s) = serde_json::to_string(self) {
            if let Err(e) = fs::write(path, s) {
                eprintln!("تعذر حفظ الحالة: {e}");
            }
        }
    }
}

// ---------- أدوات ----------

/// 10600.99 → "10,600.99"
fn fmt_money(v: f64) -> String {
    let s = format!("{v:.2}");
    let (int, frac) = s.split_once('.').unwrap_or((&s, "00"));
    let mut out = String::new();
    for (i, c) in int.chars().enumerate() {
        if i > 0 && (int.len() - i) % 3 == 0 {
            out.push(',');
        }
        out.push(c);
    }
    format!("{out}.{frac}")
}

fn pct(cur: f64, reference: f64) -> f64 {
    (cur - reference) / reference * 100.0
}

fn change_line(label: &str, cur: f64, reference: f64, fiat: &str) -> String {
    let p = pct(cur, reference);
    let arrow = if p > 0.0 {
        "🔺"
    } else if p < 0.0 {
        "🔻"
    } else {
        "▪️"
    };
    format!(
        "{arrow} {label}: <b>{}</b> {fiat}\n     كان {} ({:+.2}%)",
        fmt_money(cur),
        fmt_money(reference),
        p
    )
}

// ---------- الحلقة الرئيسية ----------

#[tokio::main]
async fn main() -> Res<()> {
    let _ = dotenvy::dotenv();
    let cfg = Config::load()?;

    let http = reqwest::Client::builder()
        .timeout(Duration::from_secs(20))
        .user_agent("Mozilla/5.0 (X11; Linux x86_64) p2p-watch/0.1")
        .build()?;

    let mut state = State::load(&cfg.state_file);
    let mut failures: u32 = 0;
    let mut outage_reported = false;
    // عدد المحاولات الفاشلة المتتالية قبل تنبيه القناة بأن المراقبة متوقفة
    const FAILURES_BEFORE_WARNING: u32 = 10;

    println!(
        "بدأت المراقبة: {}/{} كل {} ثانية، حد التنبيه {}%",
        cfg.asset, cfg.fiat, cfg.poll_seconds, cfg.threshold_percent
    );

    let mut ticker = tokio::time::interval(Duration::from_secs(cfg.poll_seconds));
    loop {
        ticker.tick().await;

        let (buy, sell) = match tokio::try_join!(
            fetch_price(&http, &cfg, "BUY"),
            fetch_price(&http, &cfg, "SELL")
        ) {
            Ok(v) => v,
            Err(e) => {
                failures += 1;
                eprintln!("فشل جلب السعر ({failures}): {e}");
                if failures >= FAILURES_BEFORE_WARNING && !outage_reported {
                    let msg = "⚠️ <b>تنبيه:</b> البوت غير قادر على قراءة أسعار Binance P2P حالياً. راقب السعر يدوياً حتى تعود الخدمة.";
                    if send_telegram(&http, &cfg, msg).await.is_ok() {
                        outage_reported = true;
                    }
                }
                continue;
            }
        };

        if outage_reported {
            let _ = send_telegram(&http, &cfg, "✅ عادت مراقبة الأسعار للعمل.").await;
        }
        failures = 0;
        outage_reported = false;

        println!("شراء={} بيع={}", fmt_money(buy), fmt_money(sell));

        // أول تشغيل: نثبت السعر المرجعي ونرسل رسالة بداية
        let (Some(buy_ref), Some(sell_ref)) = (state.buy_ref, state.sell_ref) else {
            let msg = format!(
                "🤖 <b>بدأت مراقبة {}/{}</b>\n\n🟢 شراء: <b>{}</b> {}\n🔴 بيع: <b>{}</b> {}\n\nسيصلك تنبيه عند تغيّر السعر {}% أو أكثر.",
                cfg.asset,
                cfg.fiat,
                fmt_money(buy),
                cfg.fiat,
                fmt_money(sell),
                cfg.fiat,
                cfg.threshold_percent
            );
            match send_telegram(&http, &cfg, &msg).await {
                Ok(()) => {
                    state.buy_ref = Some(buy);
                    state.sell_ref = Some(sell);
                    state.save(&cfg.state_file);
                }
                Err(e) => eprintln!("فشل إرسال رسالة البداية: {e}"),
            }
            continue;
        };

        let buy_moved = pct(buy, buy_ref).abs() >= cfg.threshold_percent;
        let sell_moved = pct(sell, sell_ref).abs() >= cfg.threshold_percent;
        if !buy_moved && !sell_moved {
            continue;
        }

        // العنوان حسب اتجاه سعر الشراء (أو البيع إن كان هو الذي تحرك)
        let main_pct = if buy_moved { pct(buy, buy_ref) } else { pct(sell, sell_ref) };
        let title = if main_pct > 0.0 {
            format!("🚨📈 <b>ارتفاع سعر {}</b>", cfg.asset)
        } else {
            format!("🚨📉 <b>انخفاض سعر {}</b>", cfg.asset)
        };

        let msg = format!(
            "{title}\n\n{}\n{}\n\n<i>Binance P2P — {}</i>",
            change_line("شراء", buy, buy_ref, &cfg.fiat),
            change_line("بيع", sell, sell_ref, &cfg.fiat),
            cfg.fiat
        );

        match send_telegram(&http, &cfg, &msg).await {
            Ok(()) => {
                // بعد التنبيه يصبح السعر الحالي هو المرجع الجديد
                state.buy_ref = Some(buy);
                state.sell_ref = Some(sell);
                state.save(&cfg.state_file);
            }
            // لا نحدّث المرجع، فيعاد إرسال التنبيه في الدورة القادمة
            Err(e) => eprintln!("فشل إرسال التنبيه: {e}"),
        }
    }
}
