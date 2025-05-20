<?php
session_start();
require_once 'db.php';
// Timezone setting
date_default_timezone_set('Africa/Nairobi');

// Function to fetch user roles from database
function getUserRoles($conn, $user_id, $event_id) {
    $sql = "SELECT em.role, em.committee_role 
            FROM event_members em
            WHERE em.user_id = ? AND em.event_id = ? AND em.status = 'active'";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $event_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Function to check user's role access
function hasAccess($required_roles, $user_role, $committee_role) {
    if ($user_role === 'admin' || $user_role === 'chairman') {
        return true;
    }
    $required_roles = array_map('strtolower', $required_roles);
    if ($user_role === 'member' && !empty($committee_role)) {
        $committee_role = strtolower($committee_role);
        return in_array($committee_role, $required_roles);
    }
    if ($user_role === 'member' && empty($committee_role)) {
        return in_array('member', $required_roles);
    }
    return false;
}

// Initialize user roles
$user_role = '';
$committee_role = '';
if (isset($_SESSION['user_id']) && isset($_SESSION['current_event_id'])) {
    $user_roles = getUserRoles($conn, $_SESSION['user_id'], $_SESSION['current_event_id']);
    $user_role = $user_roles['role'] ?? '';
    $committee_role = $user_roles['committee_role'] ?? '';
    
    // Store in session for later use
    $_SESSION['user_role'] = $user_role;
    $_SESSION['committee_role'] = $committee_role;
} else {
    // Fallback to session values if they exist
    $user_role = $_SESSION['user_role'] ?? '';
    $committee_role = $_SESSION['committee_role'] ?? '';
}

// Check if user has access to view this page
$required_roles = ['Admin', 'Organizer', 'Member', 'Chairman', 'Secretary', 'Treasurer'];
if (!hasAccess($required_roles, $user_role, $committee_role)) {
    header("Location: access-denied.php");
    exit();
}

// Define permission for meeting management
$canManageMeetings = hasAccess(['Admin', 'Organizer', 'Secretary'], $user_role, $committee_role);

// Handle meeting join request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'join_meeting') {
        $meeting_id = $_POST['meeting_id'] ?? '';
        
        if ($meeting_id) {
            $redirect_url = 'video-conference.php?' . http_build_query([
                'event_id' => $_SESSION['current_event_id'],
                'event_code' => $_SESSION['current_event_code'],
                'meeting_id' => $meeting_id
            ]);
            
            header("Location: " . $redirect_url);
            exit;
        }
    }
    
    // Handle meeting management actions if user has permission
    if ($canManageMeetings) {
        if ($_POST['action'] === 'delete_meeting') {
            $meeting_id = $_POST['meeting_id'] ?? '';
            if ($meeting_id) {
                $stmt = $conn->prepare("DELETE FROM meetings WHERE id = ? AND event_id = ?");
                $stmt->bind_param("ii", $meeting_id, $_SESSION['current_event_id']);
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Meeting deleted successfully";
                } else {
                    $_SESSION['error_message'] = "Failed to delete meeting";
                }
                header("Location: meetings.php" . getEventContextURL());
                exit;
            }
        }
    }
}

// Validate event session
if (!isset($_SESSION['current_event_id']) || !isset($_SESSION['current_event_code'])) {
    header("Location: events.php");
    exit;
}

$current_event_id = $_SESSION['current_event_id'];
$current_event_code = $_SESSION['current_event_code'];

// Function to generate base URL with event context
function getEventContextURL($additional_params = []) {
    $base_params = [
        'event_id' => $_SESSION['current_event_id'] ?? '',
        'event_code' => $_SESSION['current_event_code'] ?? ''
    ];
    
    $params = array_merge($base_params, $additional_params);
    return '?' . http_build_query($params);
}

