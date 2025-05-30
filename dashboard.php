<?php
session_start();

// Include database connection
require 'db.php';

// Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) . 
                    '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
}

// Get the event context URL
$base_url = getEventContextURL();

// Fetch user roles and event context
function getUserRoles($conn, $user_id, $event_id) {
    $sql = "SELECT em.role, em.committee_role, e.* 
            FROM event_members em
            JOIN events e ON em.event_id = e.id 
            WHERE em.user_id = ? AND em.event_id = ? AND em.status = 'active'";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $event_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Function to check user's role access
function hasAccess($required_roles, $user_role, $committee_role) {
    if ($user_role === 'admin' || $user_role === 'organizer') {
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

// User ID from session
$user_id = $_SESSION['user_id'];
// Fetch username
$username_sql = "SELECT username FROM users WHERE id = ?";
$username_stmt = $conn->prepare($username_sql);
$username_stmt->bind_param("i", $user_id);
$username_stmt->execute();
$username_result = $username_stmt->get_result();
$user_data = $username_result->fetch_assoc();
$username = $user_data['username'] ?? 'User';
$username_stmt->close();

// Handle event_id from URL
if (isset($_GET['event_id'])) {
    $_SESSION['current_event_id'] = intval($_GET['event_id']);
    
    if (!isset($_GET['event_code'])) {
        $code_stmt = $conn->prepare("SELECT event_code FROM events WHERE id = ?");
        $code_stmt->bind_param("i", $_SESSION['current_event_id']);
        $code_stmt->execute();
        $result = $code_stmt->get_result();
        if ($event_data = $result->fetch_assoc()) {
            $_SESSION['current_event_code'] = $event_data['event_code'];
        }
        $code_stmt->close();
    } else {
        $_SESSION['current_event_code'] = htmlspecialchars($_GET['event_code']);
    }
}

// Retrieve current event details
$event_id = $_SESSION['current_event_id'] ?? null;
$event_code = $_SESSION['current_event_code'] ?? null;

// Update user's online status
$update_stmt = $conn->prepare("UPDATE users SET 
    last_login = NOW(), 
    last_active = NOW(), 
    online_status = 'online' 
    WHERE id = ?");
$update_stmt->bind_param("i", $user_id);
$update_stmt->execute();
$update_stmt->close();

try {
    // Get user's roles for the current event
    $user_roles_sql = "SELECT em.role, em.committee_role, e.* 
                      FROM event_members em
                      JOIN events e ON em.event_id = e.id 
                      WHERE em.event_id = ? AND em.user_id = ? 
                      AND em.status = 'active'";
    $roles_stmt = $conn->prepare($user_roles_sql);
    $roles_stmt->bind_param("ii", $event_id, $user_id);
    $roles_stmt->execute();
    $result = $roles_stmt->get_result();
    $user_event_info = $result->fetch_assoc();
    
    $user_role = $user_event_info['role'] ?? '';
    $committee_role = $user_event_info['committee_role'] ?? '';
    
    // Get all events user is member of
    $member_events_sql = "SELECT e.* FROM events e 
                         JOIN event_members em ON e.id = em.event_id 
                         WHERE em.user_id = ? AND em.status = 'active'
                         ORDER BY e.created_at DESC";
    
    $stmt = $conn->prepare($member_events_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $events = $result->fetch_all(MYSQLI_ASSOC);
    
    // Handle specific event access
    if ($event_id) {
        $access_sql = "SELECT e.* FROM events e 
                      JOIN event_members em ON e.id = em.event_id 
                      WHERE e.id = ? 
                      AND em.user_id = ? AND e.status = 'active'";
        
        $access_stmt = $conn->prepare($access_sql);
        $access_stmt->bind_param("ii", $event_id, $user_id);
        $access_stmt->execute();
        $event = $access_stmt->get_result()->fetch_assoc();
        
        if (!$event && !empty($events)) {
            $event = $events[0];
            $_SESSION['current_event_id'] = $event['id'];
            $_SESSION['current_event_code'] = $event['event_code'];
            
            // Fetch roles for default event
            $roles_stmt->bind_param("ii", $event['id'], $user_id);
            $roles_stmt->execute();
            $user_event_info = $roles_stmt->get_result()->fetch_assoc();
            $user_role = $user_event_info['role'] ?? '';
            $committee_role = $user_event_info['committee_role'] ?? '';
        }
    } else if (!empty($events)) {
        $event = $events[0];
        $_SESSION['current_event_id'] = $event['id'];
        $_SESSION['current_event_code'] = $event['event_code'];
        
        // Fetch roles for default event
        $roles_stmt->bind_param("ii", $event['id'], $user_id);
        $roles_stmt->execute();
        $user_event_info = $roles_stmt->get_result()->fetch_assoc();
        $user_role = $user_event_info['role'] ?? '';
        $committee_role = $user_event_info['committee_role'] ?? '';
    }
    
    // Get all event members with their details
    if ($event) {
        $members_sql = "SELECT u.id, u.username, u.online_status, em.role, em.committee_role, em.joined_at
                       FROM event_members em 
                       JOIN users u ON em.user_id = u.id 
                       WHERE em.event_id = ? AND em.status = 'active'
                       ORDER BY 
                           CASE 
                               WHEN em.role = 'admin' THEN 1
                               WHEN em.role = 'organizer' THEN 2
                               ELSE 3 
                           END,
                           CASE 
                               WHEN em.committee_role = 'chairman' THEN 1
                               WHEN em.committee_role = 'secretary' THEN 2
                               WHEN em.committee_role = 'treasurer' THEN 3
                               ELSE 4 
                           END,
                           u.username ASC";
        $members_stmt = $conn->prepare($members_sql);
        $members_stmt->bind_param("i", $event['id']);
        $members_stmt->execute();
        $members = $members_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    if (!$event && empty($events)) {
        $error_message = "No events found. Please create or join an event.";
    }
    
} catch (Exception $e) {
    error_log($e->getMessage());
    $error_message = "An error occurred while fetching event data. Please try again later.";
}

// Session security
if (!isset($_SESSION['last_regeneration']) || time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Timeout check
$session_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_timeout) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit();
}

$_SESSION['last_activity'] = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Dashboard - Dantico Events</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="./styles/sidebar.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: #081b29;
            display: flex;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: 260px;
            background: rgba(8, 27, 41, 0.9);
            border-right: 2px solid #0ef;
            transition: all 0.5s ease;
            z-index: 100;
        }

        .sidebar-header {
            height: 60px;
            display: flex;
            align-items: center;
            padding: 0 15px;
            border-bottom: 2px solid #0ef;
        }

        .sidebar-header h2 {
            color: #fff;
            font-size: 20px;
            margin-left: 15px;
        }
        /* Hide sidebar by default for unauthorized users */
       .sidebar.hidden {
        display: none;
             }

       /* Show sidebar for authorized users */
.          sidebar.visible {
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

        .menu-item {
            padding: 12px 20px;
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

        .menu-item i {
            font-size: 24px;
            min-width: 40px;
            color: #0ef;
        }

        .menu-item span {
            color: #fff;
            margin-left: 10px;
        }

        .main-content {
            margin-left: 260px;
            padding: 30px;
            width: calc(100% - 260px);
        }

        .event-details-container {
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            border-radius: 15px;
            padding: 30px;
            margin-top: 20px;
            box-shadow: 0 0 25px rgba(0, 238, 255, 0.1);
        }

        .event-header {
            margin-bottom: 30px;
            border-bottom: 1px solid rgba(0, 238, 255, 0.3);
            padding-bottom: 20px;
        }

        .event-title {
            color: #0ef;
            font-size: 32px;
            margin-bottom: 10px;
        }

        .event-code {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }

        .code-container {
            display: flex;
            align-items: center;
            background: rgba(0, 238, 255, 0.1);
            padding: 8px 15px;
            border-radius: 5px;
            margin-right: 10px;
            color: #fff;
        }

        .copy-btn {
            background: transparent;
            border: 1px solid #0ef;
            color: #0ef;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .copy-btn:hover {
            background: #0ef;
            color: #081b29;
        }

        .copy-success {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(0, 238, 255, 0.9);
            color: #081b29;
            padding: 10px 20px;
            border-radius: 5px;
            display: none;
            animation: fadeInOut 2s ease;
            z-index: 1000;
        }

        @keyframes fadeInOut {
            0% { opacity: 0; transform: translateY(-20px); }
            20% { opacity: 1; transform: translateY(0); }
            80% { opacity: 1; transform: translateY(0); }
            100% { opacity: 0; transform: translateY(-20px); }
        }

        .event-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .info-card {
            background: rgba(0, 238, 255, 0.05);
            border: 1px solid rgba(0, 238, 255, 0.2);
            border-radius: 10px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 238, 255, 0.1);
        }

        .info-card i {
            font-size: 24px;
            color: #0ef;
            margin-bottom: 10px;
        }

        .info-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .info-value {
            color: #fff;
            font-size: 18px;
            font-weight: 500;
        }
        .members-section {
            margin-top: 40px;
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            border-radius: 15px;
            padding: 30px;
        }

        .members-title {
            color: #0ef;
            font-size: 24px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .members-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }

        .member-card {
            background: rgba(0, 238, 255, 0.05);
            border: 1px solid rgba(0, 238, 255, 0.2);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .member-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 238, 255, 0.1);
        }

        .member-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            margin: 0 auto 15px;
            background: #0ef;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #081b29;
            border: 2px solid rgba(0, 238, 255, 0.5);
        }

        .member-name {
            color: #fff;
            font-size: 16px;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .member-role {
            color: rgba(0, 238, 255, 0.8);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .member-committee {
            color: rgba(255, 255, 255, 0.6);
            font-size: 12px;
        }

        .online-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-left: 5px;
        }

        .online-status.online {
            background: #00ff00;
        }

        .online-status.offline {
            background: #ff0000;
        }
        .welcome-message {
    position: fixed;
    top: 60px;
    right: 30px;
    background: rgba(0, 238, 255, 0.1);
    padding: 10px 20px;
    border-radius: 10px;
    border: 1px solid #0ef;
    color: #fff;
    font-size: 16px;
    z-index: 99;
    display: flex;
    align-items: center;
    gap: 10px;
}

.welcome-message::before {
    content: '\f2bd';
    font-family: 'boxicons';
    color: #0ef;
    font-size: 20px;
}
        .error-message {
            color: #ff3333;
            background: rgba(255, 51, 51, 0.1);
            border: 1px solid rgba(255, 51, 51, 0.3);
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
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

<main class="main-content">
<div class="welcome-message">
    Welcome, <?php echo htmlspecialchars(ucfirst($username)); ?>!
</div>
        <div class="event-details-container">
            <?php if (isset($error_message)): ?>
                <div class="error-message">
                    <i class='bx bx-error-circle'></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($event): ?>
                <div class="event-header">
                    <h1 class="event-title"><?php echo htmlspecialchars($event['event_name']); ?></h1>
                    <div class="event-code">
                        <div class="code-container">
                            <i class='bx bx-code-alt'></i>
                            Event Code: <span id="eventCode"><?php echo htmlspecialchars($event['event_code']); ?></span>
                        </div>
                        <button class="copy-btn" onclick="copyEventCode()">
                            <i class='bx bx-copy'></i>
                            Copy Code
                        </button>
                    </div>
                </div>

                <div class="event-info-grid">
                    <div class="info-card">
                        <i class='bx bx-calendar'></i>
                        <div class="info-label">Event Date</div>
                        <div class="info-value"><?php echo date('F d, Y', strtotime($event['event_datetime'])); ?></div>
                    </div>

                    <div class="info-card">
                        <i class='bx bx-group'></i>
                        <div class="info-label">Total Members</div>
                        <div class="info-value"><?php echo count($members); ?></div>
                    </div>
               
                    <div class="info-card">
                    <i class='bx bxs-map'></i>
                        <div class="info-label">Location</div>
                        <div class="info-value"><?php echo ucfirst(htmlspecialchars($event['location'])); ?></div>
                    </div>

                    <div class="info-card">
                        <i class='bx bx-user'></i>
                        <div class="info-label">Your Role</div>
                        <div class="info-value">
                            <?php 
                            echo ucfirst(htmlspecialchars($user_role));
                            if ($committee_role) {
                                echo " - " . ucfirst(htmlspecialchars($committee_role));
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Members Grid Section -->
                <div class="members-section">
                    <h2 class="members-title">
                        <i class='bx bx-group'></i>
                        Event Members
                    </h2>
                    <div class="members-grid">
                        <?php foreach ($members as $member): ?>
                            <div class="member-card">
                                <div class="member-avatar">
                                    <?php echo strtoupper(substr($member['username'], 0, 1)); ?>
                                </div>
                                <div class="member-name">
                                    <?php echo htmlspecialchars($member['username']); ?>
                                    <span class="online-status <?php echo $member['online_status'] == 'online' ? 'online' : 'offline'; ?>"></span>
                                </div>
                                <div class="member-role">
                                    <?php echo ucfirst(htmlspecialchars($member['role'])); ?>
                                </div>
                                <?php if ($member['committee_role']): ?>
                                    <div class="member-committee">
                                        <?php echo ucfirst(htmlspecialchars($member['committee_role'])); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="member-joined">
                                    Joined: <?php echo date('M d, Y', strtotime($member['joined_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php else: ?>
                <div class="no-event-message">
                    <i class='bx bx-calendar-x'></i>
                    <p>No event found. Please create or join an event first.</p>
                    <a href="create_event.php" class="create-event-btn">Create Event</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="copyNotification" class="copy-success">
        Code copied successfully!
    </div>

    <script>
    function copyEventCode() {
        const eventCode = document.getElementById('eventCode').textContent;
        navigator.clipboard.writeText(eventCode).then(() => {
            const notification = document.getElementById('copyNotification');
            notification.style.display = 'block';
            setTimeout(() => {
                notification.style.display = 'none';
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy text: ', err);
        });
    }
    </script>
</body>
</html>