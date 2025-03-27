<?php
declare(strict_types=1);
session_start();

require_once 'database.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['chat_id'])) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$user_id = (int)$_SESSION['user_id'];
$chat_id = (int)$_GET['chat_id'];

try {
   
    $stmt = $db->prepare("
        SELECT 1 
        FROM chats 
        WHERE id = ? 
        AND (user1_id = ? OR user2_id = ?)
    ");
    $stmt->execute([$chat_id, $user_id, $user_id]);
    
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        exit(json_encode(['error' => 'Chat not found']));
    }

    
    $stmt = $db->prepare("
        SELECT m.*, u.username 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.chat_id = ?
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$chat_id]);
    $messages = $stmt->fetchAll();

  
    $messages = array_reverse($messages);

    header('Content-Type: application/json');
    echo json_encode($messages);

} catch(PDOException $e) {
    http_response_code(500);
    error_log("Messages Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>