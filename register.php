<?php 
session_start();


if(isset($_SESSION["username"])){
    header("Location: chats.php");
    exit();
}

require_once 'database.php';

$error = '';
$success = '';

if($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["submit"])){
    try {
      
        if(empty($_POST["username"]) || empty($_POST["password"]) || empty($_POST["confirm_password"])){
            throw new Exception("All fields are required");
        }

        $username = filter_var($_POST["username"]);
        $pwd = $_POST["password"];
        $cpwd = $_POST["confirm_password"];

     
        if(strlen($username) < 4){
            throw new Exception("Username must be at least 4 characters");
        }

       
        if($pwd !== $cpwd){
            throw new Exception("Passwords do not match");
        }

        if(strlen($pwd) < 8){
            throw new Exception("Password must be at least 8 characters");
        }

       
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if($stmt->rowCount() > 0){
            throw new Exception("Username already taken");
        }

       
        $pwd_hash = password_hash($pwd, PASSWORD_BCRYPT);
        $stmt = $db->prepare("INSERT INTO users (username, user_pwd_hash) VALUES (?, ?)");
        $stmt->execute([$username, $pwd_hash]);

       
        header("Location: login.php?registered=1");
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
    <title>Register</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f4f4f4;
            margin: 0;
        }
        .register-container {
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
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        input {
            width: 100%;
            padding: 12px;
            margin: 8px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        button:hover {
            background: #0069d9;
        }
        .login-link {
            text-align: center;
            margin-top: 15px;
        }
        .login-link a {
            color: #007bff;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <h2>Create Account</h2>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form id="registerForm" action="register.php" method="POST" onsubmit="return validateForm()">
            <input type="text" name="username" placeholder="Username" required
                   value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
            
            <input type="password" name="password" placeholder="Password" required>
            
            <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            
            <button type="submit" name="submit">Register</button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>

    <script>
        function validateForm() {
            const pwd = document.querySelector('input[name="password"]');
            const cpwd = document.querySelector('input[name="confirm_password"]');
            
            if(pwd.value !== cpwd.value) {
                alert('Passwords do not match!');
                cpwd.focus();
                return false;
            }
            
            if(pwd.value.length < 8) {
                alert('Password must be at least 8 characters!');
                pwd.focus();
                return false;
            }
            
            return true;
        }
    </script>
</body>
</html>