// Get event details
function getEventDetails($conn, $event_id) {
    $stmt = $conn->prepare("SELECT event_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getMeetingStatus($meeting_date, $start_time, $end_time) {
    try {
        $timezone = new DateTimeZone('Africa/Nairobi');
        $current_time = new DateTime('now', $timezone);
        
        $start_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $meeting_date . ' ' . $start_time, $timezone);
        $end_datetime = DateTime::createFromFormat('Y-m-d H:i:s', $meeting_date . ' ' . $end_time, $timezone);
        
        if (!$start_datetime || !$end_datetime) {
            error_log("Invalid datetime format - Date: $meeting_date, Start: $start_time, End: $end_time");
            throw new Exception("Invalid datetime format");
        }

        $current = $current_time->getTimestamp();
        $start = $start_datetime->getTimestamp();
        $end = $end_datetime->getTimestamp();

        if ($current < $start) {
            return [
                'status' => 'scheduled',
                'class' => 'scheduled-status',
                'can_join' => false,
                'formatted_start' => $start_datetime->format('c'),
                'formatted_end' => $end_datetime->format('c')
            ];
        } elseif ($current >= $start && $current <= $end) {
            return [
                'status' => 'in progress',
                'class' => 'in-progress-status',
                'can_join' => true,
                'formatted_start' => $start_datetime->format('c'),
                'formatted_end' => $end_datetime->format('c')
            ];
        } else {
            return [
                'status' => 'ended',
                'class' => 'ended-status',
                'can_join' => false,
                'formatted_start' => $start_datetime->format('c'),
                'formatted_end' => $end_datetime->format('c')
            ];
        }
    } catch (Exception $e) {
        error_log("Meeting status error: " . $e->getMessage());
        return [
            'status' => 'error',
            'class' => 'error-status',
            'can_join' => false,
            'formatted_start' => null,
            'formatted_end' => null
        ];
    }
}

function getMeetings($conn, $event_id) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                m.*,
                DATE_FORMAT(m.meeting_date, '%Y-%m-%d') as formatted_date,
                TIME_FORMAT(m.meeting_time, '%H:%i:%s') as formatted_start_time,
                TIME_FORMAT(m.end_time, '%H:%i:%s') as formatted_end_time
            FROM meetings m
            WHERE m.event_id = ?
            ORDER BY m.meeting_date DESC, m.meeting_time DESC
        ");
        
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        $stmt->bind_param("i", $event_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching meetings: " . $e->getMessage());
        return [];
    }
}

