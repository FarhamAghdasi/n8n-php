<?php
namespace App\Core;

class Logger {
    private $logFile;
    private $logLevel;
    
    const DEBUG = 0;
    const INFO = 1;
    const WARNING = 2;
    const ERROR = 3;
    const CRITICAL = 4;
    
    public function __construct($logFile = null, $logLevel = self::INFO) {
        $this->logLevel = $logLevel;
        
        if ($logFile === null) {
            $logDir = __DIR__ . '/../../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $this->logFile = $logDir . '/app.log';
        } else {
            $this->logFile = $logFile;
        }
    }
    
    public function debug($message, $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    public function info($message, $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log(self::WARNING, $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    public function critical($message, $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    private function log($level, $message, $context = []) {
        if ($level < $this->logLevel) {
            return;
        }
        
        $levelName = $this->getLevelName($level);
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        $logMessage = "[$timestamp] [$levelName] [$ip] [$method $uri] $message";
        
        if (!empty($context)) {
            $logMessage .= " " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        $logMessage .= PHP_EOL;
        
        error_log($logMessage, 3, $this->logFile);
        
        // برای خطاهای بالا، در error_log استاندارد هم ثبت کن
        if ($level >= self::ERROR) {
            error_log($logMessage);
        }
    }
    
    private function getLevelName($level) {
        $names = [
            self::DEBUG => 'DEBUG',
            self::INFO => 'INFO',
            self::WARNING => 'WARNING',
            self::ERROR => 'ERROR',
            self::CRITICAL => 'CRITICAL'
        ];
        
        return $names[$level] ?? 'UNKNOWN';
    }
    
    public function getLogs($lines = 100) {
        if (!file_exists($this->logFile)) {
            return [];
        }
        
        $file = new \SplFileObject($this->logFile, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        
        $startLine = max(0, $totalLines - $lines);
        $logs = [];
        
        for ($i = $startLine; $i <= $totalLines; $i++) {
            $file->seek($i);
            $line = $file->current();
            if (!empty(trim($line))) {
                $logs[] = $line;
            }
        }
        
        return array_reverse($logs);
    }
    
    public function clear() {
        if (file_exists($this->logFile)) {
            file_put_contents($this->logFile, '');
        }
    }
}