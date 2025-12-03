-- پایگاه داده: automation_db
CREATE DATABASE IF NOT EXISTS automation_db 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE automation_db;

-- جدول کاربران
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- جدول توکن‌ها
CREATE TABLE tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB;

-- جدول workflowها
CREATE TABLE workflows (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    public_id VARCHAR(50) UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    trigger_type ENUM('manual', 'webhook', 'schedule') DEFAULT 'manual',
    schedule_cron VARCHAR(50),
    last_executed TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_public_id (public_id),
    INDEX idx_trigger_type (trigger_type)
) ENGINE=InnoDB;

-- جدول نودها
CREATE TABLE nodes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    name VARCHAR(255),
    position_x INT DEFAULT 0,
    position_y INT DEFAULT 0,
    config_json LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    INDEX idx_workflow_id (workflow_id),
    INDEX idx_type (type)
) ENGINE=InnoDB;

-- جدول ارتباطات بین نودها
CREATE TABLE connections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    from_node_id INT NOT NULL,
    to_node_id INT NOT NULL,
    from_output VARCHAR(50) DEFAULT 'default',
    to_input VARCHAR(50) DEFAULT 'default',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (from_node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    FOREIGN KEY (to_node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_connection (workflow_id, from_node_id, to_node_id, from_output, to_input),
    INDEX idx_workflow_id (workflow_id),
    INDEX idx_from_node (from_node_id),
    INDEX idx_to_node (to_node_id)
) ENGINE=InnoDB;

-- جدول اجراها
CREATE TABLE executions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    user_id INT NOT NULL,
    trigger_type VARCHAR(50),
    status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
    started_at TIMESTAMP NULL,
    ended_at TIMESTAMP NULL,
    execution_time_ms INT DEFAULT 0,
    error_message TEXT,
    input_data LONGTEXT,
    output_data LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_workflow_id (workflow_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- جدول لاگ‌های اجرا
CREATE TABLE execution_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    execution_id INT NOT NULL,
    node_id INT,
    log_type ENUM('info', 'error', 'warning', 'debug', 'output') DEFAULT 'info',
    message TEXT NOT NULL,
    data LONGTEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (execution_id) REFERENCES executions(id) ON DELETE CASCADE,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL,
    INDEX idx_execution_id (execution_id),
    INDEX idx_node_id (node_id),
    INDEX idx_log_type (log_type),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB;

-- جدول webhookها
CREATE TABLE webhooks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    workflow_id INT NOT NULL,
    webhook_key VARCHAR(100) UNIQUE NOT NULL,
    secret_token VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    max_calls_per_hour INT DEFAULT 100,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_called TIMESTAMP NULL,
    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    INDEX idx_webhook_key (webhook_key),
    INDEX idx_workflow_id (workflow_id)
) ENGINE=InnoDB;

-- جدول لاگ‌های وب‌هوک
CREATE TABLE webhook_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    webhook_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_method VARCHAR(10),
    request_headers TEXT,
    request_body LONGTEXT,
    response_code INT,
    response_body LONGTEXT,
    processing_time_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE,
    INDEX idx_webhook_id (webhook_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- ایجاد trigger برای public_id
DELIMITER //
CREATE TRIGGER before_workflow_insert
BEFORE INSERT ON workflows
FOR EACH ROW
BEGIN
    IF NEW.public_id IS NULL THEN
        SET NEW.public_id = CONCAT('wf_', SUBSTRING(MD5(RAND()), 1, 10), '_', UNIX_TIMESTAMP());
    END IF;
END//
DELIMITER ;