// Initialize variables
$base_url = getEventContextURL();
$event = getEventDetails($conn, $current_event_id);
$meetings = getMeetings($conn, $current_event_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management Dashboard</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'poppins', sans-serif;
        }

        :root {
            --sidebar-width: 260px;
            --collapsed-width: 60px;
            --header-height: 60px;
        }

        body {
            min-height: 100vh;
            background: #081b29;
            overflow-x: hidden;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: var(--sidebar-width);
            background: rgba(8, 27, 41, 0.9);
            border-right: 2px solid #0ef;
            transition: all 0.5s ease;
            z-index: 100;
        }

        .sidebar.collapse {
            width: var(--collapsed-width);
        }

        .sidebar-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 15px;
            border-bottom: 2px solid #0ef;
        }

        .sidebar-header h2 {
            color: #fff;
            font-size: 20px;
            margin-left: 15px;
            white-space: nowrap;
            transition: all 0.5s ease;
        }

        .sidebar.collapse .sidebar-header h2 {
            opacity: 0;
        }

        .sidebar-menu {
            padding: 10px 0;
            height: calc(100% - var(--header-height));
            overflow-y: auto;
        }
                 /* Hide sidebar by default for unauthorized users */
       .sidebar.hidden {
        display: none;
             }

           /* Show sidebar for authorized users */
       .sidebar.visible {
          display: block;
         }


        .menu-category {
            margin: 10px 0;
        }

        .category-title {
            color: #0ef;
            font-size: 12px;
            text-transform: uppercase;
            padding: 10px 20px;
            letter-spacing: 1px;
            opacity: 0.7;
        }

        .sidebar.collapse .category-title {
            opacity: 0;
        }

        .menu-item {
            padding: 12px 20px 12px 30px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .menu-item a {
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .menu-item:hover {
            background: rgba(0, 238, 255, 0.1);
        }

        .menu-item.active {
            background: rgba(0, 238, 255, 0.15);
        }

        .menu-item i {
            font-size: 24px;
            min-width: 40px;
            color: #0ef;
            transition: all 0.3s ease;
        }

        .menu-item span {
            color: #fff;
            white-space: nowrap;
            transition: all 0.3s ease;
            margin-left: 10px;
        }

        .sidebar.collapse .menu-item span {
            opacity: 0;
        }

        .menu-item::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 2px;
            height: 100%;
            background: #0ef;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .menu-item:hover::after,
        .menu-item.active::after {
            opacity: 1;
        }

        .main-content {
            position: relative;
            left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            min-height: 100vh;
            transition: all 0.5s ease;
            padding: 20px;
        }

        .main-content.expand {
            left: var(--collapsed-width);
            width: calc(100% - var(--collapsed-width));
        }

        .header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 20px;
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .toggle-btn {
            font-size: 24px;
            color: #0ef;
            cursor: pointer;
            background: none;
            border: none;
            outline: none;
        }

        .header-title {
            color: #fff;
            margin-left: 20px;
            font-size: 20px;
        }

        .header-actions {
            margin-left: auto;
            display: flex;
            gap: 15px;
        }

        .header-actions i {
            font-size: 24px;
            color: #0ef;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .header-actions i:hover {
            transform: scale(1.1);
        }

        .content-section {
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            box-shadow: 0 0 15px rgba(0, 238, 255, 0.3);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            min-height: 300px;
        }

        .notification-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #0ef;
            color: #081b29;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Scrollbar Styling */
        .sidebar-menu::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-menu::-webkit-scrollbar-track {
            background: rgba(8, 27, 41, 0.9);
        }

        .sidebar-menu::-webkit-scrollbar-thumb {
            background: #0ef;
            border-radius: 5px;
        }

        /* Hover effects for interactive elements */
        .header-actions i:hover,
        .toggle-btn:hover {
            color: #fff;
        }
        .meetings-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            color: #fff;
        }

        .meetings-table th,
        .meetings-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 238, 255, 0.2);
        }

        .meetings-table th {
            background: rgba(0, 238, 255, 0.1);
            color: #0ef;
            font-weight: 500;
        }

        .meetings-table tr:hover {
            background: rgba(0, 238, 255, 0.05);
        }

        .join-btn {
            padding: 8px 16px;
            background: #0ef;
            color: #081b29;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
        }

        .join-btn:hover {
            background: #fff;
            transform: translateY(-2px);
        }

        .join-btn.disabled {
            background-color: #4a4a4a;
            cursor: not-allowed;
            opacity: 0.6;
            pointer-events: none;
            transform: none;
        }

        .countdown {
            color: #00C851;
            font-size: 0.9em;
            display: block;
            margin-top: 5px;
        }

        .ended-status {
            color: #ff4444;
            font-style: italic;
        }

        .scheduled-status {
            color: #00C851;
        }

        .in-progress-status {
            color: #ffa500;
            font-weight: bold;
        }

        .meeting-time {
            white-space: nowrap;
        }
        .meeting-action {
            position: relative;
        }
        
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }
        
        .status-scheduled .status-indicator {
            background-color: #00C851;
        }
        
        .status-in-progress .status-indicator {
            background-color: #ffa500;
        }
        
        .status-ended .status-indicator {
            background-color: #ff4444;
        }
        
        .join-btn {
            position: relative;
            overflow: hidden;
        }
        
        .join-btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%);
            transform-origin: 50% 50%;
        }
        
        .join-btn:focus:not(:active)::after {
            animation: ripple 1s ease-out;
        }
        .status-indicator {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}

