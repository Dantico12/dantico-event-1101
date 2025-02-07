<?php
// Start output buffering immediately
ob_start();

// Enable error reporting but log to file instead of output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'mpesa_error.log');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // Capture any output that might occur during includes
    ob_start();
    require_once 'db.php';
    require_once 'make_contribution.php';
    $include_output = ob_get_clean();
    
    if (!empty($include_output)) {
        error_log("Unexpected output during includes: " . $include_output);
    }

    // Clear all previous output
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Set JSON header
    header('Content-Type: application/json');

    // Validate session and inputs
    if (!isset($_SESSION['current_event_id'])) {
        throw new Exception('Invalid event context');
    }

    if (!isset($_POST['phone_number']) || !isset($_POST['amount'])) {
        throw new Exception('Missing required fields');
    }

    $phone_number = $_POST['phone_number'];
    $amount = floatval($_POST['amount']);
    $event_id = $_SESSION['current_event_id'];
    
    if ($amount <= 0) {
        throw new Exception('Invalid amount');
    }

    // Initialize M-Pesa gateway
    $mpesa = new MpesaGateway($conn);
    
    // Process payment with event-specific paybill
    $result = $mpesa->initiateSTKPush($phone_number, $amount, $event_id);
    
    // Log the result
    error_log("M-Pesa API Response: " . print_r($result, true));

    // Check for specific M-Pesa errors
    if (isset($result['error'])) {
        throw new Exception($result['error']);
    }

    echo json_encode([
        'status' => 'success',
        'data' => $result
    ]);
    exit;

} catch (Exception $e) {
    error_log("Payment Processing Error: " . $e->getMessage());
    
    // Clear any output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
    exit;
}
?>