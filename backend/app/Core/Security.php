<?php
namespace App\Core;

class Security {
    
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        return $input;
    }
    
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    public static function validateUrl($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    public static function validateIp($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    public static function generateCsrfToken() {
        if (!isset($_SESSION)) {
            session_start();
        }
        
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateToken();
        }
        
        return $_SESSION['csrf_token'];
    }
    
    public static function verifyCsrfToken($token) {
        if (!isset($_SESSION)) {
            session_start();
        }
        
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    public static function rateLimit($key, $limit, $window) {
        $cacheKey = "rate_limit_$key";
        
        if (!isset($_SESSION[$cacheKey])) {
            $_SESSION[$cacheKey] = [
                'count' => 1,
                'timestamp' => time()
            ];
            return true;
        }
        
        $data = $_SESSION[$cacheKey];
        
        if (time() - $data['timestamp'] > $window) {
            $_SESSION[$cacheKey] = [
                'count' => 1,
                'timestamp' => time()
            ];
            return true;
        }
        
        if ($data['count'] >= $limit) {
            return false;
        }
        
        $data['count']++;
        $_SESSION[$cacheKey] = $data;
        
        return true;
    }
    
    public static function escapeSql($value, $connection = null) {
        if ($connection instanceof \mysqli) {
            return $connection->real_escape_string($value);
        }
        
        return addslashes($value);
    }
    
    public static function sanitizeFileName($filename) {
        $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '', $filename);
        $filename = preg_replace('/\.{2,}/', '.', $filename);
        return basename($filename);
    }
    
    public static function getClientIp() {
        $ip = $_SERVER['HTTP_CLIENT_IP'] ?? 
              $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_X_FORWARDED'] ?? 
              $_SERVER['HTTP_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_FORWARDED'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        return self::validateIp($ip) ? $ip : '0.0.0.0';
    }
}