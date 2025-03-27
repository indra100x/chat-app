<?php


$servername = "localhost";
$username = "root"; 
$password = "";      
$dbname = "chat_app";
$websocket_url = "ws://localhost:8080";


error_reporting(E_ALL);
ini_set('display_errors', 1);


function logError($message) {
    $logMessage = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    file_put_contents(__DIR__ . '/database_errors.log', $logMessage, FILE_APPEND);
    error_log($logMessage);
}


$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
];

try {
   
    $initDb = new PDO("mysql:host=$servername", $username, $password, $pdoOptions);
    
   
    $initDb->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    
    $initDb->exec("CREATE DATABASE IF NOT EXISTS `$dbname` 
              CHARACTER SET utf8mb4 
              COLLATE utf8mb4_unicode_ci");
    
    
    $initDb->exec("USE `$dbname`");

   
    $tables = [
        'users' => "
            CREATE TABLE IF NOT EXISTS `users` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(50) UNIQUE NOT NULL,
                `user_pwd_hash` VARCHAR(255) NOT NULL,
                `is_online` BOOLEAN DEFAULT FALSE,
                `last_seen` TIMESTAMP NULL DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_username` (`username`),
                INDEX `idx_online_status` (`is_online`, `last_seen`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",

        'chat_requests' => "
            CREATE TABLE IF NOT EXISTS `chat_requests` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `sender_id` INT UNSIGNED NOT NULL,
                `receiver_id` INT UNSIGNED NOT NULL,
                `status` ENUM('pending','accepted','rejected') DEFAULT 'pending',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                INDEX `idx_sender_receiver_status` (`sender_id`, `receiver_id`, `status`),
                INDEX `idx_status_created` (`status`, `created_at`),
                CONSTRAINT `unique_request_pair` UNIQUE (`sender_id`, `receiver_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",

        'chats' => "
            CREATE TABLE IF NOT EXISTS `chats` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user1_id` INT UNSIGNED NOT NULL,
                `user2_id` INT UNSIGNED NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                 user1_min INT GENERATED ALWAYS AS (LEAST(user1_id, user2_id)) STORED,
               user2_max INT GENERATED ALWAYS AS (GREATEST(user1_id, user2_id)) STORED,
                  FOREIGN KEY (user1_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (user2_id) REFERENCES users(id) ON DELETE CASCADE,
                  UNIQUE KEY unique_chat_pair (user1_min, user2_max),
                INDEX `idx_users_chat` (`user1_id`, `user2_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",

        'messages' => "
            CREATE TABLE IF NOT EXISTS `messages` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `chat_id` INT UNSIGNED NOT NULL,
                `sender_id` INT UNSIGNED NOT NULL,
                `message` TEXT NOT NULL,
                `is_delivered` BOOLEAN DEFAULT FALSE,
                `is_read` BOOLEAN DEFAULT FALSE,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`chat_id`) REFERENCES `chats`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                INDEX `idx_chat_sender` (`chat_id`, `sender_id`),
                INDEX `idx_delivery_status` (`is_delivered`, `is_read`),
                FULLTEXT INDEX `idx_message_content` (`message`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",

        'notifications' => "
            CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `type` VARCHAR(50) NOT NULL,
                `data` JSON,
                `is_read` BOOLEAN DEFAULT FALSE,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                INDEX `idx_user_type` (`user_id`, `type`),
                INDEX `idx_read_status` (`is_read`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ",

        'ws_connections' => "
            CREATE TABLE IF NOT EXISTS `ws_connections` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT UNSIGNED NOT NULL,
                `connection_id` VARCHAR(255) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
                UNIQUE KEY `unique_connection` (`connection_id`),
                INDEX `idx_user_connection` (`user_id`, `connection_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        "
    ];

    
    foreach ($tables as $tableName => $tableDefinition) {
        try {
            $initDb->exec($tableDefinition);
            logError("Table '$tableName' created successfully.");
        } catch (PDOException $e) {
            logError("Table creation error for '$tableName': " . $e->getMessage() . 
                     " (Error Code: " . $e->getCode() . ")");
          
        }
    }

  
    $initDb->exec("SET FOREIGN_KEY_CHECKS = 1");

   
    $db = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        $pdoOptions + [PDO::ATTR_PERSISTENT => true]
    );

    

} catch (PDOException $e) {
    logError("Fatal database initialization error: " . $e->getMessage() . 
             " (Error Code: " . $e->getCode() . ")");
    die("Database setup failed. Please check the error logs.");
}


function notifyUser($userId, $type, $data = []) {
    global $db;
    
    try {
        if (!is_numeric($userId) || empty($type)) {
            throw new InvalidArgumentException("Invalid user ID or notification type");
        }

        $jsonData = json_encode($data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException("Invalid JSON data for notification");
        }

        $stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, data)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $type, $jsonData]);
        return $db->lastInsertId();
        
    } catch (PDOException $e) {
        logError("Notification error for user $userId: " . $e->getMessage());
        return false;
    } catch (InvalidArgumentException $e) {
        logError("Invalid notification input: " . $e->getMessage());
        return false;
    }
}


function updateUserStatus($userId, $isOnline) {
    global $db;
    
    try {
        if (!is_numeric($userId)) {
            throw new InvalidArgumentException("Invalid user ID");
        }

        $stmt = $db->prepare("
            UPDATE users 
            SET is_online = ?, 
                last_seen = IF(?, NULL, CURRENT_TIMESTAMP)
            WHERE id = ?
        ");
        $stmt->execute([$isOnline ? 1 : 0, $isOnline ? 1 : 0, $userId]);
        
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        logError("Status update error for user $userId: " . $e->getMessage());
        return false;
    } catch (InvalidArgumentException $e) {
        logError("Invalid status update input: " . $e->getMessage());
        return false;
    }
}


function markMessagesAsRead($chatId, $userId) {
    global $db;
    
    try {
        if (!is_numeric($chatId) || !is_numeric($userId)) {
            throw new InvalidArgumentException("Invalid chat or user ID");
        }

        $stmt = $db->prepare("
            UPDATE messages 
            SET is_read = TRUE 
            WHERE chat_id = ? 
            AND sender_id != ?
            AND is_read = FALSE
        ");
        $stmt->execute([$chatId, $userId]);
        return $stmt->rowCount(); 
    } catch (PDOException $e) {
        logError("Read receipt error for chat $chatId: " . $e->getMessage());
        return false;
    } catch (InvalidArgumentException $e) {
        logError("Invalid read receipt input: " . $e->getMessage());
        return false;
    }
}