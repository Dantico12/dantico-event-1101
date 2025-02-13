<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

// Get current event ID from session
$current_event_id = $_SESSION['current_event_id'] ?? null;

if (!$current_event_id) {
    echo json_encode(['status' => 'error', 'message' => 'No event selected']);
    exit;
}

// Get current timestamp in event's timezone
date_default_timezone_set('Africa/Nairobi');
$current_time = date('Y-m-d H:i:s');

// Query to get current or next meeting
$query = "SELECT 
    id,
    meeting_type,
    meeting_date,
    meeting_time,
    end_time,
    CONCAT(meeting_date, ' ', meeting_time) as start_datetime,
    CONCAT(meeting_date, ' ', end_time) as end_datetime
FROM meetings 
WHERE event_id = ? 
    AND CONCAT(meeting_date, ' ', end_time) >= ?
ORDER BY meeting_date ASC, meeting_time ASC 
LIMIT 1";

$stmt = $conn->prepare($query);
$stmt->bind_param('is', $current_event_id, $current_time);
$stmt->execute();
$result = $stmt->get_result();
$meeting = $result->fetch_assoc();

if (!$meeting) {
    echo json_encode(['status' => 'no_meeting', 'message' => 'No upcoming meetings']);
    exit;
}

// Check meeting status
$start_datetime = strtotime($meeting['start_datetime']);
$end_datetime = strtotime($meeting['end_datetime']);
$current = strtotime($current_time);

$response = [
    'meeting_id' => $meeting['id'],
    'meeting_type' => $meeting['meeting_type']
];

if ($current < $start_datetime) {
    $response['status'] = 'scheduled';
    $response['start_time'] = $meeting['start_datetime'];
} elseif ($current >= $start_datetime && $current <= $end_datetime) {
    $response['status'] = 'in_progress';
} else {
    $response['status'] = 'ended';
}

echo json_encode($response);
?>