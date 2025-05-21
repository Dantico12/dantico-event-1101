<?php
session_start([
    'cookie_lifetime' => 86400,
    'cookie_secure' => isset($_SERVER['HTTPS']), // Works for both HTTP and HTTPS
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax', // Balanced security
    'use_strict_mode' => true
]);

require_once 'db.php';

$response = ['status' => '', 'message' => ''];

// Input validation functions
function validate_username($username) {
    $username = trim($username);
    return preg_match('/^[a-zA-Z0-9_\-.]{3,20}$/', $username) ? $username : false;
}

function validate_email($email) {
    $email = trim($email);
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
}

function validate_password($password) {
    return strlen($password) >= 8 ? $password : false;
}

// Handle Registration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register'])) {
    $username = validate_username($_POST['username'] ?? '');
    $email = validate_email($_POST['email'] ?? '');
    $password = validate_password($_POST['password'] ?? '');

    if (!$username || !$email || !$password) {
        $response = ['status' => 'error', 'message' => 'Invalid input data'];
        respond($response);
    }

    // Check if user exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        $response = ['status' => 'error', 'message' => 'Username or email already exists'];
        respond($response);
    }

    // Create account
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $email, $hashed_password);

    if ($stmt->execute()) {
        $response = ['status' => 'success', 'message' => 'Registration successful! Redirecting...'];
    } else {
        $response = ['status' => 'error', 'message' => 'Registration failed. Please try again.'];
    }
    respond($response);
}

// Handle Login with rate limiting
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    // Rate limiting
    $current_time = time();
    if (($_SESSION['login_attempts'] ?? 0) > 5 && 
        $current_time - ($_SESSION['last_attempt'] ?? 0) < 300) {
        $response = ['status' => 'error', 'message' => 'Too many attempts. Try again later.'];
        respond($response);
    }

    $username = validate_username($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || empty($password)) {
        $response = ['status' => 'error', 'message' => 'Invalid credentials'];
        respond($response);
    }

    // Check user
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            // Successful login
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_attempts'] = 0;
            
            $response = [
                'status' => 'success', 
                'message' => 'Login successful!', 
                'redirect' => 'index.html'
            ];
        } else {
            // Failed attempt
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['last_attempt'] = $current_time;
            $response = ['status' => 'error', 'message' => 'Invalid credentials'];
        }
    } else {
        $response = ['status' => 'error', 'message' => 'Invalid credentials'];
    }
    respond($response);
}

function respond($response) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <title>Secure Login System</title>
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
        .password-strength {
            display: flex;
            gap: 2px;
            margin-top: 5px;
            height: 3px;
        }
        .strength-bar {
            flex: 1;
            background: #ddd;
            border-radius: 2px;
        }
        .strength-bar.active { background: #00cc00; }
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
                    ￼
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