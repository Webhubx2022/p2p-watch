<?php
// انسخ الملف باسم config.php واملأ القيم
return [
    // توكن البوت من @BotFather
    'bot_token' => '123456:ABC...',
    // معرف القناة: @channel_username أو الرقم مثل -1001234567890
    'chat_id' => '@my_channel',

    'fiat' => 'SDG',
    'asset' => 'USDT',

    // نسبة التغير التي يرسل عندها التنبيه (%)
    'threshold_percent' => 1.0,
    // السعر = وسيط أفضل top_n إعلان
    'top_n' => 5,
    // تجاهل المعلنين الذين طلباتهم الشهرية أقل من هذا
    'min_orders' => 10,
    // طرق الدفع (اختياري). اتركها فارغة لكل الطرق، مثال: ['BankofKhartoum']
    'pay_types' => [],
];
