<?php
namespace App\Core;

class Router {
    private $routes = [];
    private $middlewares = [];
    private $request;
    private $response;
    
    public function __construct() {
        $this->request = new Request();
        $this->response = new Response();
    }
    
    public function get($path, $callback) {
        $this->addRoute('GET', $path, $callback);
    }
    
    public function post($path, $callback) {
        $this->addRoute('POST', $path, $callback);
    }
    
    public function put($path, $callback) {
        $this->addRoute('PUT', $path, $callback);
    }
    
    public function delete($path, $callback) {
        $this->addRoute('DELETE', $path, $callback);
    }
    
    public function patch($path, $callback) {
        $this->addRoute('PATCH', $path, $callback);
    }
    
    private function addRoute($method, $path, $callback) {
        $this->routes[$method][$path] = $callback;
    }
    
    public function middleware($middleware) {
        $this->middlewares[] = $middleware;
        return $this;
    }
    
    public function dispatch() {
        $method = $this->request->method();
        $path = $this->request->path();
        
        // بررسی وجود route
        if (!isset($this->routes[$method])) {
            $this->response->notFound('Route not found');
        }
        
        foreach ($this->routes[$method] as $route => $callback) {
            $pattern = $this->convertToRegex($route);
            
            if (preg_match("#^$pattern$#", $path, $matches)) {
                array_shift($matches);
                
                // اجرای middlewares
                foreach ($this->middlewares as $middleware) {
                    $middleware($this->request, $this->response);
                }
                
                // اجرای callback
                if (is_string($callback)) {
                    $this->executeController($callback, $matches);
                } elseif (is_callable($callback)) {
                    call_user_func_array($callback, array_merge([$this->request, $this->response], $matches));
                } else {
                    $this->response->error('Invalid route callback', 500);
                }
                
                return;
            }
        }
        
        $this->response->notFound('Route not found');
    }
    
    private function convertToRegex($route) {
        // تبدیل {id} به regex pattern
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $route);
        return str_replace('/', '\/', $pattern);
    }
    
    private function executeController($callback, $params) {
        list($controllerName, $methodName) = explode('@', $callback);
        
        $controllerClass = "App\\Controllers\\" . $controllerName;
        
        if (!class_exists($controllerClass)) {
            $this->response->error("Controller $controllerClass not found", 500);
        }
        
        $controller = new $controllerClass();
        
        if (!method_exists($controller, $methodName)) {
            $this->response->error("Method $methodName not found in $controllerClass", 500);
        }
        
        // تزریق request و response
        if (method_exists($controller, 'setRequest')) {
            $controller->setRequest($this->request);
        }
        
        if (method_exists($controller, 'setResponse')) {
            $controller->setResponse($this->response);
        }
        
        call_user_func_array([$controller, $methodName], $params);
    }
    
    public function group($attributes, $callback) {
        $prefix = $attributes['prefix'] ?? '';
        $middleware = $attributes['middleware'] ?? null;
        
        // ذخیره routeهای فعلی
        $currentRoutes = $this->routes;
        $currentMiddlewares = $this->middlewares;
        
        // افزودن middleware اگر وجود دارد
        if ($middleware) {
            if (is_array($middleware)) {
                $this->middlewares = array_merge($this->middlewares, $middleware);
            } else {
                $this->middlewares[] = $middleware;
            }
        }
        
        // افزودن prefix
        $originalRoutes = $this->routes;
        $this->routes = [];
        
        // اجرای callback برای تعریف routeهای جدید
        call_user_func($callback);
        
        // اضافه کردن prefix به routeهای جدید
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route => $handler) {
                $prefixedRoute = $prefix . $route;
                $originalRoutes[$method][$prefixedRoute] = $handler;
            }
        }
        
        // بازگرداندن state
        $this->routes = $originalRoutes;
        $this->middlewares = $currentMiddlewares;
    }
}