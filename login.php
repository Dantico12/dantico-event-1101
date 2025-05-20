<?php
session_start();
require_once 'db.php';

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Initialize response array
$response = [
    'status' => '',
    'message' => ''
];

// Handle Registration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register'])) {
    $username = sanitize_input($_POST['username']);
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];

    if (empty($username) || empty($email) || empty($password)) {
        $response['status'] = 'error';
        $response['message'] = 'All fields are required';
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $response['status'] = 'error';
            $response['message'] = 'Username or email already exists';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $email, $hashed_password);

            if ($stmt->execute()) {
                $response['status'] = 'success';
                $response['message'] = 'Registration successful! Redirecting to login...';
            } else {
                $response['status'] = 'error';
                $response['message'] = 'Registration failed. Please try again.';
            }
        }
        $stmt->close();
    }
    
    // Always return JSON for register requests as your JS expects it
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Handle Login
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $response['status'] = 'error';
        $response['message'] = 'Both username and password are required';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                $response['status'] = 'success';
                $response['message'] = 'Login successful! Redirecting...';
                $response['redirect'] = 'index.html'; // Your JavaScript will handle this redirect
            } else {
                $response['status'] = 'error';
                $response['message'] = 'Invalid password';
            }
        } else {
            $response['status'] = 'error';
            $response['message'] = 'User not found';
        }
        $stmt->close();
    }
    
    // Always return JSON for login requests as your JS expects it
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login page</title>
    <link rel="stylesheet" href="style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        .message {
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            text-align: center;
            display: none;
        }
        .error { background-color: rgba(255, 0, 0, 0.1); color: #ff3333; }
        .success { background-color: rgba(0, 255, 0, 0.1); color: #00cc00; }
    </style>
</head>
<body>
    <div class="wrapper">
        <span class="bg-animate"></span>
        <span class="bg-animate2"></span>
        
        <div class="form-box login">
            <h2 class="animation" style="--i:0;">Login</h2>
            <div id="loginMessage" class="message"></div>
            <form id="loginForm" method="post">
                <div class="input-box animation" style="--i:1;">
                    <input type="text" name="username" required>
                    <label>Username</label>
                    <i class='bx bxs-user'></i>
                </div>
                <div class="input-box animation" style="--i:2;">
                    <input type="password" name="password" required>
                    <label>Password</label>
                    <i class='bx bxs-lock-alt'></i>
                </div>
                <button type="submit" name="login" class="btn animation" style="--i:3;">Login</button>
                <div class="logregreg-link animation" style="--i:4;">
                    <p>Do not have an account<a href="#" class="register-link">Sign UP</a></p>
                </div>
            </form>
        </div>

        <div class="info-text login">
            <h2 class="animation" style="--i:0;">Welcome Back</h2>
            <p class="animation" style="--i:1;">Login to access your account.</p>
        </div>

        <div class="form-box register">
            <h2 class="animation" style="--i:17;">Signup</h2>
            <div id="registerMessage" class="message"></div>
            <form id="registerForm" method="post">
                <div class="input-box animation" style="--i:18;">
                    <input type="text" name="username" required>
                    <label>Username</label>
                    <i class='bx bxs-user'></i>
                </div>
                <div class="input-box animation" style="--i:19;">
                    <input type="email" name="email" required>
                    <label>Email</label>
                    <i class='bx bxs-envelope'></i>
                </div>
                <div class="input-box animation" style="--i:20;">
                    <input type="password" name="password" required>
                    <label>Password</label>
                    <i class='bx bxs-lock-alt'></i>
                </div>
                <button type="submit" name="register" class="btn animation" style="--i:21;">Sign Up</button>
                <div class="logregreg-link animation" style="--i:22;">
                    <p>Already have an account<a href="#" class="login-link">Login</a></p>
                </div>
            </form>
        </div>

        <div class="info-text register">
            <h2 class="animation" style="--i:17;">Welcome</h2>
            <p class="animation" style="--i:18;">Create your account to get started.</p>
        </div>
    </div>
    <script src="script.js"></script>
</body>
</html>