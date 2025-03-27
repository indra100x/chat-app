<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
require_once 'database.php';


ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$search_results = [];
$received_requests = [];
$sent_requests = [];
$error = '';
$success = '';

try {

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['search'])) {
        $search_term = trim($_GET['search']);
        if (!empty($search_term)) {
            $search_term = "%$search_term%";
            $stmt = $db->prepare("
                SELECT id, username 
                FROM users 
                WHERE username LIKE :search 
                AND id != :current_user
                LIMIT 10
            ");
            $stmt->bindParam(':search', $search_term, PDO::PARAM_STR);
            $stmt->bindParam(':current_user', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->execute();
            $search_results = $stmt->fetchAll();
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_request'])) {
        $receiver_id = intval($_POST['receiver_id']);
        
     
        $stmt = $db->prepare("
            SELECT id 
            FROM chat_requests 
            WHERE sender_id = :sender_id 
            AND receiver_id = :receiver_id
            AND status = 'pending'
        ");
        $stmt->execute([
            ':sender_id' => $_SESSION['user_id'],
            ':receiver_id' => $receiver_id
        ]);
        
        if ($stmt->rowCount() === 0) {
            $stmt = $db->prepare("
                INSERT INTO chat_requests (sender_id, receiver_id)
                VALUES (:sender_id, :receiver_id)
            ");
            $stmt->execute([
                ':sender_id' => $_SESSION['user_id'],
                ':receiver_id' => $receiver_id
            ]);
            
            notifyUser($receiver_id, 'new_request', [
                'request_id' => $db->lastInsertId(),
                'sender_id' => $_SESSION['user_id']
            ]);
            
            $success = "Chat request sent!";
        } else {
            $error = "You already have a pending request with this user";
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['accept_request'])) {
            $request_id = intval($_POST['request_id']);
            $sender_id = intval($_POST['sender_id']);
            
      
            $stmt = $db->prepare("
                SELECT id FROM chat_requests 
                WHERE id = :request_id 
                AND receiver_id = :receiver_id
                AND status = 'pending'
            ");
            $stmt->execute([
                ':request_id' => $request_id,
                ':receiver_id' => $_SESSION['user_id']
            ]);
            
            if ($stmt->rowCount() > 0) {
          
                $stmt = $db->prepare("
                    INSERT INTO chats (user1_id, user2_id)
                    VALUES (:user1_id, :user2_id)
                ");
                $stmt->execute([
                    ':user1_id' => $sender_id,
                    ':user2_id' => $_SESSION['user_id']
                ]);
                
              
                $stmt = $db->prepare("
                    UPDATE chat_requests 
                    SET status = 'accepted',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :request_id
                ");
                $stmt->execute([':request_id' => $request_id]);
                
                notifyUser($sender_id, 'request_accepted', [
                    'chat_id' => $db->lastInsertId(),
                    'receiver_id' => $_SESSION['user_id']
                ]);
                
                $success = "Chat request accepted!";
            } else {
                $error = "Invalid or expired request";
            }
        }
        elseif (isset($_POST['reject_request'])) {
            $request_id = intval($_POST['request_id']);
            
            $stmt = $db->prepare("
                UPDATE chat_requests 
                SET status = 'rejected',
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :request_id
                AND receiver_id = :receiver_id
            ");
            $stmt->execute([
                ':request_id' => $request_id,
                ':receiver_id' => $_SESSION['user_id']
            ]);
            
            $success = "Chat request rejected";
        }
    }

  
    $stmt = $db->prepare("
        SELECT cr.id AS request_id, 
               u.username, 
               cr.sender_id, 
               cr.created_at
        FROM chat_requests cr
        JOIN users u ON cr.sender_id = u.id
        WHERE cr.receiver_id = :receiver_id
        AND cr.status = 'pending'
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([':receiver_id' => $_SESSION['user_id']]);
    $received_requests = $stmt->fetchAll();

   
    $stmt = $db->prepare("
        SELECT cr.id AS request_id, 
               u.username, 
               cr.receiver_id, 
               cr.status, 
               cr.created_at
        FROM chat_requests cr
        JOIN users u ON cr.receiver_id = u.id
        WHERE cr.sender_id = :sender_id
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute([':sender_id' => $_SESSION['user_id']]);
    $sent_requests = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Chat request error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Requests</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            background: #f0f2f5;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .search-input {
            flex-grow: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .search-button {
            padding: 10px 20px;
            background: #1877f2;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .user-list, .request-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .user-item, .request-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        .request-actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-primary {
            background: #1877f2;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .alert {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status-badge {
            font-size: 0.8em;
            padding: 3px 8px;
            border-radius: 10px;
            background: #f0f0f0;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-accepted {
            background: #d4edda;
            color: #155724;
        }
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Chat Requests</h1>
            <a href="chats.php" class="btn btn-primary">Back to Chats</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <div class="section">
            <h2>Search Users</h2>
            <form class="search-form" method="GET">
                <input type="text" name="search" class="search-input" 
                       placeholder="Search by username..."
                       value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                <button type="submit" class="search-button">Search</button>
            </form>

            <?php if (!empty($search_results)): ?>
                <ul class="user-list">
                    <?php foreach ($search_results as $user): ?>
                        <li class="user-item">
                            <span><?= htmlspecialchars($user['username']) ?></span>
                            <form method="POST">
                                <input type="hidden" name="receiver_id" value="<?= $user['id'] ?>">
                                <button type="submit" name="send_request" class="btn btn-primary">
                                    Send Request
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (isset($_GET['search'])): ?>
                <p>No users found matching your search</p>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>Received Requests</h2>
            <?php if (!empty($received_requests)): ?>
                <ul class="request-list">
                    <?php foreach ($received_requests as $request): ?>
                        <li class="request-item">
                            <div>
                                <div><?= htmlspecialchars($request['username']) ?></div>
                                <small><?= date('M j, H:i', strtotime($request['created_at'])) ?></small>
                            </div>
                            <div class="request-actions">
                                <form method="POST">
                                    <input type="hidden" name="request_id" value="<?= $request['request_id'] ?>">
                                    <input type="hidden" name="sender_id" value="<?= $request['sender_id'] ?>">
                                    <button type="submit" name="accept_request" class="btn btn-success">
                                        Accept
                                    </button>
                                </form>
                                <form method="POST">
                                    <input type="hidden" name="request_id" value="<?= $request['request_id'] ?>">
                                    <button type="submit" name="reject_request" class="btn btn-danger">
                                        Reject
                                    </button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No pending requests</p>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2>Sent Requests</h2>
            <?php if (!empty($sent_requests)): ?>
                <ul class="request-list">
                    <?php foreach ($sent_requests as $request): ?>
                        <li class="request-item">
                            <div>
                                <div><?= htmlspecialchars($request['username']) ?></div>
                                <div>
                                    <span class="status-badge status-<?= $request['status'] ?>">
                                        <?= ucfirst($request['status']) ?>
                                    </span>
                                    <small><?= date('M j, H:i', strtotime($request['created_at'])) ?></small>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No sent requests</p>
            <?php endif; ?>
        </div>
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
        };

        ws.onmessage = (event) => {
            const data = JSON.parse(event.data);
            console.log('Received:', data);
            
            switch(data.type) {
                case 'new_request':
                    addNewRequest(data);
                    break;
                case 'request_accepted':
                    showAcceptNotification(data);
                    break;
                case 'request_rejected':
                    showRejectNotification(data);
                    break;
            }
        };

        ws.onerror = (error) => {
            console.error('WebSocket error:', error);
        };

        ws.onclose = () => {
            console.log('WebSocket disconnected');
            if (reconnectAttempts < maxReconnectAttempts) {
                reconnectAttempts++;
                const delay = Math.min(1000 * reconnectAttempts, 5000);
                console.log(`Reconnecting in ${delay}ms...`);
                setTimeout(connectWebSocket, delay);
            } else {
                console.error('Max reconnection attempts reached');
            }
        };
    }

    // Initial connection
    connectWebSocket();

    // Handle new incoming requests
    function addNewRequest(data) {
        const requestList = document.querySelector('.request-list');
        
        // Check if request already exists
        if (document.querySelector(`[data-request-id="${data.request_id}"]`)) {
            return;
        }

        const requestItem = document.createElement('li');
        requestItem.className = 'request-item';
        requestItem.dataset.requestId = data.request_id;
        requestItem.innerHTML = `
            <div>
                <div>${escapeHtml(data.sender_name)}</div>
                <small>Just now</small>
            </div>
            <div class="request-actions">
                <form method="POST" onsubmit="return handleAccept(this)">
                    <input type="hidden" name="request_id" value="${data.request_id}">
                    <input type="hidden" name="sender_id" value="${data.sender_id}">
                    <button type="submit" name="accept_request" class="btn btn-success">
                        Accept
                    </button>
                </form>
                <form method="POST" onsubmit="return handleReject(this)">
                    <input type="hidden" name="request_id" value="${data.request_id}">
                    <button type="submit" name="reject_request" class="btn btn-danger">
                        Reject
                    </button>
                </form>
            </div>
        `;
        
        requestList.prepend(requestItem);
        showNotification(`New chat request from ${data.sender_name}`);
    }

    // Handle form submissions without page reload
    function handleAccept(form) {
        fetch('handle_request.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const requestItem = document.querySelector(`[data-request-id="${data.request_id}"]`);
                if (requestItem) {
                    requestItem.remove();
                }
                showNotification('Request accepted!');
                // Optionally redirect to chat
                if (data.chat_id) {
                    window.location.href = `chat.php?id=${data.chat_id}`;
                }
            } else {
                showNotification(data.error || 'Error accepting request', 'error');
            }
        });
        return false;
    }

    function handleReject(form) {
        fetch('handle_request.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const requestItem = document.querySelector(`[data-request-id="${data.request_id}"]`);
                if (requestItem) {
                    requestItem.remove();
                }
                showNotification('Request rejected');
            } else {
                showNotification(data.error || 'Error rejecting request', 'error');
            }
        });
        return false;
    }

    // Helper functions
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showNotification(message, type = 'success') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.classList.add('fade-out');
            setTimeout(() => notification.remove(), 500);
        }, 3000);
    }

    
    const style = document.createElement('style');
    style.textContent = `
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
        .notification.success {
            background: #28a745;
        }
        .notification.error {
            background: #dc3545;
        }
        .fade-out {
            animation: fade-out 0.5s ease-out forwards;
        }
        @keyframes slide-in {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }
        @keyframes fade-out {
            to { opacity: 0; }
        }
    `;
    document.head.appendChild(style);
</script>
</body>
</html>