<?php
namespace App\Services;

use App\Core\Database;
use App\Nodes\NodeFactory;

class ExecutionService {
    private $db;
    private $nodeFactory;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->nodeFactory = new NodeFactory();
    }
    
    public function executeWorkflow($workflowId, $inputData = [], $userId = null, $triggerType = 'manual') {
        $startTime = microtime(true);
        
        // دریافت workflow
        $workflow = $this->db->select(
            "SELECT * FROM workflows WHERE id = ?",
            [$workflowId]
        );
        
        if (empty($workflow)) {
            throw new \Exception('Workflow not found');
        }
        
        $workflow = $workflow[0];
        
        // بررسی مالکیت
        if ($userId && $workflow['user_id'] != $userId) {
            throw new \Exception('Access denied to this workflow');
        }
        
        // بررسی اینکه workflow فعال باشد
        if (!$workflow['is_active']) {
            throw new \Exception('Workflow is not active');
        }
        
        // ایجاد رکورد اجرا
        $executionId = $this->db->insert(
            "INSERT INTO executions (workflow_id, user_id, trigger_type, status, input_data, started_at) 
             VALUES (?, ?, ?, 'running', ?, NOW())",
            [
                $workflowId, 
                $userId ?? $workflow['user_id'], 
                $triggerType, 
                json_encode($inputData, JSON_UNESCAPED_UNICODE)
            ]
        );
        
        try {
            // دریافت نودها و ارتباطات
            $nodes = $this->db->select(
                "SELECT * FROM nodes WHERE workflow_id = ? ORDER BY id",
                [$workflowId]
            );
            
            $connections = $this->db->select(
                "SELECT * FROM connections WHERE workflow_id = ?",
                [$workflowId]
            );
            
            // بررسی اینکه workflow نود داشته باشد
            if (empty($nodes)) {
                throw new \Exception('Workflow has no nodes');
            }
            
            // ساخت گراف
            $graph = $this->buildGraph($nodes, $connections);
            
            // پیدا کردن نودهای شروع (بدون ورودی)
            $startNodes = $this->findStartNodes($graph);
            
            // اگر هیچ نود شروع‌ای وجود نداشته باشد
            if (empty($startNodes)) {
                // همه نودها را به عنوان شروع در نظر بگیر (برای workflowهای دایره‌ای)
                $startNodes = array_keys($graph);
            }
            
            // اجرای BFS
            $executionResult = $this->executeBFS($graph, $startNodes, $inputData, $executionId);
            
            // ثبت اتمام اجرا
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->db->update(
                "UPDATE executions 
                 SET status = 'completed', 
                     ended_at = NOW(),
                     execution_time_ms = ?,
                     output_data = ?
                 WHERE id = ?",
                [
                    $executionTime, 
                    json_encode($executionResult, JSON_UNESCAPED_UNICODE), 
                    $executionId
                ]
            );
            
            // به‌روزرسانی آخرین زمان اجرای workflow
            $this->db->update(
                "UPDATE workflows SET last_executed = NOW() WHERE id = ?",
                [$workflowId]
            );
            
            return [
                'execution_id' => $executionId,
                'status' => 'completed',
                'execution_time' => $executionTime,
                'result' => $executionResult,
                'webhook_url' => $this->getWebhookUrl($workflowId)
            ];
            
        } catch (\Exception $e) {
            // ثبت خطا
            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->db->update(
                "UPDATE executions 
                 SET status = 'failed', 
                     ended_at = NOW(),
                     execution_time_ms = ?,
                     error_message = ?
                 WHERE id = ?",
                [$executionTime, $e->getMessage(), $executionId]
            );
            
            // ثبت لاگ خطا
            $this->logExecution($executionId, null, 'error', $e->getMessage());
            
            throw $e;
        }
    }
    
    private function buildGraph($nodes, $connections) {
        $graph = [];
        
        foreach ($nodes as $node) {
            $graph[$node['id']] = [
                'node' => $node,
                'incoming' => [],
                'outgoing' => [],
                'visited' => false,
                'result' => null,
                'executed' => false
            ];
        }
        
        foreach ($connections as $connection) {
            $fromId = $connection['from_node_id'];
            $toId = $connection['to_node_id'];
            
            if (isset($graph[$fromId]) && isset($graph[$toId])) {
                $graph[$fromId]['outgoing'][] = [
                    'node_id' => $toId,
                    'from_output' => $connection['from_output'],
                    'to_input' => $connection['to_input']
                ];
                $graph[$toId]['incoming'][] = [
                    'node_id' => $fromId,
                    'from_output' => $connection['from_output'],
                    'to_input' => $connection['to_input']
                ];
            }
        }
        
        return $graph;
    }
    
    private function findStartNodes($graph) {
        $startNodes = [];
        
        foreach ($graph as $nodeId => $nodeData) {
            if (empty($nodeData['incoming'])) {
                $startNodes[] = $nodeId;
            }
        }
        
        return $startNodes;
    }
    
    private function executeBFS($graph, $startNodes, $inputData, $executionId) {
        $queue = $startNodes;
        $executedNodes = [];
        $finalResult = [];
        
        while (!empty($queue)) {
            $nodeId = array_shift($queue);
            
            if ($graph[$nodeId]['executed']) {
                continue;
            }
            
            // بررسی اینکه آیا همه نودهای ورودی اجرا شده‌اند
            $allInputsReady = true;
            $nodeInputs = [];
            
            foreach ($graph[$nodeId]['incoming'] as $connection) {
                $inputNodeId = $connection['node_id'];
                
                if (!$graph[$inputNodeId]['executed']) {
                    $allInputsReady = false;
                    break;
                }
                
                // جمع‌آوری داده‌های ورودی از نودهای قبلی
                if ($graph[$inputNodeId]['result']) {
                    $nodeInputs = array_merge_recursive(
                        $nodeInputs, 
                        $graph[$inputNodeId]['result']
                    );
                }
            }
            
            if (!$allInputsReady) {
                // اگر نودهای ورودی هنوز اجرا نشده‌اند، به انتهای صف برگردان
                $queue[] = $nodeId;
                continue;
            }
            
            // برای نودهای شروع، داده اولیه را اضافه کن
            if (empty($graph[$nodeId]['incoming']) && !empty($inputData)) {
                $nodeInputs['initial'] = $inputData;
            }
            
            // اجرای نود
            try {
                $result = $this->executeNode($graph[$nodeId]['node'], $nodeInputs, $executionId);
                $graph[$nodeId]['result'] = $result;
                $graph[$nodeId]['executed'] = true;
                $executedNodes[] = $nodeId;
                
                // ثبت لاگ موفقیت
                $this->logExecution(
                    $executionId, 
                    $nodeId, 
                    'info', 
                    "Node executed successfully"
                );
                
                // اضافه کردن نودهای خروجی به صف
                foreach ($graph[$nodeId]['outgoing'] as $connection) {
                    $outputNodeId = $connection['node_id'];
                    if (!in_array($outputNodeId, $queue) && !$graph[$outputNodeId]['executed']) {
                        $queue[] = $outputNodeId;
                    }
                }
                
                // ذخیره نتیجه نهایی اگر نود خروجی ندارد
                if (empty($graph[$nodeId]['outgoing'])) {
                    $finalResult[] = [
                        'node_id' => $nodeId,
                        'node_name' => $graph[$nodeId]['node']['name'],
                        'result' => $result
                    ];
                }
                
            } catch (\Exception $e) {
                // خطا در اجرای نود
                $graph[$nodeId]['executed'] = true;
                $graph[$nodeId]['result'] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                
                $this->logExecution(
                    $executionId, 
                    $nodeId, 
                    'error', 
                    "Node execution failed: " . $e->getMessage()
                );
                
                // ادامه اجرای workflow (مگر اینکه خطا بحرانی باشد)
                // می‌توانید این بخش را بر اساس نیاز تغییر دهید
            }
            
            // جلوگیری از حلقه بی‌نهایت
            if (count($executedNodes) > 100) {
                throw new \Exception('Maximum node execution limit reached (possible infinite loop)');
            }
        }
        
        return [
            'total_nodes' => count($graph),
            'executed_nodes' => count($executedNodes),
            'results' => $finalResult
        ];
    }
    
    private function executeNode($nodeData, $inputData, $executionId) {
        $startTime = microtime(true);
        
        // ایجاد نمونه نود
        $config = json_decode($nodeData['config_json'], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid node configuration JSON');
        }
        
        $node = $this->nodeFactory->create($nodeData['type'], $config);
        
        // اعتبارسنجی نود
        $node->validate();
        
        // اجرای نود
        $result = $node->execute($inputData);
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        
        // اضافه کردن metadata به نتیجه
        $result['_metadata'] = [
            'node_id' => $nodeData['id'],
            'node_name' => $nodeData['name'],
            'execution_time_ms' => $executionTime,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return $result;
    }
    
    private function logExecution($executionId, $nodeId, $type, $message, $data = null) {
        $this->db->insert(
            "INSERT INTO execution_logs (execution_id, node_id, log_type, message, data, timestamp) 
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $executionId, 
                $nodeId, 
                $type, 
                $message, 
                $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null
            ]
        );
    }
    
    public function getExecutionLogs($executionId, $limit = 100) {
        return $this->db->select(
            "SELECT el.*, n.name as node_name 
             FROM execution_logs el
             LEFT JOIN nodes n ON el.node_id = n.id
             WHERE el.execution_id = ? 
             ORDER BY el.timestamp ASC 
             LIMIT ?",
            [$executionId, $limit]
        );
    }
    
    public function getExecutionStatus($executionId) {
        $execution = $this->db->select(
            "SELECT * FROM executions WHERE id = ?",
            [$executionId]
        );
        
        if (empty($execution)) {
            return null;
        }
        
        $execution = $execution[0];
        
        // دریافت لاگ‌ها
        $logs = $this->getExecutionLogs($executionId, 50);
        
        return [
            'execution' => $execution,
            'logs' => $logs,
            'progress' => $this->calculateProgress($executionId)
        ];
    }
    
    private function calculateProgress($executionId) {
        $logs = $this->db->select(
            "SELECT COUNT(*) as total_logs FROM execution_logs WHERE execution_id = ?",
            [$executionId]
        );
        
        $nodeLogs = $this->db->select(
            "SELECT COUNT(DISTINCT node_id) as executed_nodes 
             FROM execution_logs 
             WHERE execution_id = ? AND node_id IS NOT NULL",
            [$executionId]
        );
        
        $totalNodes = $this->db->select(
            "SELECT COUNT(*) as total_nodes 
             FROM nodes n
             INNER JOIN executions e ON n.workflow_id = e.workflow_id
             WHERE e.id = ?",
            [$executionId]
        );
        
        $totalNodes = $totalNodes[0]['total_nodes'] ?? 0;
        $executedNodes = $nodeLogs[0]['executed_nodes'] ?? 0;
        
        if ($totalNodes > 0) {
            $percentage = ($executedNodes / $totalNodes) * 100;
        } else {
            $percentage = 0;
        }
        
        return [
            'executed_nodes' => $executedNodes,
            'total_nodes' => $totalNodes,
            'percentage' => round($percentage, 2)
        ];
    }
    
    private function getWebhookUrl($workflowId) {
        $workflow = $this->db->select(
            "SELECT public_id FROM workflows WHERE id = ?",
            [$workflowId]
        );
        
        if (empty($workflow)) {
            return null;
        }
        
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = rtrim($host, '/');
        $publicId = $workflow[0]['public_id'];
        
        return "{$protocol}://{$baseUrl}/api/webhook/{$publicId}";
    }
}