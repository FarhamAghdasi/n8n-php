<?php
namespace App\Core;

class Autoloader {
    
    public static function register() {
        spl_autoload_register(function ($className) {
            $prefix = 'App\\';
            
            $len = strlen($prefix);
            if (strncmp($prefix, $className, $len) !== 0) {
                return;
            }
            
            $relativeClass = substr($className, $len);
            $file = __DIR__ . '/../' . str_replace('\\', '/', $relativeClass) . '.php';
            
            if (file_exists($file)) {
                require $file;
            }
        });
    }
}