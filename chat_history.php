<?php
require 'vendor/autoload.php';
session_start();

// Database connection
$servername = "127.0.0.1";
$username = "root";
$password = "";
$dbname = "event";

function getConnection() {
    global $servername, $username, $password, $dbname;
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        die(json_encode(['success' => false, 'message' => 'Connection failed: ' . $conn->connect_error]));
    }
    
    return $conn;
}

// Handle POST request for storing messages
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $conn = getConnection();
    
    // Validate event_id exists and matches the session
    if (!isset($input['event_id']) || !isset($_SESSION['current_event_id']) || 
        $input['event_id'] != $_SESSION['current_event_id']) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid event ID or unauthorized access'
        ]);
        exit;
    }
    
    $stmt = $conn->prepare("
        INSERT INTO messages (event_id, sender_id, message, sent_at, message_type)
        VALUES (?, ?, ?, NOW(), 'text')
    ");
    
    $stmt->bind_param("iis", 
        $input['event_id'],
        $_SESSION['user_id'],
        $input['message']
    );
    
    $success = $stmt->execute();
    $newMessageId = $stmt->insert_id;
    
    if ($success) {
        // Fetch the inserted message with user details
        $stmt = $conn->prepare("
            SELECT m.*, u.username
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ? AND m.event_id = ?
        ");
        $stmt->bind_param("ii", $newMessageId, $input['event_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $message = $result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'message' => $message
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to store message'
        ]);
    }
    
    $stmt->close();
    $conn->close();
    exit;
}

// Handle GET request for fetching messages
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;

if ($eventId > 0) {
    $conn = getConnection();
    
    // Validate user has access to this event
    if (!isset($_SESSION['current_event_id']) || $eventId != $_SESSION['current_event_id']) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
        exit;
    }
    
    // Base query for fetching messages
    $query = "
        SELECT 
            m.id,
            m.sender_id,
            m.message,
            m.sent_at,
            m.message_type,
            u.username
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.event_id = ?
    ";
    
    // Add last_id condition if provided
    if ($lastId > 0) {
        $query .= " AND m.id > ?";
    }
    
    $query .= " ORDER BY m.sent_at ASC";
    
    $stmt = $conn->prepare($query);
    
    if ($lastId > 0) {
        $stmt->bind_param("ii", $eventId, $lastId);
    } else {
        $stmt->bind_param("i", $eventId);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = [];
    
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'id' => $row['id'],
            'sender_id' => $row['sender_id'],
            'message' => $row['message'],
            'sent_at' => $row['sent_at'],
            'message_type' => $row['message_type'],
            'username' => $row['username']
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true, 
        'messages' => $messages,
        'event_id' => $eventId
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid event ID']);
}
?>