<?php
// Start output buffering immediately
ob_start();

// Enable error reporting but log to file instead of output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'mpesa_error.log');

// Ensure proper response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Verify that this is an AJAX request
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        throw new Exception('Invalid request method');
    }

    // Include required files
    if (!file_exists('db.php') || !file_exists('make_contribution.php')) {
        throw new Exception('Required files not found');
    }
    
    require_once 'db.php';
    require_once 'make_contribution.php';

    // Validate session and inputs
    if (!isset($_SESSION['current_event_id']) || !isset($_SESSION['user_id'])) {
        throw new Exception('Session expired or invalid. Please refresh the page and try again.');
    }

    if (!isset($_POST['phone_number']) || !isset($_POST['amount'])) {
        throw new Exception('Missing required fields. Please fill in all required information.');
    }

    // Sanitize and validate inputs
    $phone_number = filter_var($_POST['phone_number'], FILTER_SANITIZE_STRING);
    $amount = filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT);
    $event_id = filter_var($_SESSION['current_event_id'], FILTER_VALIDATE_INT);
    $user_id = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);

    if (!$phone_number || !preg_match('/^(07|01)[0-9]{8}$/', $phone_number)) {
        throw new Exception('Invalid phone number format');
    }

    if (!$amount || $amount <= 0) {
        throw new Exception('Invalid amount. Please enter a valid amount.');
    }

    if (!$event_id || !$user_id) {
        throw new Exception('Invalid session data. Please refresh and try again.');
    }

    // Initialize M-Pesa gateway
    $mpesa = new MpesaGateway($conn);

    // Process payment
    $result = $mpesa->initiateSTKPush($phone_number, $amount, $event_id, $user_id);

    if (isset($result['error'])) {
        throw new Exception($result['error']);
    }

    // Clean output buffer before sending response
    ob_clean();

    echo json_encode([
        'status' => 'success',
        'data' => $result,
        'message' => 'STK push sent successfully'
    ]);

} catch (Exception $e) {
    // Log error
    error_log("Payment Processing Error: " . $e->getMessage());
    
    // Clean output buffer before sending error response
    ob_clean();

    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}

// End output buffering and send response
ob_end_flush();
?>