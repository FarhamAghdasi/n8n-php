<?php
// تنظیمات اپلیکیشن
define('APP_NAME', 'PHP Automation');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'production');

// تنظیمات JWT-like Token
define('TOKEN_SECRET', 'your-secret-key-change-this');
define('TOKEN_EXPIRY', 86400); // 24 ساعت

// تنظیمات امنیتی
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 دقیقه
define('RATE_LIMIT_REQUESTS', 100); // 100 درخواست در ساعت
define('RATE_LIMIT_WINDOW', 3600);

// تنظیمات اجرای workflow
define('MAX_EXECUTION_TIME', 300); // 5 دقیقه
define('MAX_NODES_PER_WORKFLOW', 100);
define('MAX_LOG_SIZE', 10000); // حداکثر تعداد لاگ‌ها

// Allowlist توابع برای FunctionNode
$allowedFunctions = [
    'strlen', 'strtoupper', 'strtolower', 'trim', 'ltrim', 'rtrim',
    'substr', 'str_replace', 'strpos', 'stripos', 'explode', 'implode',
    'json_encode', 'json_decode', 'urlencode', 'urldecode',
    'base64_encode', 'base64_decode', 'md5', 'sha1', 'time', 'date',
    'strtotime', 'intval', 'floatval', 'boolval', 'is_array',
    'is_string', 'is_numeric', 'count', 'array_merge', 'array_keys',
    'array_values', 'in_array'
];

// تنظیمات ایمیل
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('SMTP_SECURE', 'tls');