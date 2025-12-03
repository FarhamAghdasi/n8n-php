<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Services\WorkflowService;

class WorkflowController {
    private $db;
    private $workflowService;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->workflowService = new WorkflowService();
    }

    public function index() {
        $userId = $this->getUserId();

        $workflows = $this->db->select(
            "SELECT w.*, 
             (SELECT COUNT(*) FROM nodes n WHERE n.workflow_id = w.id) as node_count,
             (SELECT MAX(created_at) FROM executions e WHERE e.workflow_id = w.id) as last_executed
             FROM workflows w 
             WHERE w.user_id = ? 
             ORDER BY w.created_at DESC",
            [$userId]
        );

        (new Response())->success($workflows);
    }

    public function store() {
        $userId = $this->getUserId();
        $request = new Request();

        $data = [
            'user_id' => $userId,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'trigger_type' => $request->input('trigger_type', 'manual'),
            'is_active' => $request->input('is_active', 1),
            'schedule_cron' => $request->input('schedule_cron')
        ];

        $workflowId = $this->workflowService->createWorkflow($data);

        $workflow = $this->db->select(
            "SELECT * FROM workflows WHERE id = ?",
            [$workflowId]
        );

        (new Response())->success($workflow[0] ?? null, 'Workflow created successfully');
    }

    public function show($id) {
        $userId = $this->getUserId();

        $workflow = $this->db->select(
            "SELECT w.* FROM workflows w 
             WHERE w.id = ? AND w.user_id = ?",
            [$id, $userId]
        );

        if (empty($workflow)) {
            (new Response())->error('Workflow not found', 404);
        }

        $nodes = $this->db->select(
            "SELECT * FROM nodes WHERE workflow_id = ?",
            [$id]
        );

        $connections = $this->db->select(
            "SELECT * FROM connections WHERE workflow_id = ?",
            [$id]
        );

        $workflow[0]['nodes'] = $nodes;
        $workflow[0]['connections'] = $connections;

        (new Response())->success($workflow[0]);
    }

    public function update($id) {
        $userId = $this->getUserId();
        $request = new Request();

        $this->checkOwnership($id, $userId);

        $data = $request->input();
        unset($data['id'], $data['user_id'], $data['created_at']);

        $this->workflowService->updateWorkflow($id, $data);

        $workflow = $this->db->select(
            "SELECT * FROM workflows WHERE id = ?",
            [$id]
        );

        (new Response())->success($workflow[0] ?? null, 'Workflow updated successfully');
    }

    public function destroy($id) {
        $userId = $this->getUserId();

        $this->checkOwnership($id, $userId);

        $this->db->beginTransaction();

        try {
            $this->db->delete(
                "DELETE FROM connections WHERE workflow_id = ?",
                [$id]
            );

            $this->db->delete(
                "DELETE FROM nodes WHERE workflow_id = ?",
                [$id]
            );

            $this->db->delete(
                "DELETE FROM workflows WHERE id = ?",
                [$id]
            );

            $this->db->commit();

            (new Response())->success(null, 'Workflow deleted successfully');

        } catch (\Exception $e) {
            $this->db->rollback();
            (new Response())->error('Failed to delete workflow: ' . $e->getMessage(), 500);
        }
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

    private function checkOwnership($workflowId, $userId) {
        $workflow = $this->db->select(
            "SELECT id FROM workflows WHERE id = ? AND user_id = ?",
            [$workflowId, $userId]
        );

        if (empty($workflow)) {
            (new Response())->error('Access denied to this workflow', 403);
        }
    }
}