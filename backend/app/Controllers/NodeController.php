<?php
namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use App\Nodes\NodeFactory;

class NodeController {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function index($workflowId) {
        $userId = $this->getUserId();
        
        // بررسی مالکیت workflow
        $this->checkWorkflowOwnership($workflowId, $userId);
        
        // دریافت نودهای workflow
        $nodes = $this->db->select(
            "SELECT * FROM nodes WHERE workflow_id = ? ORDER BY position_y, position_x",
            [$workflowId]
        );
        
        // decode کردن config_json برای هر نود
        foreach ($nodes as &$node) {
            if (!empty($node['config_json'])) {
                $node['config'] = json_decode($node['config_json'], true);
                unset($node['config_json']);
            }
        }
        
        (new Response())->success($nodes);
    }
    
    public function store($workflowId) {
        $userId = $this->getUserId();
        $request = new Request();
        
        // بررسی مالکیت workflow
        $this->checkWorkflowOwnership($workflowId, $userId);
        
        $data = $request->input();
        
        // اعتبارسنجی داده‌ها
        $this->validateNodeData($data);
        
        // ایجاد نود
        $nodeId = $this->db->insert(
            "INSERT INTO nodes (workflow_id, type, name, position_x, position_y, config_json) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $workflowId,
                $data['type'],
                $data['name'] ?? $this->getDefaultNodeName($data['type']),
                $data['position_x'] ?? 0,
                $data['position_y'] ?? 0,
                json_encode($data['config'] ?? [], JSON_UNESCAPED_UNICODE)
            ]
        );
        
        // دریافت نود ایجاد شده
        $node = $this->db->select(
            "SELECT * FROM nodes WHERE id = ?",
            [$nodeId]
        );
        
        if (!empty($node)) {
            $node = $node[0];
            $node['config'] = json_decode($node['config_json'], true);
            unset($node['config_json']);
        }
        
