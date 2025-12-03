<?php
namespace App\Core;

class Database {
    private $connection;
    private static $instance = null;
    
    public function __construct() {
        $config = $this->loadConfig();
        
        try {
            $this->connection = new \mysqli(
                $config['host'],
                $config['username'],
                $config['password'],
                $config['database'],
                $config['port']
            );
            
            if ($this->connection->connect_error) {
                throw new \Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            $this->connection->set_charset("utf8mb4");
            
        } catch (\Exception $e) {
            error_log("Database Error: " . $e->getMessage());
            die("Database connection error. Check logs for details.");
        }
    }
    
    private function loadConfig() {
        $configPath = __DIR__ . '/../../config/database.php';
        
        if (file_exists($configPath)) {
            return require $configPath;
        }
        
        // مقادیر پیش‌فرض
        return [
            'host' => 'localhost',
            'username' => 'root',
            'password' => '',
            'database' => 'automation_db',
            'port' => 3306,
            'charset' => 'utf8mb4'
        ];
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        
        if (!$stmt) {
            throw new \Exception("Prepare failed: " . $this->connection->error);
        }
        
        if (!empty($params)) {
            $types = '';
            $values = [];
            
            foreach ($params as $param) {
                if (is_int($param)) {
                    $types .= 'i';
                } elseif (is_float($param)) {
                    $types .= 'd';
                } elseif (is_string($param)) {
                    $types .= 's';
                } else {
                    $types .= 'b';
                }
                $values[] = $param;
            }
            
            $stmt->bind_param($types, ...$values);
        }
        
        $stmt->execute();
        return $stmt;
    }
    
    public function select($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $result = $stmt->get_result();
        
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        
        $stmt->close();
        return $rows;
    }
    
    public function selectOne($sql, $params = []) {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? null;
    }
    
    public function insert($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $insertId = $stmt->insert_id;
        $stmt->close();
        return $insertId;
    }
    
    public function update($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        return $affectedRows;
    }
    
    public function delete($sql, $params = []) {
        return $this->update($sql, $params);
    }
    
    public function escape($value) {
        return $this->connection->real_escape_string($value);
    }
    
    public function beginTransaction() {
        $this->connection->begin_transaction();
    }
    
    public function commit() {
        $this->connection->commit();
    }
    
    public function rollback() {
        $this->connection->rollback();
    }
    
    public function lastInsertId() {
        return $this->connection->insert_id;
    }
    
    public function affectedRows() {
        return $this->connection->affected_rows;
    }
    
    public function __destruct() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}