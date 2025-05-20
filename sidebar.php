<?php
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
?>

<style>
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

.menu-item {
    padding: 12px 20px;
    display: flex;
    align-items: center;
    cursor: pointer;
    transition: all 0.3s ease;
    position: relative;
}

.menu-item:hover {
    background: rgba(0, 238, 255, 0.1);
}

.menu-item.active {
    background: rgba(0, 238, 255, 0.15);
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
</style>

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
