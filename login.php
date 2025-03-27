<?php
session_start();

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
if(isset($_SESSION["username"])){
    header("Location: chats.php");
    exit();
}

require_once 'database.php';

$error = '';

if($_SERVER['REQUEST_METHOD'] === "POST" && isset($_POST['submit'])){
    try {
        
        if(empty($_POST['username']) || empty($_POST['password'])){
            throw new Exception("Both username and password are required");
        }

        $username = filter_var($_POST['username']);
        $password = $_POST['password'];

        $stmt = $db->prepare("SELECT id, username, user_pwd_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        
        if($stmt->rowCount() === 0){
            throw new Exception("Invalid username or password");
        }

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

       
        if(!password_verify($password, $user['user_pwd_hash'])){
            throw new Exception("Invalid username or password");
        }

        
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_id'] = $user['id'];
        header("Location: chats.php");
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-container {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            width: 320px;
        }
        .alert {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .input-group {
            margin-bottom: 15px;
        }
        .input-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .input-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .login-btn {
            width: 100%;
            padding: 12px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        .login-btn:hover {
            background-color: #218838;
        }
        .register-link {
            text-align: center;
            margin-top: 15px;
        }
        .register-link a {
            color: #007bff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Login to Your Account</h2>
        
        <?php if($error): ?>
            <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="input-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required
                       value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
            </div>
            
            <div class="input-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit" name="submit" class="login-btn">Login</button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
    </div>
</body>
</html>