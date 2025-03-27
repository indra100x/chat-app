<?php
session_start();
require_once 'database.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once 'helpers.php';

use App\Helpers\AuthHelper;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Initialize logging
$logger = new Logger('chat_application');
$logger->pushHandler(new StreamHandler(__DIR__ . '/logs/chat.log', Logger::ERROR));

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$chat_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1]
]) ?: 0;

try {
    // Validate chat access
    $stmt = $db->prepare("
        SELECT c.id, c.user1_id, c.user2_id 
        FROM chats c
        WHERE c.id = ? 
        AND (c.user1_id = ? OR c.user2_id = ?)
    ");
    $stmt->execute([$chat_id, $current_user_id, $current_user_id]);
    
    if (!$stmt->rowCount()) {
        header("Location: chats.php?error=invalid_chat");
        exit();
    }
    
    $chat = $stmt->fetch(PDO::FETCH_ASSOC);
    $partner_id = ($chat['user1_id'] == $current_user_id) 
                ? $chat['user2_id'] 
                : $chat['user1_id'];

    // Fetch partner details
    $stmt = $db->prepare("
        SELECT id, username, is_online, last_seen 
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$partner_id]);
    $partner = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$partner) {
        throw new Exception("Partner not found");
    }

    // Fetch messages
    $stmt = $db->prepare("
        SELECT m.*, u.username 
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.chat_id = ?
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$chat_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(Exception $e) {
    $logger->error('Chat Error: ' . $e->getMessage());
    header("Location: chats.php?error=system_error");
    exit();
}

// Generate secure token for WebSocket authentication
$token = AuthHelper::generateToken($current_user_id);

// Prepare last seen information
$last_seen_formatted = $partner['last_seen'] 
    ? date('h:i A', strtotime($partner['last_seen'])) 
    : 'Unknown';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with <?= htmlspecialchars($partner['username']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; height: 100vh; display: flex; flex-direction: column; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; flex: 1; }
        .chat-header { background: #1877f2; color: white; padding: 15px; border-radius: 10px 10px 0 0; }
        .messages-container { height: 70vh; overflow-y: auto; padding: 20px; background: white; border: 1px solid #ddd; display: flex; flex-direction: column; }
        .message { max-width: 70%; margin: 10px 0; padding: 10px; border-radius: 15px; position: relative; }
        .received { background: #f0f0f0; align-self: flex-start; }
        .sent { background: #1877f2; color: white; align-self: flex-end; }
        .message-form { display: flex; gap: 10px; padding: 15px; background: white; border: 1px solid #ddd; }
        .message-input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; }
        .send-button { padding: 10px 20px; background: #1877f2; color: white; border: none; border-radius: 20px; cursor: pointer; }
        .timestamp { font-size: 0.75rem; opacity: 0.8; margin-top: 5px; }
        .online-status { font-size: 0.8rem; margin-left: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="chat-header">
            <h2>
                <?= htmlspecialchars($partner['username']) ?>
                <small class="online-status">
                    (<?= $partner['is_online'] ? 'Online' : 'Last seen ' . $last_seen_formatted ?>)
                </small>
            </h2>
            <a href="chats.php" style="color: white;">← Back to Chats</a>
        </div>
        
        <div class="messages-container" id="messages">
            <?php foreach ($messages as $message): ?>
                <div class="message <?= $message['sender_id'] == $current_user_id ? 'sent' : 'received' ?>">
                    <div><?= htmlspecialchars($message['message']) ?></div>
                    <div class="timestamp">
                        <?= date('h:i A', strtotime($message['created_at'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <form class="message-form" id="messageForm">
            <input type="text" name="message" class="message-input" placeholder="Type your message..." required autocomplete="off" maxlength="500">
            <button type="submit" class="send-button">Send</button>
        </form>
    </div>

    <script>
    const WEBSOCKET_URL = 'ws://localhost:8080'; // Consider making this configurable
    const messagesContainer = document.getElementById('messages');
    const token = '<?= $token ?>';
    const chatId = <?= $chat_id ?>;
    const currentUserId = <?= $current_user_id ?>;

    // Robust WebSocket connection management
    class ChatWebSocket {
        constructor(url, token, chatId) {
            this.url = url;
            this.token = token;
            this.chatId = chatId;
            this.ws = null;
            this.reconnectAttempts = 0;
            this.connect();
        }

        connect() {
            const fullUrl = `${this.url}?token=${encodeURIComponent(this.token)}&chat_id=${this.chatId}`;
            
            this.ws = new WebSocket(fullUrl);

            this.ws.onopen = () => {
                console.log('Connected to chat server');
                this.reconnectAttempts = 0;
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            };

            this.ws.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    switch(data.type) {
                        case 'new_message':
                            this.addMessage(data);
                            break;
                        case 'user_status':
                            this.updateUserStatus(data);
                            break;
                    }
                } catch (error) {
                    console.error('Message parsing error:', error);
                }
            };

            this.ws.onerror = (error) => {
                console.error('WebSocket Error:', error);
                this.reconnect();
            };

            this.ws.onclose = (event) => {
                if (!event.wasClean) {
                    console.log('Connection lost, reconnecting...');
                    this.reconnect();
                }
            };
        }

        reconnect() {
            const delay = Math.min(30, Math.pow(2, this.reconnectAttempts)) * 1000;
            this.reconnectAttempts++;

            setTimeout(() => {
                this.connect();
            }, delay);
        }

        send(message) {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                this.ws.send(JSON.stringify({
                    type: 'message',
                    message: message,
                    chat_id: this.chatId
                }));
            } else {
                console.error('WebSocket not connected');
                alert('Unable to send message. Reconnecting...');
            }
        }

        addMessage(data) {
            const isCurrentUser = data.sender_id === currentUserId;
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${isCurrentUser ? 'sent' : 'received'}`;
            messageDiv.innerHTML = `
                <div class="message-content">${this.escapeHtml(data.message)}</div>
                <div class="timestamp">${new Date(data.created_at).toLocaleTimeString()}</div>
            `;
            messagesContainer.appendChild(messageDiv);
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        updateUserStatus(data) {
            const statusElement = document.querySelector('.online-status');
            if (data.user_id === <?= $partner['id'] ?>) {
                statusElement.textContent = data.is_online 
                    ? 'Online' 
                    : `Last seen ${new Date(data.last_seen).toLocaleTimeString()}`;
            }
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    
    const chatSocket = new ChatWebSocket(WEBSOCKET_URL, token, chatId);

    
    document.getElementById('messageForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const input = document.querySelector('.message-input');
        const message = input.value.trim();
        
        if (message) {
            chatSocket.send(message);
            input.value = '';
        }
    });
    </script>
</body>
</html>