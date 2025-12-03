<?php
namespace App\Core;

class Request {
    
    public function method() {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    
    public function path() {
        $path = $_GET['url'] ?? '/';
        $path = trim($path, '/');
        return $path ?: '/';
    }
    
    public function header($name) {
        $name = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$name] ?? null;
    }
    
    public function input($key = null, $default = null) {
        $data = array_merge($_GET, $_POST);
        
        // دریافت JSON input
        $input = file_get_contents('php://input');
        if ($input) {
            $jsonData = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = array_merge($data, $jsonData);
            }
        }
        
        if ($key === null) {
            return $data;
        }
        
        return $data[$key] ?? $default;
    }
    
    public function ip() {
        $ip = $_SERVER['HTTP_CLIENT_IP'] ?? 
              $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? '';
        
        // Clean IP address
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        
        return '0.0.0.0';
    }
    
    public function userAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    public function isJson() {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return strpos($contentType, 'application/json') !== false;
    }
    
    public function files() {
        return $_FILES ?? [];
    }
    
    public function cookie($name, $default = null) {
        return $_COOKIE[$name] ?? $default;
    }
    
    public function all() {
        return $this->input();
    }
    
    public function only($keys) {
        $data = $this->input();
        $result = [];
        
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }
        
        foreach ($keys as $key) {
            $key = trim($key);
            if (isset($data[$key])) {
                $result[$key] = $data[$key];
            }
        }
        
        return $result;
    }
    
    public function except($keys) {
        $data = $this->input();
        
        if (is_string($keys)) {
            $keys = explode(',', $keys);
        }
        
        foreach ($keys as $key) {
            $key = trim($key);
            unset($data[$key]);
        }
        
        return $data;
    }
    
    public function has($key) {
        $data = $this->input();
        return isset($data[$key]);
    }
}