        (new Response())->success($node, 'Node created successfully');
    }
    
    public function update($nodeId) {
        $userId = $this->getUserId();
        $request = new Request();
        
        // بررسی مالکیت نود
        $this->checkNodeOwnership($nodeId, $userId);
        
        $data = $request->input();
        
        // اعتبارسنجی داده‌ها
        if (isset($data['type'])) {
            $this->validateNodeType($data['type']);
        }
        
        // ساخت query پویا
        $fields = [];
        $params = [];
        
        $allowedFields = ['type', 'name', 'position_x', 'position_y', 'config'];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                if ($key === 'config') {
                    $fields[] = "config_json = ?";
                    $params[] = json_encode($value, JSON_UNESCAPED_UNICODE);
                } else {
                    $fields[] = "$key = ?";
                    $params[] = $value;
                }
            }
        }
        
        $fields[] = "updated_at = NOW()";
        
        if (empty($fields)) {
            (new Response())->error('No valid fields to update', 400);
        }
        
        $params[] = $nodeId;
        
        $sql = "UPDATE nodes SET " . implode(', ', $fields) . " WHERE id = ?";
        
        $affected = $this->db->update($sql, $params);
        
        if ($affected > 0) {
            $node = $this->db->select(
                "SELECT * FROM nodes WHERE id = ?",
                [$nodeId]
            );
            
            if (!empty($node)) {
                $node = $node[0];
                $node['config'] = json_decode($node['config_json'], true);
                unset($node['config_json']);
            }
            
            (new Response())->success($node, 'Node updated successfully');
        } else {
            (new Response())->error('Failed to update node', 500);
        }
    }
    
    public function destroy($nodeId) {
        $userId = $this->getUserId();
        
        // بررسی مالکیت نود
        $this->checkNodeOwnership($nodeId, $userId);
        
        $this->db->beginTransaction();
        
        try {
            // حذف ارتباطات مرتبط
            $this->db->delete(
                "DELETE FROM connections WHERE from_node_id = ? OR to_node_id = ?",
                [$nodeId, $nodeId]
            );
            
            // حذف نود
            $this->db->delete(
                "DELETE FROM nodes WHERE id = ?",
                [$nodeId]
            );
            
            $this->db->commit();
            
            (new Response())->success(null, 'Node deleted successfully');
            
        } catch (\Exception $e) {
            $this->db->rollback();
            (new Response())->error('Failed to delete node: ' . $e->getMessage(), 500);
        }
    }
    
    public function addConnection($workflowId) {
        $userId = $this->getUserId();
        $request = new Request();
        
        // بررسی مالکیت workflow
        $this->checkWorkflowOwnership($workflowId, $userId);
        
        $data = $request->input();
        
        // اعتبارسنجی داده‌ها
        if (empty($data['from_node_id']) || empty($data['to_node_id'])) {
            (new Response())->error('from_node_id and to_node_id are required', 400);
        }
        
        // بررسی اینکه نودها متعلق به این workflow باشند
        $fromNode = $this->db->select(
            "SELECT id FROM nodes WHERE id = ? AND workflow_id = ?",
            [$data['from_node_id'], $workflowId]
        );
        
        $toNode = $this->db->select(
            "SELECT id FROM nodes WHERE id = ? AND workflow_id = ?",
            [$data['to_node_id'], $workflowId]
        );
        
        if (empty($fromNode) || empty($toNode)) {
            (new Response())->error('Invalid node IDs', 400);
        }
        
        // بررسی اینکه connection تکراری نباشد
        $existing = $this->db->select(
            "SELECT id FROM connections 
             WHERE workflow_id = ? 
             AND from_node_id = ? 
             AND to_node_id = ? 
             AND from_output = ? 
             AND to_input = ?",
            [
                $workflowId,
                $data['from_node_id'],
                $data['to_node_id'],
                $data['from_output'] ?? 'default',
                $data['to_input'] ?? 'default'
            ]
        );
        
        if (!empty($existing)) {
            (new Response())->error('Connection already exists', 400);
        }
        
        // ایجاد connection
        $connectionId = $this->db->insert(
            "INSERT INTO connections 
             (workflow_id, from_node_id, to_node_id, from_output, to_input) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $workflowId,
                $data['from_node_id'],
                $data['to_node_id'],
                $data['from_output'] ?? 'default',
                $data['to_input'] ?? 'default'
            ]
        );
        
        $connection = $this->db->select(
            "SELECT * FROM connections WHERE id = ?",
            [$connectionId]
        );
        
        (new Response())->success($connection[0] ?? null, 'Connection added successfully');
    }
    
    public function removeConnection($connectionId) {
        $userId = $this->getUserId();
        
        // بررسی مالکیت connection
        $connection = $this->db->select(
            "SELECT c.* FROM connections c
             INNER JOIN workflows w ON c.workflow_id = w.id
             WHERE c.id = ? AND w.user_id = ?",
            [$connectionId, $userId]
        );
        
        if (empty($connection)) {
            (new Response())->error('Connection not found or access denied', 404);
        }
        
        $this->db->delete(
            "DELETE FROM connections WHERE id = ?",
            [$connectionId]
        );
        
        (new Response())->success(null, 'Connection removed successfully');
    }
    
    public function getNodeTypes() {
        $nodeTypes = NodeFactory::getNodeTypes();
        
        (new Response())->success($nodeTypes);
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
    
    private function checkNodeOwnership($nodeId, $userId) {
        $node = $this->db->select(
            "SELECT n.id FROM nodes n
             INNER JOIN workflows w ON n.workflow_id = w.id
             WHERE n.id = ? AND w.user_id = ?",
            [$nodeId, $userId]
        );
        
        if (empty($node)) {
            (new Response())->error('Access denied to this node', 403);
        }
    }
    
    private function validateNodeData($data) {
        if (empty($data['type'])) {
            (new Response())->error('Node type is required', 400);
        }
        
        $this->validateNodeType($data['type']);
        
        if (isset($data['config']) && !is_array($data['config'])) {
            (new Response())->error('Config must be an array', 400);
        }
    }
    
    private function validateNodeType($type) {
        $nodeTypes = NodeFactory::getNodeTypes();
        
        if (!isset($nodeTypes[$type])) {
            (new Response())->error('Invalid node type', 400);
        }
    }
    
    private function getDefaultNodeName($type) {
        $nodeTypes = NodeFactory::getNodeTypes();
        return $nodeTypes[$type]['name'] ?? 'Untitled Node';
    }
}