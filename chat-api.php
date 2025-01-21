<?php
// Add these at the top of your chat-api.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers to allow AJAX requests
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
require 'db.php';

// Get all messages for an event
function getChatHistory($event_id, $limit = 50) {
    global $conn;
    
    $stmt = $conn->prepare("
        SELECT 
            cm.*,
            u.name as sender_name,
            u.profile_image
        FROM chat_messages cm
        JOIN users u ON cm.sender_id = u.id
        WHERE cm.event_id = ?
        ORDER BY cm.created_at DESC
        LIMIT ?
    ");
    
    $stmt->bind_param("ii", $event_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'id' => $row['id'],
            'sender_id' => $row['sender_id'],
            'sender_name' => $row['sender_name'],
            'profile_image' => $row['profile_image'],
            'message' => $row['message'],
            'sent_at' => date('h:i A', strtotime($row['created_at'])),
        ];
    }
    
    return array_reverse($messages);
}

// Get all users in an event
function getEventParticipants($event_id) {
    global $conn;
    
    // Modified query to properly join users and event_members tables
    $stmt = $conn->prepare("
        SELECT DISTINCT
            u.id,
            u.username as name,  // Changed from u.name to u.username since that's your column name
            COALESCE(u.profile_image, '/default-profile.jpg') as profile_image,
            CASE 
                WHEN u.last_login > DATE_SUB(NOW(), INTERVAL 5 MINUTE) 
                THEN 'Online' 
                ELSE 'Offline' 
            END as status,
            em.role,
            em.committee_role
        FROM users u
        INNER JOIN event_members em ON u.id = em.user_id
        WHERE em.event_id = ? 
        AND em.status = 'active'
        ORDER BY u.username ASC
    ");

    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }

    $stmt->bind_param("i", $event_id);
    
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return false;
    }
    
    $result = $stmt->get_result();
    $participants = [];
    
    while ($row = $result->fetch_assoc()) {
        $participants[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'profile_image' => $row['profile_image'],
            'status' => $row['status'],
            'role' => $row['role'],
            'committee_role' => $row['committee_role']
        ];
    }
    
    $stmt->close();
    return $participants;
}
// Update user's last active timestamp
function updateUserActivity($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("
        UPDATE users 
        SET last_active = NOW() 
        WHERE id = ?
    ");
    
    $stmt->bind_param("i", $user_id);
    return $stmt->execute();
}

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_history':
            if (isset($_GET['event_id'])) {
                $messages = getChatHistory($_GET['event_id']);
                echo json_encode(['success' => true, 'messages' => $messages]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Event ID required']);
            }
            break;
            
            case 'get_participants':
                if (!isset($_GET['event_id'])) {
                    echo json_encode([
                        'success' => false, 
                        'error' => 'Event ID required'
                    ]);
                    break;
                }
                
                try {
                    $event_id = (int)$_GET['event_id'];
                    $participants = getEventParticipants($event_id);
                    
                    if ($participants === false) {
                        throw new Exception("Failed to fetch participants");
                    }
                    
                    echo json_encode([
                        'success' => true, 
                        'participants' => $participants
                    ]);
                    
                } catch (Exception $e) {
                    error_log("Error in get_participants: " . $e->getMessage());
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Server error while fetching participants'
                    ]);
                }
                break;
            
        case 'update_activity':
            if (isset($_GET['user_id'])) {
                $success = updateUserActivity($_GET['user_id']);
                echo json_encode(['success' => $success]);
            } else {
                echo json_encode(['success' => false, 'error' => 'User ID required']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    exit;
}