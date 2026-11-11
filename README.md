# p2p-watch

بوت بلغة Rust يراقب سعر USDT/SDG في Binance P2P ويرسل تنبيه لقناة تلجرام عند ارتفاع أو انخفاض السعر.

## الإعداد

1. أنشئ بوت من [@BotFather](https://t.me/BotFather) وخذ التوكن.
2. أضف البوت **مشرف (Admin)** في القناة مع صلاحية نشر الرسائل.
3. انسخ `.env.example` إلى `.env` واملأ `TELEGRAM_BOT_TOKEN` و `TELEGRAM_CHAT_ID`.

## التشغيل

```bash
cargo build --release
./target/release/p2p-watch
```

## تشغيل دائم على سيرفر (systemd)

`/etc/systemd/system/p2p-watch.service`:

```ini
[Unit]
Description=Binance P2P price watcher
After=network-online.target

[Service]
WorkingDirectory=/opt/p2p-watch
ExecStart=/opt/p2p-watch/p2p-watch
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now p2p-watch
```

## كيف يعمل

- كل `POLL_SECONDS` يجلب إعلانات الشراء والبيع، ويحسب وسيط أفضل `TOP_N` إعلان (حتى لا يتأثر بإعلان مروَّج أو شاذ).
- يقارن السعر بآخر سعر أُرسل عنه تنبيه. إذا الفرق `THRESHOLD_PERCENT`% أو أكثر يرسل تنبيه ويعتمد السعر الجديد كمرجع.
- المرجع محفوظ في `state.json` فلا يضيع عند إعادة التشغيل.
- إذا فشل الاتصال بـ Binance 10 مرات متتالية يرسل تحذير للقناة.
