<?php
namespace App\Core;

class Validator {
    private $data;
    private $errors = [];
    private $rules = [];
    
    public function __construct($data = []) {
        $this->data = $data;
    }
    
    public function setData($data) {
        $this->data = $data;
        return $this;
    }
    
    public function validate($rules) {
        $this->rules = $rules;
        $this->errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            $fieldRules = explode('|', $fieldRules);
            $value = $this->getValue($field);
            
            foreach ($fieldRules as $rule) {
                $this->applyRule($field, $value, $rule);
            }
        }
        
        return empty($this->errors);
    }
    
    public function errors() {
        return $this->errors;
    }
    
    public function firstError() {
        if (empty($this->errors)) {
            return null;
        }
        
        $firstField = array_key_first($this->errors);
        return $this->errors[$firstField][0] ?? null;
    }
    
    private function getValue($field) {
        $keys = explode('.', $field);
        $value = $this->data;
        
        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }
        
        return $value;
    }
    
    private function applyRule($field, $value, $rule) {
        $params = [];
        
        if (strpos($rule, ':') !== false) {
            list($rule, $paramString) = explode(':', $rule, 2);
            $params = explode(',', $paramString);
        }
        
        $method = 'validate' . ucfirst($rule);
        
        if (method_exists($this, $method)) {
            $result = call_user_func_array([$this, $method], [$field, $value, $params]);
            if (!$result) {
                $this->addError($field, $rule, $params);
            }
        }
    }
    
    private function addError($field, $rule, $params) {
        $messages = [
            'required' => 'The :field field is required.',
            'email' => 'The :field must be a valid email address.',
            'min' => 'The :field must be at least :min characters.',
            'max' => 'The :field may not be greater than :max characters.',
            'numeric' => 'The :field must be a number.',
            'integer' => 'The :field must be an integer.',
            'string' => 'The :field must be a string.',
            'array' => 'The :field must be an array.',
            'in' => 'The selected :field is invalid.',
            'url' => 'The :field must be a valid URL.',
            'regex' => 'The :field format is invalid.'
        ];
        
        $message = $messages[$rule] ?? 'The :field field is invalid.';
        $message = str_replace(':field', $field, $message);
        
        foreach ($params as $key => $param) {
            $message = str_replace(":{$key}", $param, $message);
        }
        
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        
        $this->errors[$field][] = $message;
    }
    
    // Validation methods
    private function validateRequired($field, $value, $params) {
        if (is_string($value)) {
            return trim($value) !== '';
        }
        
        if (is_array($value)) {
            return !empty($value);
        }
        
        return $value !== null;
    }
    
    private function validateEmail($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    private function validateMin($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        $min = intval($params[0] ?? 0);
        
        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }
        
        if (is_numeric($value)) {
            return $value >= $min;
        }
        
        if (is_array($value)) {
            return count($value) >= $min;
        }
        
        return false;
    }
    
    private function validateMax($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        $max = intval($params[0] ?? 0);
        
        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }
        
        if (is_numeric($value)) {
            return $value <= $max;
        }
        
        if (is_array($value)) {
            return count($value) <= $max;
        }
        
        return false;
    }
    
    private function validateNumeric($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        return is_numeric($value);
    }
    
    private function validateInteger($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }
    
    private function validateString($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        return is_string($value);
    }
    
    private function validateArray($field, $value, $params) {
        if ($value === null) {
            return true;
        }
        
        return is_array($value);
    }
    
    private function validateIn($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        return in_array($value, $params);
    }
    
    private function validateUrl($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
    
    private function validateRegex($field, $value, $params) {
        if ($value === null || $value === '') {
            return true;
        }
        
        if (empty($params[0])) {
            return false;
        }
        
        return preg_match($params[0], $value) === 1;
    }
}