<?php
namespace App\Core;

class Response {
    
    public function json($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    public function success($data = null, $message = 'Success') {
        $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }
    
    public function error($message, $statusCode = 400, $errors = null) {
        $response = [
            'success' => false,
            'error' => $message
        ];
        
        if ($errors !== null) {
            $response['errors'] = $errors;
        }
        
        $this->json($response, $statusCode);
    }
    
    public function notFound($message = 'Resource not found') {
        $this->error($message, 404);
    }
    
    public function unauthorized($message = 'Unauthorized access') {
        $this->error($message, 401);
    }
    
    public function forbidden($message = 'Access forbidden') {
        $this->error($message, 403);
    }
    
    public function validationError($errors, $message = 'Validation failed') {
        $this->error($message, 422, $errors);
    }
    
    public function download($filePath, $filename = null) {
        if (!file_exists($filePath)) {
            $this->notFound('File not found');
        }
        
        $filename = $filename ?? basename($filePath);
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($filePath);
        exit;
    }
    
    public function redirect($url, $statusCode = 302) {
        header('Location: ' . $url, true, $statusCode);
        exit;
    }
    
    public function withHeaders($headers) {
        foreach ($headers as $key => $value) {
            header("$key: $value");
        }
        return $this;
    }
    
    public function setStatusCode($code) {
        http_response_code($code);
        return $this;
    }
    
    public function plain($content, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=utf-8');
        echo $content;
        exit;
    }
    
    public function html($content, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        exit;
    }
}