<?php
// فایل cron ساده برای اجرای workflowهای زمان‌بندی شده

use App\Core\Database;
use App\Services\TriggerService;

session_start();

define('ROOT_PATH', dirname(__DIR__));
define('APP_PATH', ROOT_PATH . '/app');
define('CONFIG_PATH', ROOT_PATH . '/config');

require_once APP_PATH . '/Core/Autoloader.php';
require_once CONFIG_PATH . '/settings.php';

$db = new Database();
$triggerService = new TriggerService($db);

// اجرای workflowهای زمان‌بندی شده
$triggerService->runScheduledWorkflows();

echo "Cron executed at " . date('Y-m-d H:i:s');