.status-scheduled-status .status-indicator {
    background-color: #00C851;
}

.status-in-progress-status .status-indicator {
    background-color: #ffa500;
    animation: pulse 2s infinite;
}

.status-ended-status .status-indicator {
    background-color: #ff4444;
}

.join-btn {
    padding: 8px 16px;
    background: #0ef;
    color: #081b29;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
    font-weight: 500;
}

.join-btn:hover {
    background: #fff;
    transform: translateY(-2px);
}

.join-btn.disabled {
    background-color: #4a4a4a;
    cursor: not-allowed;
    opacity: 0.6;
    pointer-events: none;
}

.countdown {
    color: #00C851;
    font-size: 0.9em;
    display: block;
    margin-top: 5px;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}
        
        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(20, 20);
                opacity: 0;
            }
        }
    </style>
</head>
<body>
<nav class="sidebar" <?= !hasAccess(['Treasurer', 'Secretary', 'Chairman', 'Admin', 'member'], $user_role, $committee_role) ? 'style="display: none;"' : '' ?>>
    <div class="sidebar-header">
        <i class='bx bx-calendar-event' style="color: #0ef; font-size: 24px;"></i>
        <h2>Dantico Events</h2>
    </div>
    <div class="sidebar-menu">
        <!-- Dashboard (accessible to all) -->
        <div class="menu-category">
            <div class="menu-item active">
                <a href="./dashboard.php<?= $base_url ?>">
                    <i class='bx bx-home-alt'></i>
                    <span>Dashboard</span>
                </a>
            </div>
        </div>

        <!-- Paybill Section (Visible only to Treasurer and Admin) -->
        <?php if (hasAccess(['Treasurer', 'Admin'], $user_role, $committee_role)): ?>
        <div class="menu-category">
            <div class="category-title">Paybill</div>
            <div class="menu-item">
                <a href="./paybill.php<?= $base_url ?>">
                    <i class='bx bx-plus-circle'></i>
                    <span>Add Paybill</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Committees Section -->
        <div class="menu-category">
            <div class="menu-item">
                <a href="./committee-list.php<?= $base_url ?>">
                    <i class='bx bx-group'></i>
                    <span>Committee List</span>
                </a>
            </div>
        </div>

        <!-- Minutes Section (Visible only to Secretary) -->
        <?php if (hasAccess(['Secretary'], $user_role, $committee_role)): ?>
        <div class="menu-category">
            <div class="category-title">Reviews</div>
            <div class="menu-item">
                <a href="./minutes.php<?= $base_url ?>">
                    <i class='bx bxs-timer'></i>
                    <span>Minutes</span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Communication Section (accessible to all) -->
        <div class="menu-category">
            <div class="category-title">Communication</div>
            <div class="menu-item">
                <a href="./chat.php<?= $base_url ?>">
                    <i class='bx bx-message-rounded-dots'></i>
                    <span>Chat System</span>
                    <div class="notification-badge">3</div>
                </a>
            </div>
            <div class="menu-item">
                <a href="./video-conference.php<?= $base_url ?>">
                    <i class='bx bx-video'></i>
                    <span>Video Conference</span>
                </a>
            </div>
        </div>

        <!-- Contributions Section (accessible to all) -->
        <div class="menu-category">
            <div class="category-title">Contributions</div>
            <div class="menu-item">
                <a href="./make_contribution.php<?= $base_url ?>">
                    <i class='bx bx-plus-circle'></i>
                    <span>Make Contributions</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./contributions.php<?= $base_url ?>">
                    <i class='bx bx-money'></i>
                    <span>Contributions</span>
                </a>
            </div>
        </div>

        <!-- Tasks Section (accessible to all) -->
        <div class="menu-category">
            <div class="category-title">Tasks</div>
            <div class="menu-item">
                <a href="./tasks.php<?= $base_url ?>">
                    <i class='bx bx-task'></i>
                    <span>Tasks</span>
                </a>
            </div>
        </div>

        <!-- Schedule Section (accessible to all) -->
        <div class="menu-category">
            <div class="category-title">Tools</div>
            <div class="menu-item">
                <a href="./schedule.php<?= $base_url ?>">
                    <i class='bx bx-calendar'></i>
                    <span>Schedule</span>
                </a>
            </div>
        </div>
    </div>
