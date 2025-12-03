<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;

class AuthController {
    private $authService;
    
    public function __construct() {
        $this->authService = new AuthService();
    }
    
    public function register() {
        $request = new Request();
        $response = new Response();
        
        $data = $request->input();
        
        try {
            // اعتبارسنجی داده‌های ورودی
            if (empty($data['email']) || empty($data['password'])) {
                $response->error('Email and password are required', 400);
            }
            
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $response->error('Invalid email format', 400);
            }
            
            if (strlen($data['password']) < 8) {
                $response->error('Password must be at least 8 characters', 400);
            }
            
            // ثبت نام کاربر
            $result = $this->authService->register(
                $data['email'],
                $data['password'],
                $data['first_name'] ?? null,
                $data['last_name'] ?? null
            );
            
            $response->success([
                'user' => [
                    'id' => $result['user_id'],
                    'email' => $data['email'],
                    'first_name' => $data['first_name'] ?? null,
                    'last_name' => $data['last_name'] ?? null
                ],
                'token' => $result['token']
            ], 'Registration successful');
            
        } catch (\Exception $e) {
            $response->error($e->getMessage(), 400);
        }
    }
    
    public function login() {
        $request = new Request();
        $response = new Response();
        
        $data = $request->input();
        
        try {
            // اعتبارسنجی داده‌های ورودی
            if (empty($data['email']) || empty($data['password'])) {
                $response->error('Email and password are required', 400);
            }
            
            // ورود کاربر
            $result = $this->authService->login($data['email'], $data['password']);
            
            // دریافت اطلاعات کاربر
            $db = \App\Core\Database::getInstance();
            $user = $db->select(
                "SELECT id, email, first_name, last_name FROM users WHERE id = ?",
                [$result['user_id']]
            );
            
            $response->success([
                'user' => $user[0] ?? null,
                'token' => $result['token']
            ], 'Login successful');
            
        } catch (\Exception $e) {
            $response->error($e->getMessage(), 401);
        }
    }
    
    public function logout() {
        $request = new Request();
        $response = new Response();
        
        $authHeader = $request->header('Authorization');
        
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            
            // باطل کردن توکن
            $db = \App\Core\Database::getInstance();
            $db->delete(
                "DELETE FROM tokens WHERE token = ?",
                [$token]
            );
        }
        
        $response->success(null, 'Logout successful');
    }
    
    public function me() {
        $request = new Request();
        $response = new Response();
        
        $authHeader = $request->header('Authorization');
        
        if (!$authHeader) {
            $response->error('Token required', 401);
        }
        
        preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches);
        $token = $matches[1] ?? '';
        
        try {
            $userId = $this->authService->getUserIdFromToken($token);
            
            if (!$userId) {
                $response->error('Invalid token', 401);
            }
            
            $db = \App\Core\Database::getInstance();
            $user = $db->select(
                "SELECT id, email, first_name, last_name, created_at 
                 FROM users WHERE id = ?",
                [$userId]
            );
            
            if (empty($user)) {
                $response->error('User not found', 404);
            }
            
            // دریافت آمار کاربر
            $stats = $this->getUserStats($userId);
            
            $userData = $user[0];
            $userData['stats'] = $stats;
            
            $response->success($userData);
            
        } catch (\Exception $e) {
            $response->error($e->getMessage(), 401);
        }
    }
    
    private function getUserStats($userId) {
        $db = \App\Core\Database::getInstance();
        
        $workflowCount = $db->select(
            "SELECT COUNT(*) as count FROM workflows WHERE user_id = ?",
            [$userId]
        );
        
        $executionCount = $db->select(
            "SELECT COUNT(*) as count FROM executions WHERE user_id = ?",
            [$userId]
        );
        
        $successfulExecutions = $db->select(
            "SELECT COUNT(*) as count FROM executions 
             WHERE user_id = ? AND status = 'completed'",
            [$userId]
        );
        
        return [
            'workflows' => $workflowCount[0]['count'] ?? 0,
            'total_executions' => $executionCount[0]['count'] ?? 0,
            'successful_executions' => $successfulExecutions[0]['count'] ?? 0
        ];
    }
}