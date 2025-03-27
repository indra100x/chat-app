<?php
session_start();
require_once 'database.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

try {
    $stmt = $db->prepare("
        SELECT c.id AS chat_id, 
               u.id AS partner_id,
               u.username AS partner_name,
               u.is_online,
               MAX(m.created_at) AS last_activity
        FROM chats c
        JOIN users u ON u.id = CASE 
            WHEN c.user1_id = ? THEN c.user2_id 
            ELSE c.user1_id 
        END
        LEFT JOIN messages m ON m.chat_id = c.id
        WHERE c.user1_id = ? OR c.user2_id = ?
        GROUP BY c.id
        ORDER BY last_activity DESC
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $chats = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Chats error: " . $e->getMessage());
    $chats = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Chats</title>
    <style>
       
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            background: #f0f2f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #fff;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .chat-list {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .chat-item {
            display: flex;
            justify-content: space-between;
            padding: 15px;
            margin: 10px 0;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s;
        }
        .chat-item:hover {
            background: #f8f9fa;
        }
        .partner-name {
            font-weight: 600;
            color: #1a1a1a;
        }
        .last-time {
            color: #65676b;
            font-size: 0.9em;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
        }
        .btn-primary {
            background: #1877f2;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
    
        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            z-index: 1000;
            animation: slide-in 0.5s ease-out;
        }
        
        @keyframes slide-in {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }
        
        .connection-status {
            position: fixed;
            bottom: 10px;
            left: 10px;
            padding: 5px 10px;
            border-radius: 5px;
            background: #f0f0f0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Your Chats</h1>
            <div>
                <a href="chat-request.php" class="btn btn-primary">Chat Requests</a>
                <button class="btn btn-danger" onclick="logout()">Logout</button>
            </div>
        </div>

        <div class="chat-list" id="chatList">
            <?php foreach ($chats as $chat): ?>
                <div class="chat-item" 
                     data-chat-id="<?= $chat['chat_id'] ?>"
                     data-partner-id="<?= $chat['partner_id'] ?>"
                     onclick="openChat(<?= $chat['chat_id'] ?>)">
                    <div class="partner-info">
                        <span class="partner-name">
                            <?= htmlspecialchars($chat['partner_name']) ?>
                            <?php if ($chat['is_online']): ?>
                                <span class="online-dot">•</span>
                            <?php endif; ?>
                        </span>
                        <small class="last-activity">
                            <?= $chat['last_activity'] 
                                ? date('M j, H:i', strtotime($chat['last_activity'])) 
                                : 'No messages' ?>
                        </small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="connection-status" id="connectionStatus">Connected</div>
    </div>

    <script>
        const wsToken = <?= isset($_SESSION['ws_token']) ? json_encode($_SESSION['ws_token']) : '""' ?>;
        let ws;
        let reconnectAttempts = 0;
        const maxReconnectAttempts = 5;

        function connectWebSocket() {
            ws = new WebSocket(`ws://localhost:8080?token=${wsToken}`);

            ws.onopen = () => {
                console.log('WebSocket connected');
                reconnectAttempts = 0;
                updateConnectionStatus('Connected', '#28a745');
            };

            ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                console.log('Received:', data);
                
                switch(data.type) {
                    case 'new_message':
                        updateChatLastMessage(data);
                        break;
                    case 'presence':
                        updateOnlineStatus(data);
                        break;
                    case 'chat_created':
                        addNewChat(data);
                        break;
                }
            };

            ws.onerror = (error) => {
                console.error('WebSocket error:', error);
                updateConnectionStatus('Connection error', '#dc3545');
            };

            ws.onclose = () => {
                console.log('WebSocket disconnected');
                if (reconnectAttempts < maxReconnectAttempts) {
                    reconnectAttempts++;
                    const delay = Math.min(1000 * reconnectAttempts, 5000);
                    updateConnectionStatus(`Reconnecting in ${delay}ms...`, '#ffc107');
                    setTimeout(connectWebSocket, delay);
                } else {
                    updateConnectionStatus('Disconnected', '#dc3545');
                }
            };
        }

        function updateChatLastMessage(data) {
            const chatItem = document.querySelector(`[data-chat-id="${data.chat_id}"]`);
            if (chatItem) {
                const lastActivity = chatItem.querySelector('.last-activity');
                lastActivity.textContent = new Date(data.timestamp).toLocaleString();
                chatItem.style.order = 0; // Move to top
            }
        }

        function updateOnlineStatus(data) {
            const chatItems = document.querySelectorAll(`[data-partner-id="${data.user_id}"]`);
            chatItems.forEach(item => {
                const dot = item.querySelector('.online-dot');
                if (data.is_online) {
                    if (!dot) {
                        item.querySelector('.partner-name').innerHTML += '<span class="online-dot">•</span>';
                    }
                } else if (dot) {
                    dot.remove();
                }
            });
        }

        function addNewChat(data) {
            const chatList = document.getElementById('chatList');
            
            if (document.querySelector(`[data-chat-id="${data.chat_id}"]`)) {
                return;
            }

            const chatItem = document.createElement('div');
            chatItem.className = 'chat-item';
            chatItem.dataset.chatId = data.chat_id;
            chatItem.dataset.partnerId = data.partner_id;
            chatItem.onclick = () => openChat(data.chat_id);
            chatItem.innerHTML = `
                <div class="partner-info">
                    <span class="partner-name">
                        ${data.partner_name}
                        ${data.is_online ? '<span class="online-dot">•</span>' : ''}
                    </span>
                    <small class="last-activity">Just now</small>
                </div>
            `;

            chatList.insertBefore(chatItem, chatList.firstChild);
            showNotification(`New chat with ${data.partner_name}`);
        }

        function openChat(chatId) {
            window.location.href = `chat.php?id=${chatId}`;
        }

        function logout() {
            fetch('logout.php', { method: 'POST' })
                .then(() => window.location.href = 'login.php');
        }

        function updateConnectionStatus(text, color) {
            const status = document.getElementById('connectionStatus');
            status.textContent = text;
            status.style.backgroundColor = color;
        }

        function showNotification(message, type = 'success') {
            const existing = document.querySelector('.notification');
            if (existing) existing.remove();

            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => notification.remove(), 3000);
        }

        // Initial connection
        connectWebSocket();
    </script>
</body>
</html>