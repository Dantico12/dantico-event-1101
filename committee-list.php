<?php
session_start();
require 'db.php';

// Initialize variables
$event_id = 0;
$base_url = '';
$error_message = '';
$user_role = '';
$committee_role = '';

// Validate and set event ID from different possible sources
if (isset($_GET['event_id'])) {
    $event_id = (int)$_GET['event_id'];
} elseif (isset($_SESSION['current_event_id'])) {
    $event_id = (int)$_SESSION['current_event_id'];
} else {
    $error_message = "No event ID provided";
}

// Store event context in session if valid
if ($event_id > 0) {
    $_SESSION['current_event_id'] = $event_id;

    // Get event details to validate and get event code
    $event = getEventDetails($conn, $event_id);
    if ($event && isset($event['code'])) {
        $_SESSION['current_event_code'] = $event['code'];
    }
}

// Function to fetch event details
function getEventDetails($conn, $event_id) {
    if ($event_id <= 0) return null;
    
    $sql = "SELECT * FROM events WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $event_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    return null;
}

// Function to fetch committee members
function getCommitteeMembers($conn, $event_id) {
    if ($event_id <= 0) return [];
    
    $sql = "SELECT em.*, u.username, u.email 
            FROM event_members em 
            JOIN users u ON em.user_id = u.id 
            WHERE em.event_id = ? 
            AND (em.committee_role IS NOT NULL OR em.role = 'organizer')
            AND em.status = 'active' 
            ORDER BY 
                CASE 
                    WHEN em.committee_role = 'chairman' THEN 1
                    WHEN em.committee_role = 'secretary' THEN 2
                    WHEN em.committee_role = 'treasurer' THEN 3
                    WHEN em.role = 'organizer' THEN 4
                    ELSE 5
                END";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $event_id);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    return [];
}

// Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) .
        '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
}

// Function to fetch user roles
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

// Set base URL for use in templates
$base_url = getEventContextURL();
$current_event_id = $_SESSION['current_event_id'] ?? null;

// Initialize user roles
if (isset($_SESSION['user_id']) && $current_event_id) {
    $user_roles = getUserRoles($conn, $_SESSION['user_id'], $current_event_id);
    $user_role = $user_roles['role'] ?? '';
    $committee_role = $user_roles['committee_role'] ?? '';
}

// Get event and committee members
$event = getEventDetails($conn, $event_id);
$committee_members = getCommitteeMembers($conn, $event_id);

// Handle event not found
if (!$event) {
    $error_message = "Event not found";
}

// Close database connection
$conn->close();
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

        ontent-section {
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .event-info {
            color: #fff;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(0, 238, 255, 0.2);
        }

        .event-info h3 {
            color: #0ef;
            margin-bottom: 10px;
        }

        .committee-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            color: #fff;
        }

        .committee-table th,
        .committee-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 238, 255, 0.2);
        }

        .committee-table th {
            background: rgba(0, 238, 255, 0.1);
            color: #0ef;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9em;
        }

        .committee-table tr:hover {
            background: rgba(0, 238, 255, 0.05);
        }

        .role-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 0.85em;
            font-weight: 500;
            text-transform: capitalize;
        }

        .role-chairman {
            background: rgba(255, 99, 71, 0.2);
            color: #ff6347;
        }

        .role-secretary {
            background: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .role-treasurer {
            background: rgba(241, 196, 15, 0.2);
            color: #f1c40f;
        }

        .role-organizer {
            background: rgba(142, 68, 173, 0.2);
            color: #8e44ad;
        }

        .empty-message {
            text-align: center;
            padding: 40px;
            color: #fff;
            font-size: 1.1em;
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
            <h2 class="header-title">Committee List</h2>
            <div class="header-actions">
                <i class='bx bx-search'></i>
                <i class='bx bx-bell'></i>
                <i class='bx bx-user-circle'></i>
            </div>
        </div>

        <div class="content-section">
            <div class="event-info">
                <h3><?= htmlspecialchars($event['name'] ?? 'Event Committee Members') ?></h3>
                <?php if ($event): ?>
                    <p>Event Date: <?= date('F d, Y', strtotime($event['created_at'])) ?></p>
                <?php endif; ?>
            </div>

            <?php if (empty($committee_members)): ?>
                <div class="empty-message">
                    No committee members found for this event.
                </div>
            <?php else: ?>
                <table class="committee-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Joined Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($committee_members as $member): ?>
                            <tr>
                                <td>
                                    <i class='bx bx-user' style="color: #0ef; margin-right: 8px;"></i>
                                    <?= htmlspecialchars($member['username']) ?>
                                </td>
                                <td>
                                    <?php 
                                    $role = $member['committee_role'] ?: $member['role'];
                                    $roleClass = strtolower($role);
                                    ?>
                                    <span class="role-badge role-<?= $roleClass ?>">
                                        <?= htmlspecialchars(ucfirst($role)) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($member['email']) ?></td>
                                <td><?= date('M d, Y', strtotime($member['joined_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <script>
        // Toggle sidebar
        const toggleBtn = document.querySelector('.toggle-btn');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content');

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapse');
            mainContent.classList.toggle('expand');
        });

        // Handle menu item clicks
        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', () => {
                // Remove active class from all items
                menuItems.forEach(i => i.classList.remove('active'));
                // Add active class to clicked item
                item.classList.add('active');
                // Update header title based on selected menu
                const spanText = item.querySelector('span').textContent;
                document.querySelector('.header-title').textContent = spanText;
            });
        });
    </script>
</body>
</html>