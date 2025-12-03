<?php

// Application constants
define('APP_NAME', 'PHP Automation');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'production');

// Token constants
define('TOKEN_SECRET', 'your-secret-key-change-this-in-production');
define('TOKEN_EXPIRY', 86400); // 24 hours

// Security constants
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 3600);

// Execution constants
define('MAX_EXECUTION_TIME', 300); // 5 minutes
define('MAX_NODES_PER_WORKFLOW', 100);
define('MAX_LOG_SIZE', 10000);

// Email constants
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', '');
define('SMTP_PASSWORD', '');
define('SMTP_SECURE', 'tls');

// Path constants
define('ROOT_PATH', dirname(__DIR__, 2));
define('APP_PATH', ROOT_PATH . '/app');
define('PUBLIC_PATH', ROOT_PATH . '/public');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Error reporting
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}