</nav>

<div class="main-content">
    <div class="header">
        <button class="toggle-btn">
            <i class='bx bx-menu'></i>
        </button>
        <h2 class="header-title">Scheduled Meetings</h2>
        <div class="header-actions">
            <i class='bx bx-search'></i>
            <i class='bx bx-bell'></i>
            <i class='bx bx-user-circle'></i>
        </div>
    </div>

    <div class="content-section">
        <table class="meetings-table">
            <thead>
                <tr>
                    <th>Meeting Type</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($meetings): ?>
                    <?php foreach ($meetings as $meeting): ?>
                        <?php 
                        $meeting_status = getMeetingStatus(
                            $meeting['formatted_date'],
                            $meeting['formatted_start_time'],
                            $meeting['formatted_end_time']
                        );
                        ?>
                        <tr class="meeting-row">
                            <td><?php echo htmlspecialchars(ucfirst($meeting['meeting_type'])); ?></td>
                            <td><?php echo date('M d, Y', strtotime($meeting['formatted_date'])); ?></td>
                            <td class="meeting-time">
                                <?php 
                                echo date('h:i A', strtotime($meeting['formatted_start_time'])) . ' - ' . 
                                     date('h:i A', strtotime($meeting['formatted_end_time'])); 
                                ?>
                            </td>
                            <td>
                                <div class="status-<?php echo $meeting_status['class']; ?>">
                                    <span class="status-indicator"></span>
                                    <span class="status-text">
                                        <?php echo ucfirst($meeting_status['status']); ?>
                                    </span>
                                </div>
                            </td>
                            <td><?php echo date('M d, Y h:i A', strtotime($meeting['created_at'])); ?></td>
                            <td class="meeting-action">
                                <?php if ($meeting_status['status'] === 'scheduled'): ?>
                                    <button type="button" class="join-btn disabled">Join Meeting</button>
                                    <span class="countdown"></span>
                                <?php elseif ($meeting_status['status'] === 'in progress'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                        <input type="hidden" name="action" value="join_meeting">
                                        <button type="submit" class="join-btn">Join Now</button>
                                    </form>
                                <?php else: ?>
                                    <span class="ended-status">
                                        Meeting Ended on <?php echo date('M d, Y', strtotime($meeting['formatted_date'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="no-meetings">No meetings scheduled</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    updateMeetingStatusesAndButtons();
    setInterval(updateMeetingStatusesAndButtons, 1000);
});

function updateMeetingStatusesAndButtons() {
    const meetingRows = document.querySelectorAll('.meeting-row');
    
    meetingRows.forEach(row => {
        try {
            const dateStr = row.children[1].textContent.trim();
            const timeRange = row.children[2].textContent.trim();
            const actionCell = row.querySelector('.meeting-action');

            const [startTimeStr, endTimeStr] = timeRange.split(' - ').map(t => t.trim());
            const startDateTime = parseDateTime(dateStr, startTimeStr);
            const endDateTime = parseDateTime(dateStr, endTimeStr);
            const now = new Date();

            const timeToStart = startDateTime - now;
            const timeToEnd = endDateTime - now;

            const statusCell = row.querySelector('.status-text');
            const statusIndicator = row.querySelector('.status-indicator');
            const joinBtn = actionCell.querySelector('.join-btn');
            const countdownSpan = actionCell.querySelector('.countdown');

            if (timeToStart > 0) {
                handleFutureMeeting(joinBtn, countdownSpan, timeToStart);
                updateStatus(statusCell, statusIndicator, 'scheduled', 'Scheduled');
            } 
            else if (timeToEnd > 0) {
                handleInProgressMeeting(actionCell, row.dataset.meetingId);
                updateStatus(statusCell, statusIndicator, 'in-progress', 'In Progress');
            } 
            else {
                handleEndedMeeting(actionCell, endDateTime);
                updateStatus(statusCell, statusIndicator, 'ended', 'Ended');
            }
        } catch (error) {
            console.error('Error processing meeting row:', error);
        }
    });
}

function parseDateTime(dateStr, timeStr) {
    const dateMatch = dateStr.match(/([A-Za-z]+)\s+(\d+),\s+(\d{4})/);
    const timeMatch = timeStr.match(/(\d+):(\d+)\s+(AM|PM)/i);

    if (!dateMatch || !timeMatch) {
        throw new Error(`Invalid date/time format: ${dateStr} ${timeStr}`);
    }

    const [_, month, day, year] = dateMatch;
    const [__, hours, minutes, period] = timeMatch;

    const date = new Date();
    date.setFullYear(parseInt(year));
    date.setMonth(getMonthNumber(month));
    date.setDate(parseInt(day));

    let hour = parseInt(hours);
    if (period.toUpperCase() === 'PM' && hour !== 12) hour += 12;
    if (period.toUpperCase() === 'AM' && hour === 12) hour = 0;

    date.setHours(hour, parseInt(minutes), 0, 0);
    return date;
}

function getMonthNumber(monthStr) {
    const months = {
        'Jan': 0, 'Feb': 1, 'Mar': 2, 'Apr': 3, 'May': 4, 'Jun': 5,
        'Jul': 6, 'Aug': 7, 'Sep': 8, 'Oct': 9, 'Nov': 10, 'Dec': 11
    };
    return months[monthStr.substring(0, 3)];
}

function handleFutureMeeting(joinBtn, countdownSpan, timeToStart) {
    if (joinBtn) {
        joinBtn.classList.add('disabled');
        joinBtn.textContent = 'Join Meeting';
        if (countdownSpan) {
            updateCountdown(timeToStart, countdownSpan);
            countdownSpan.style.display = 'block';
        }
    }
}

function handleInProgressMeeting(actionCell, meetingId) {
    actionCell.innerHTML = `
        <form method="POST" style="display: inline;">
            <input type="hidden" name="meeting_id" value="${meetingId}">
            <input type="hidden" name="action" value="join_meeting">
            <button type="submit" class="join-btn">Join Now</button>
        </form>
    `;
}

function handleEndedMeeting(actionCell, endDateTime) {
    const formattedDateTime = endDateTime.toLocaleString('en-US', {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
    actionCell.innerHTML = `<span class="ended-status">Meeting Ended on ${formattedDateTime}</span>`;
}

function updateStatus(statusCell, statusIndicator, className, text) {
    if (statusCell && statusIndicator) {
        statusCell.textContent = text;
        const parentDiv = statusIndicator.closest('div');
        if (parentDiv) {
            parentDiv.className = `status-${className}`;
        }
    }
}

function updateCountdown(timeLeft, countdownElement) {
    if (timeLeft <= 0) {
        location.reload();
        return;
    }

    const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
    const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);

    let countdownText = 'Starting in: ';
    const timeUnits = [];

    if (days > 0) timeUnits.push(`${days}d`);
    if (hours > 0 || days > 0) timeUnits.push(`${hours}h`);
    if (minutes > 0 || hours > 0 || days > 0) timeUnits.push(`${minutes}m`);
    timeUnits.push(`${seconds}s`);

    countdownText += timeUnits.join(' ');
    countdownElement.textContent = countdownText;
}
</script>

</body>
</html>