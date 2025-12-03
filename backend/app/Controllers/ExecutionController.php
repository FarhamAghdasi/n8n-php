<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\ExecutionService;

class ExecutionController {
    private $db;
    private $executionService;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->executionService = new ExecutionService();
    }
    
    public function run($workflowId) {
        $userId = $this->getUserId();
        $request = new Request();
        
        // بررسی مالکیت workflow
        $this->checkWorkflowOwnership($workflowId, $userId);
        
        $data = $request->input();
        $inputData = $data['input_data'] ?? [];
        $async = $data['async'] ?? false;
        
        try {
            if ($async) {
                // اجرای غیرهمزمان (آینده می‌توانید پیاده‌سازی کنید)
                $executionId = $this->queueExecution($workflowId, $userId, $inputData);
                
                $response = [
                    'execution_id' => $executionId,
                    'status' => 'queued',
                    'message' => 'Execution has been queued'
                ];
            } else {
                // اجرای همزمان
                $result = $this->executionService->executeWorkflow(
                    $workflowId, 
                    $inputData, 
                    $userId
                );
                
                $response = [
                    'execution_id' => $result['execution_id'],
                    'status' => $result['status'],
                    'execution_time' => $result['execution_time'],
                    'webhook_url' => $result['webhook_url']
                ];
            }
            
            (new Response())->success($response, 'Workflow execution started');
            
        } catch (\Exception $e) {
            (new Response())->error('Execution failed: ' . $e->getMessage(), 500);
        }
    }
    
    public function show($executionId) {
        $userId = $this->getUserId();
        
        // بررسی مالکیت execution
        $execution = $this->db->select(
            "SELECT * FROM executions WHERE id = ? AND user_id = ?",
            [$executionId, $userId]
        );
        
        if (empty($execution)) {
            (new Response())->error('Execution not found or access denied', 404);
        }
        
        // دریافت اطلاعات کامل execution
        $executionData = $this->executionService->getExecutionStatus($executionId);
        
        (new Response())->success($executionData);
    }
    
    public function logs($executionId) {
        $userId = $this->getUserId();
        
        // بررسی مالکیت execution
        $execution = $this->db->select(
            "SELECT id FROM executions WHERE id = ? AND user_id = ?",
            [$executionId, $userId]
        );
        
        if (empty($execution)) {
            (new Response())->error('Execution not found or access denied', 404);
        }
        
        $request = new Request();
        $limit = $request->input('limit', 100);
        
        // دریافت لاگ‌ها
        $logs = $this->executionService->getExecutionLogs($executionId, $limit);
        
        (new Response())->success($logs);
    }
    
    public function getExecutions($workflowId = null) {
        $userId = $this->getUserId();
        $request = new Request();
        
        $page = max(1, intval($request->input('page', 1)));
        $perPage = min(100, max(1, intval($request->input('per_page', 20))));
        $offset = ($page - 1) * $perPage;
        
        $where = "e.user_id = ?";
        $params = [$userId];
        
        if ($workflowId) {
            $where .= " AND e.workflow_id = ?";
            $params[] = $workflowId;
            
            // بررسی مالکیت workflow
            $this->checkWorkflowOwnership($workflowId, $userId);
        }
        
        // دریافت اجراها
        $executions = $this->db->select(
            "SELECT e.*, w.name as workflow_name 
             FROM executions e
             INNER JOIN workflows w ON e.workflow_id = w.id
             WHERE {$where}
             ORDER BY e.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );
        
        // تعداد کل
        $total = $this->db->select(
            "SELECT COUNT(*) as count 
             FROM executions e
             WHERE {$where}",
            $params
        );
        
        $totalCount = $total[0]['count'] ?? 0;
        
        (new Response())->success([
            'executions' => $executions,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $perPage)
            ]
        ]);
    }
    
    private function getUserId() {
        $authHeader = (new Request())->header('Authorization');
        
        if (!$authHeader) {
            (new Response())->error('Token required', 401);
        }
        
        preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches);
        $token = $matches[1] ?? '';
        
        $authService = new AuthService();
        return $authService->getUserIdFromToken($token);
    }
    
    private function checkWorkflowOwnership($workflowId, $userId) {
        $workflow = $this->db->select(
            "SELECT id FROM workflows WHERE id = ? AND user_id = ?",
            [$workflowId, $userId]
        );
        
        if (empty($workflow)) {
            (new Response())->error('Access denied to this workflow', 403);
        }
    }
    
    private function queueExecution($workflowId, $userId, $inputData) {
        // پیاده‌سازی ساده صف
        // در نسخه‌های آینده می‌توانید از Redis یا جدول jobs استفاده کنید
        
        $executionId = $this->db->insert(
            "INSERT INTO executions 
             (workflow_id, user_id, trigger_type, status, input_data) 
             VALUES (?, ?, 'manual', 'pending', ?)",
            [$workflowId, $userId, json_encode($inputData)]
        );
        
        // در اینجا می‌توانید سیستم queue واقعی پیاده‌سازی کنید
        // فعلاً فقط رکورد ایجاد می‌شود
        
        return $executionId;
    }
}