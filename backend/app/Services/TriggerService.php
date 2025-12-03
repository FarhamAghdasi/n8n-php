<?php
namespace App\Services;

use App\Core\Database;
use App\Services\ExecutionService;
use Exception;

class TriggerService {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function runScheduledWorkflows() {
        $workflows = $this->db->select(
            "SELECT * FROM workflows 
             WHERE trigger_type = 'schedule' 
             AND is_active = 1 
             AND schedule_cron IS NOT NULL",
            []
        );

        foreach ($workflows as $workflow) {
            if ($this->shouldRunNow($workflow['schedule_cron'])) {
                try {
                    $executionService = new ExecutionService();
                    $executionService->executeWorkflow(
                        $workflow['id'],
                        [],
                        $workflow['user_id'],
                        'schedule'
                    );

                    error_log("Scheduled workflow {$workflow['id']} executed successfully");

                } catch (Exception $e) {
                    error_log("Scheduled workflow {$workflow['id']} failed: " . $e->getMessage());
                }
            }
        }
    }

    private function shouldRunNow($cronExpression) {
        list($minute, $hour, $day, $month, $weekday) = explode(' ', $cronExpression);

        $now = getdate();

        return $this->cronMatch($minute, $now['minutes']) &&
               $this->cronMatch($hour, $now['hours']) &&
               $this->cronMatch($day, $now['mday']) &&
               $this->cronMatch($month, $now['mon']) &&
               $this->cronMatch($weekday, $now['wday']);
    }

    private function cronMatch($pattern, $value) {
        if ($pattern === '*') return true;
        if ($pattern === (string)$value) return true;

        if (preg_match('/^(\d+)-(\d+)$/', $pattern, $matches)) {
            return $value >= $matches[1] && $value <= $matches[2];
        }

        if (strpos($pattern, ',') !== false) {
            $values = explode(',', $pattern);
            return in_array($value, $values);
        }

        if (preg_match('/^\*\/(\d+)$/', $pattern, $matches)) {
            return $value % $matches[1] === 0;
        }

        return false;
    }
}