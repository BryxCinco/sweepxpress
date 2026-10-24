<?php
// ============================================================
// SweepXpress - Log Helper (FIXED)
// ============================================================

/**
 * Logs an action performed by or on a user.
 *
 * @param PDO $pdo The database connection object.
 * @param int $userId The ID of the user the action is about (the subject).
 * @param string $actionType A constant/string defining the action (e.g., 'LOGIN_SUCCESS').
 * @param string $description Detailed text describing the log event.
 * @param int|null $actionById The ID of the user who performed the action (e.g., admin). Null if the subject performed it.
 * @param string|null $ip The IP address associated with the action.
 * @return bool True on success, false on failure.
 */
function log_user_action($pdo, $userId, $actionType, $description, $actionById = null, $ip = null) {
    
    // Fallback to get IP from server variables
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    try {
        // Check if user_logs table exists, if not create it
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                action_by_id INT DEFAULT NULL,
                action_type VARCHAR(50) NOT NULL,
                description TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_action_type (action_type),
                INDEX idx_created_at (created_at)
            )
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO user_logs 
            (user_id, action_by_id, action_type, description, ip_address) 
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([
            $userId, 
            $actionById, 
            $actionType, 
            $description, 
            $ip
        ]);
    } catch (PDOException $e) {
        // Log to error log instead of silencing
        error_log("Logging error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log error messages to file (backup method)
 */
function logError($message, $context = []) {
    $log_dir = __DIR__ . '/../logs/';
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . 'error.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $log_entry = "[{$timestamp}] ERROR: {$message}";
    
    if (!empty($context)) {
        $log_entry .= " | Context: " . json_encode($context);
    }
    
    $log_entry .= PHP_EOL;
    
    error_log($log_entry, 3, $log_file);
}

/**
 * Log info messages
 */
function logInfo($message, $context = []) {
    $log_dir = __DIR__ . '/../logs/';
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . 'app.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $log_entry = "[{$timestamp}] INFO: {$message}";
    
    if (!empty($context)) {
        $log_entry .= " | Context: " . json_encode($context);
    }
    
    $log_entry .= PHP_EOL;
    
    error_log($log_entry, 3, $log_file);
}
?>