<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$response = ['success' => false];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['accept_request'])) {
            $request_id = intval($_POST['request_id']);
            $sender_id = intval($_POST['sender_id']);
            
          
            $stmt = $db->prepare("
                SELECT id FROM chat_requests 
                WHERE id = ? 
                AND receiver_id = ?
                AND status = 'pending'
            ");
            $stmt->execute([$request_id, $_SESSION['user_id']]);
            
            if ($stmt->rowCount() > 0) {
               
                $stmt = $db->prepare("
                    INSERT INTO chats (user1_id, user2_id)
                    VALUES (?, ?)
                ");
                $stmt->execute([$sender_id, $_SESSION['user_id']]);
                $chat_id = $db->lastInsertId();
                
               
                $stmt = $db->prepare("
                    UPDATE chat_requests 
                    SET status = 'accepted'
                    WHERE id = ?
                ");
                $stmt->execute([$request_id]);
                
                
                notifyUser($sender_id, 'request_accepted', [
                    'chat_id' => $chat_id,
                    'receiver_id' => $_SESSION['user_id']
                ]);
                
                $response = [
                    'success' => true,
                    'request_id' => $request_id,
                    'chat_id' => $chat_id
                ];
            } else {
                $response['error'] = 'Invalid request';
            }
        }
        elseif (isset($_POST['reject_request'])) {
            $request_id = intval($_POST['request_id']);
            
            $stmt = $db->prepare("
                UPDATE chat_requests 
                SET status = 'rejected'
                WHERE id = ?
                AND receiver_id = ?
            ");
            $stmt->execute([$request_id, $_SESSION['user_id']]);
            
            $response = [
                'success' => true,
                'request_id' => $request_id
            ];
        }
    }
} catch (PDOException $e) {
    error_log("Request handler error: " . $e->getMessage());
    $response['error'] = 'Database error';
}

echo json_encode($response);