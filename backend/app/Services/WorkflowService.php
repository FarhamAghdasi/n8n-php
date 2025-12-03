<?php
namespace App\Services;

use App\Core\Database;

class WorkflowService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function createWorkflow($data) {
        // اعتبارسنجی داده‌ها
        $this->validateWorkflowData($data);
        
        // ایجاد public_id منحصربه‌فرد
        $publicId = $this->generatePublicId();
        
        // درج workflow
        $workflowId = $this->db->insert(
            "INSERT INTO workflows 
             (user_id, name, description, public_id, trigger_type, is_active, schedule_cron) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $data['user_id'],
                $data['name'],
                $data['description'] ?? '',
                $publicId,
                $data['trigger_type'] ?? 'manual',
                $data['is_active'] ?? 1,
                $data['schedule_cron'] ?? null
            ]
        );
        
        // ایجاد webhook entry اگر trigger_type برابر webhook باشد
        if (($data['trigger_type'] ?? 'manual') === 'webhook') {
            $this->createWebhookForWorkflow($workflowId);
        }
        
        return $workflowId;
    }
    
    public function updateWorkflow($workflowId, $data) {
        // اعتبارسنجی داده‌ها
        $this->validateWorkflowData($data, true);
        
        // ساخت query پویا
        $fields = [];
        $params = [];
        
        foreach ($data as $key => $value) {
            if (in_array($key, ['name', 'description', 'trigger_type', 'is_active', 'schedule_cron'])) {
                $fields[] = "$key = ?";
                $params[] = $value;
            }
        }
        
        $fields[] = "updated_at = NOW()";
        
        if (empty($fields)) {
            throw new \Exception('No valid fields to update');
        }
        
        $params[] = $workflowId;
        
        $sql = "UPDATE workflows SET " . implode(', ', $fields) . " WHERE id = ?";
        
        return $this->db->update($sql, $params);
    }
    
    public function deleteWorkflow($workflowId) {
        // حذف workflow و تمام داده‌های مرتبط
        $this->db->beginTransaction();
        
        try {
            // حذف لاگ‌های اجرا
            $this->db->delete(
                "DELETE el FROM execution_logs el
                 INNER JOIN executions e ON e.id = el.execution_id
                 WHERE e.workflow_id = ?",
                [$workflowId]
            );
            
            // حذف اجراها
            $this->db->delete(
                "DELETE FROM executions WHERE workflow_id = ?",
                [$workflowId]
            );
            
            // حذف webhook
            $this->db->delete(
                "DELETE FROM webhooks WHERE workflow_id = ?",
                [$workflowId]
            );
            
            // حذف ارتباطات
            $this->db->delete(
                "DELETE FROM connections WHERE workflow_id = ?",
                [$workflowId]
            );
            
            // حذف نودها
            $this->db->delete(
                "DELETE FROM nodes WHERE workflow_id = ?",
                [$workflowId]
            );
            
            // حذف workflow
            $this->db->delete(
                "DELETE FROM workflows WHERE id = ?",
                [$workflowId]
            );
            
            $this->db->commit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
    
    public function getWorkflowGraph($workflowId) {
        $workflow = $this->db->select(
            "SELECT * FROM workflows WHERE id = ?",
            [$workflowId]
        );
        
        if (empty($workflow)) {
            throw new \Exception('Workflow not found');
        }
        
        $workflow = $workflow[0];
        
        // دریافت نودها
        $nodes = $this->db->select(
            "SELECT * FROM nodes WHERE workflow_id = ?",
            [$workflowId]
        );
        
        // دریافت ارتباطات
        $connections = $this->db->select(
            "SELECT * FROM connections WHERE workflow_id = ?",
            [$workflowId]
        );
        
        // ساخت گراف برای نمایش
        $graph = [
            'workflow' => $workflow,
            'nodes' => [],
            'edges' => []
        ];
        
        foreach ($nodes as $node) {
            $graph['nodes'][] = [
                'id' => $node['id'],
                'type' => $node['type'],
                'name' => $node['name'],
                'position' => [
                    'x' => $node['position_x'],
                    'y' => $node['position_y']
                ],
                'data' => [
                    'config' => json_decode($node['config_json'], true)
                ]
            ];
        }
        
        foreach ($connections as $connection) {
            $graph['edges'][] = [
                'id' => $connection['id'],
                'source' => $connection['from_node_id'],
                'target' => $connection['to_node_id'],
                'sourceHandle' => $connection['from_output'],
                'targetHandle' => $connection['to_input']
            ];
        }
        
        return $graph;
    }
    
    public function validateWorkflowData($data, $isUpdate = false) {
        if (!$isUpdate) {
            // برای ایجاد جدید
            if (empty($data['user_id'])) {
                throw new \Exception('User ID is required');
            }
        }
        
        if (isset($data['name'])) {
            if (empty(trim($data['name']))) {
                throw new \Exception('Workflow name is required');
            }
            
            if (strlen($data['name']) > 255) {
                throw new \Exception('Workflow name is too long');
            }
        }
        
        if (isset($data['trigger_type'])) {
            $validTriggers = ['manual', 'webhook', 'schedule'];
            if (!in_array($data['trigger_type'], $validTriggers)) {
                throw new \Exception('Invalid trigger type');
            }
        }
        
        if (isset($data['schedule_cron']) && !empty($data['schedule_cron'])) {
            if (!$this->validateCronExpression($data['schedule_cron'])) {
                throw new \Exception('Invalid cron expression');
            }
        }
        
        return true;
    }
    
    private function validateCronExpression($expression) {
        // اعتبارسنجی ساده cron expression
        $parts = explode(' ', $expression);
        
        if (count($parts) !== 5) {
            return false;
        }
        
        // بررسی فرمت
        foreach ($parts as $part) {
            if (!preg_match('/^(\*|\d+(-\d+)?(,\d+(-\d+)?)*|\*\/(\d+))$/', $part)) {
                return false;
            }
        }
        
        return true;
    }
    
    private function generatePublicId() {
        $prefix = 'wf_';
        $unique = substr(md5(uniqid(mt_rand(), true)), 0, 10);
        $timestamp = time();
        
        return $prefix . $unique . '_' . $timestamp;
    }
    
    private function createWebhookForWorkflow($workflowId) {
        $webhookKey = 'wh_' . bin2hex(random_bytes(16));
        
        $this->db->insert(
            "INSERT INTO webhooks (workflow_id, webhook_key) VALUES (?, ?)",
            [$workflowId, $webhookKey]
        );
        
        return $webhookKey;
    }
    
    public function getWebhookUrl($workflowId) {
        $workflow = $this->db->select(
            "SELECT public_id FROM workflows WHERE id = ?",
            [$workflowId]
        );
        
        if (empty($workflow)) {
            return null;
        }
        
        $baseUrl = rtrim($_SERVER['HTTP_HOST'] ?? 'localhost', '/');
        $publicId = $workflow[0]['public_id'];
        
        return "https://{$baseUrl}/api/webhook/{$publicId}";
    }
}