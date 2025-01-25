<?php
// Start the session at the beginning of each page
session_start();

// Include database connection
require 'db.php';

// Check if user is logged in, with more robust session validation
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // Clear any existing session data
    session_unset();
    session_destroy();
    
    // Redirect to login page
    header('Location: login.php');
    exit();
}

// At the start, handle event selection and session management
if (isset($_GET['event_id']) && isset($_GET['event_code'])) {
    // Sanitize and store current event details in session
    $_SESSION['current_event_id'] = intval($_GET['event_id']);
    $_SESSION['current_event_code'] = htmlspecialchars($_GET['event_code']);
}

// Retrieve the current event details from session
$event_id = $_SESSION['current_event_id'] ?? null;
$event_code = $_SESSION['current_event_code'] ?? null;

// User ID from session
$user_id = $_SESSION['user_id'];

// Update user's online status and last_active when entering dashboard
$update_stmt = $conn->prepare("UPDATE users SET 
    last_login = NOW(), 
    last_active = NOW(), 
    online_status = 'online' 
    WHERE id = ?");
$update_stmt->bind_param("i", $user_id);
$update_stmt->execute();
$update_stmt->close();

try {
    // First, check if the user is a member of any events
    $member_events_sql = "SELECT e.* FROM events e 
                         JOIN event_members em ON e.id = em.event_id 
                         WHERE em.user_id = ? AND em.status = 'active'
                         ORDER BY e.created_at DESC";
    
    $stmt = $conn->prepare($member_events_sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $events = $result->fetch_all(MYSQLI_ASSOC);
    
    // If specific event requested or stored in session
    if ($event_id) {
        // Check if user has access to this specific event
        $access_sql = "SELECT e.* FROM events e 
                      JOIN event_members em ON e.id = em.event_id 
                      WHERE e.id = ? 
                      AND em.user_id = ? AND e.status = 'active'";
        
        $access_stmt = $conn->prepare($access_sql);
        $access_stmt->bind_param("ii", $event_id, $user_id);
        $access_stmt->execute();
        $event = $access_stmt->get_result()->fetch_assoc();
        
        if (!$event && !empty($events)) {
            // If no specific event access, default to first event
            $event = $events[0];
            
            // Update session with default event
            $_SESSION['current_event_id'] = $event['id'];
            $_SESSION['current_event_code'] = $event['event_code'];
        }
    } else if (!empty($events)) {
        // Default to most recent event the user is a member of
        $event = $events[0];
        
        // Update session with default event
        $_SESSION['current_event_id'] = $event['id'];
        $_SESSION['current_event_code'] = $event['event_code'];
    }
    
    if (!$event && empty($events)) {
        $error_message = "No events found. Please create or join an event.";
    }
    
} catch (Exception $e) {
    error_log($e->getMessage());
    $error_message = "An error occurred while fetching event data. Please try again later.";
}

// Session security: Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['last_regeneration']) || time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Timeout check
$session_timeout = 1800; // 30 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $session_timeout) {
    // Destroy the session and redirect to login
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();
?>

<!-- Rest of your existing HTML remains the same -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Dashboard - Dantico Events</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
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
<?php
// Ensure this PHP block is at the top of your sidebar include or in a separate navigation.php file
session_start();

// Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) . 
                    '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
}

// Get the event context URL to be used across navigation
$base_url = getEventContextURL();
?>

<!-- Sidebar Navigation -->
<nav class="sidebar">
    <div class="sidebar-header">
        <i class='bx bx-calendar-event' style="color: #0ef; font-size: 24px;"></i>
        <h2>Dantico Events</h2>
    </div>
    <div class="sidebar-menu">
        <!-- Dashboard -->
        <div class="menu-category">
            <div class="menu-item active">
                <a href="./dashboard.php<?= $base_url ?>">
                    <i class='bx bx-home-alt'></i>
                    <span>Dashboard</span>
                </a>
            </div>
        </div>

        <!-- Committees Section -->
        <div class="menu-category">
            <div class="category-title">paybill</div>
            <div class="menu-item">
                <a href="./paybill.php<?= $base_url ?>">
                    <i class='bx bx-plus-circle'></i>
                    <span>Add Paybill</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./committee-list.php<?= $base_url ?>">
                    <i class='bx bx-group'></i>
                    <span>Committee List</span>
                </a>
            </div>
        </div>

        <!-- Communication Section -->
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

        <!-- Contributions Section -->
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

        <!-- Reviews Section -->
        <div class="menu-category">
            <div class="category-title">Reviews</div>
            <div class="menu-item">
                <a href="./minutes.php<?= $base_url ?>">
                    <i class='bx bxs-timer'></i>
                    <span>Minutes</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./tasks.php<?= $base_url ?>">
                    <i class='bx bx-task'></i>
                    <span>Tasks</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./reports.php<?= $base_url ?>">
                    <i class='bx bx-line-chart'></i>
                    <span>Reports</span>
                </a>
            </div>
        </div>

        <!-- Other Tools -->
        <div class="menu-category">
            <div class="category-title">Tools</div>
            <div class="menu-item">
                <a href="./schedule.php<?= $base_url ?>">
                    <i class='bx bx-calendar'></i>
                    <span>Schedule</span>
                </a>
            </div>
            <div class="menu-item">
                <a href="./settings.php<?= $base_url ?>">
                    <i class='bx bx-cog'></i>
                    <span>Settings</span>
                </a>
            </div>
        </div>
    </div>
</nav>


    <main class="main-content">
        <div class="event-details-container">
            <?php if (isset($error_message)): ?>
                <div class="error-message">
                    <i class='bx bx-error-circle'></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($event): ?>
                <div class="event-header">
                    <h1 class="event-title"><?php echo htmlspecialchars($event['name']); ?></h1>
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
                        <div class="info-label">Created Date</div>
                        <div class="info-value"><?php echo date('F d, Y', strtotime($event['created_at'])); ?></div>
                    </div>

                    <div class="info-card">
                        <i class='bx bx-group'></i>
                        <div class="info-label">Members</div>
                        <div class="info-value">
                            <?php 
                            $member_count_sql = "SELECT COUNT(*) as count FROM event_members WHERE event_id = ? AND status = 'active'";
                            $count_stmt = $conn->prepare($member_count_sql);
                            $count_stmt->bind_param("i", $event['id']);
                            $count_stmt->execute();
                            $count = $count_stmt->get_result()->fetch_assoc()['count'];
                            echo $count;
                            ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <i class='bx bx-check-circle'></i>
                        <div class="info-label">Status</div>
                        <div class="info-value"><?php echo ucfirst(htmlspecialchars($event['status'])); ?></div>
                    </div>

                    <?php
                    // Get user's role in this event
                    $role_sql = "SELECT role, committee_role FROM event_members WHERE event_id = ? AND user_id = ?";
                    $role_stmt = $conn->prepare($role_sql);
                    $role_stmt->bind_param("ii", $event['id'], $user_id);
                    $role_stmt->execute();
                    $role_info = $role_stmt->get_result()->fetch_assoc();
                    ?>
                    <div class="info-card">
                        <i class='bx bx-user'></i>
                        <div class="info-label">Your Role</div>
                        <div class="info-value">
                            <?php 
                            echo ucfirst(htmlspecialchars($role_info['role']));
                            if ($role_info['committee_role']) {
                                echo " - " . htmlspecialchars($role_info['committee_role']);
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div style="text-align: center; color: #fff; padding: 40px 20px;">
                    <i class='bx bx-calendar-x' style="font-size: 48px; color: #0ef; margin-bottom: 15px; display: block;"></i>
                    <p>No event found. Please create or join an event first.</p>
                    <a href="create_event.php" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background: #0ef; color: #081b29; text-decoration: none; border-radius: 5px;">Create Event</a>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Notification for copy success -->
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