<?php
// Suppress all PHP errors to prevent HTML output
error_reporting(0);
@ini_set('display_errors', 0);

session_start();

// Ensure clean output
ob_clean();
header('Content-Type: application/json');

// Validate requirements
if (!isset($_SESSION['current_event_id']) || !isset($_SESSION['current_event_code'])) {
    die(json_encode(['error' => 'Invalid event context']));
}

// Direct error handling
try {
    // Verify database and gateway class
    require_once 'db.php';
    require_once 'make_contribution.php';

    // Strict input validation
    $phone_number = filter_input(INPUT_POST, 'phone_number', FILTER_SANITIZE_STRING);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $event_id = $_SESSION['current_event_id'];

    if (!$phone_number || !$amount) {
        throw new Exception('Invalid input');
    }

    // Process payment
    $mpesa = new MpesaGateway($conn);
    $result = $mpesa->initiateSTKPush($phone_number, $amount, $event_id);

    echo json_encode($result);
    exit;

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
?>