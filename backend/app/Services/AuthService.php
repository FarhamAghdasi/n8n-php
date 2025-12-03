<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Security;

class AuthService {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function register($email, $password, $firstName = null, $lastName = null) {
        // اعتبارسنجی ایمیل
        if (!Security::validateEmail($email)) {
            throw new \Exception('Invalid email format');
        }
        
        // بررسی وجود ایمیل
        $existing = $this->db->select(
            "SELECT id FROM users WHERE email = ?",
            [$email]
        );
        
        if (!empty($existing)) {
            throw new \Exception('Email already registered');
        }
        
        // هش کردن رمز عبور
        $passwordHash = Security::hashPassword($password);
        
        // ایجاد کاربر
        $userId = $this->db->insert(
            "INSERT INTO users (email, password_hash, first_name, last_name) 
             VALUES (?, ?, ?, ?)",
            [$email, $passwordHash, $firstName, $lastName]
        );
        
        // ایجاد توکن
        $token = $this->generateToken($userId);
        
        return [
            'user_id' => $userId,
            'token' => $token
        ];
    }
    
    public function login($email, $password) {
        // دریافت کاربر
        $user = $this->db->select(
            "SELECT id, password_hash, is_active FROM users WHERE email = ?",
            [$email]
        );
        
        if (empty($user)) {
            throw new \Exception('Invalid credentials');
        }
        
        $user = $user[0];
        
        // بررسی فعال بودن کاربر
        if (!$user['is_active']) {
            throw new \Exception('Account is deactivated');
        }
        
        // بررسی رمز عبور
        if (!Security::verifyPassword($password, $user['password_hash'])) {
            // افزایش تلاش‌های ناموفق
            $this->logFailedAttempt($user['id']);
            throw new \Exception('Invalid credentials');
        }
        
        // ایجاد توکن جدید
        $token = $this->generateToken($user['id']);
        
        // پاک کردن تلاش‌های ناموفق
        $this->clearFailedAttempts($user['id']);
        
        return [
            'user_id' => $user['id'],
            'token' => $token
        ];
    }
    
    public function generateToken($userId) {
        // ایجاد توکن JWT-like
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + TOKEN_EXPIRY
        ]);
        
        $base64Header = $this->base64UrlEncode($header);
        $base64Payload = $this->base64UrlEncode($payload);
        
        $signature = hash_hmac(
            'sha256',
            $base64Header . "." . $base64Payload,
            TOKEN_SECRET,
            true
        );
        
        $base64Signature = $this->base64UrlEncode($signature);
        
        $token = $base64Header . "." . $base64Payload . "." . $base64Signature;
        
        // ذخیره توکن در دیتابیس
        $this->db->insert(
            "INSERT INTO tokens (user_id, token, expires_at) 
             VALUES (?, ?, FROM_UNIXTIME(?))",
            [$userId, $token, time() + TOKEN_EXPIRY]
        );
        
        return $token;
    }
    
    public function validateToken($token) {
        // بررسی ساختار توکن
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        // بررسی در دیتابیس
        $tokenData = $this->db->select(
            "SELECT user_id, expires_at FROM tokens 
             WHERE token = ? AND expires_at > NOW()",
            [$token]
        );
        
        if (empty($tokenData)) {
            return false;
        }
        
        // بررسی امضا
        list($header, $payload, $signature) = $parts;
        
        $validSignature = $this->base64UrlEncode(
            hash_hmac('sha256', $header . "." . $payload, TOKEN_SECRET, true)
        );
        
        return hash_equals($signature, $validSignature);
    }
    
    public function getUserIdFromToken($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        return $payload['user_id'] ?? null;
    }
    
    private function base64UrlEncode($data) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
    
    private function base64UrlDecode($data) {
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $mod4 = strlen($data) % 4;
        
        if ($mod4) {
            $data .= substr('====', $mod4);
        }
        
        return base64_decode($data);
    }
    
    private function logFailedAttempt($userId) {
        // ثبت تلاش ناموفق
        error_log("Failed login attempt for user: $userId");
    }
    
    private function clearFailedAttempts($userId) {
        // پاک کردن لاگ تلاش‌های ناموفق
    }
    
    public function logout($token) {
        $this->db->delete(
            "DELETE FROM tokens WHERE token = ?",
            [$token]
        );
    }
    
    public function getUserById($userId) {
        return $this->db->selectOne(
            "SELECT id, email, first_name, last_name, created_at 
             FROM users WHERE id = ?",
            [$userId]
        );
    }
}