<?php
session_start();
require_once "db.php";
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) . 
                    '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
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

// Get the event context URL to be used across navigation
$base_url = getEventContextURL();

// Check if user has access to view this page
$required_roles = ['Admin', 'Organizer', 'Member', 'Chairman', 'Secretary', 'Treasurer'];
if (!hasAccess($required_roles, $user_role, $committee_role)) {
    header("Location: access-denied.php");
    exit();
}

// Define permission for task management (used in the view)
$hasPermission = hasAccess(['Admin', 'Organizer', 'Secretary'], $user_role, $committee_role);

// Session validation
if (!isset($_SESSION['current_event_id']) || !isset($_SESSION['user_id'])) {
    header("Location: events.php");
    exit;
}

$current_event_id = $_SESSION['current_event_id'];
$current_user_id = $_SESSION['user_id'];

// Get event details
function getEventDetails($conn, $event_id) {
    $stmt = $conn->prepare("SELECT event_name, event_code FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Get tasks with error handling
function getTasks($conn, $event_id, $user_id = null, $user_role = null) {
    $query = "SELECT 
                t.*,
                COALESCE(u.username, 'Unassigned') as assigned_username
              FROM tasks t 
              LEFT JOIN users u ON t.assigned_to = u.id 
              WHERE t.event_id = ?";
    
    // Filter tasks based on user role
    if ($user_role === 'member') {
        $query .= " AND (t.assigned_to = ? OR t.assigned_to IS NULL)";
    }
    
    $query .= " ORDER BY t.created_at DESC";
              
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return [];
    }
    
    if ($user_role === 'member') {
        $stmt->bind_param("ii", $event_id, $user_id);
    } else {
        $stmt->bind_param("i", $event_id);
    }
    
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return [];
    }
    
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Process task actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only allow actions if user has permission
    if (!$hasPermission) {
        echo json_encode(['error' => 'unauthorized']);
        exit;
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete':
                if (isset($_POST['task_id'])) {
                    $stmt = $conn->prepare("DELETE FROM tasks WHERE task_id = ? AND event_id = ?");
                    $stmt->bind_param("ii", $_POST['task_id'], $current_event_id);
                    echo $stmt->execute() ? 'success' : 'error';
                }
                exit;
                
            case 'edit':
                if (isset($_POST['task_id'])) {
                    $stmt = $conn->prepare("UPDATE tasks SET description = ?, due_date = ?, status = ? WHERE task_id = ? AND event_id = ?");
                    $stmt->bind_param("sssii", $_POST['description'], $_POST['due_date'], $_POST['status'], $_POST['task_id'], $current_event_id);
                    echo $stmt->execute() ? 'success' : 'error';
                }
                exit;
                
            case 'update_status':
                // Allow task assignees to update their own task status
                if (isset($_POST['task_id']) && isset($_POST['status'])) {
                    $task_id = $_POST['task_id'];
                    $status = $_POST['status'];
                    
                    // Verify the task belongs to the current user if they're not an admin/secretary
                    if (!hasAccess(['Admin', 'Organizer', 'Secretary'], $user_role, $committee_role)) {
                        $check_stmt = $conn->prepare("SELECT assigned_to FROM tasks WHERE task_id = ? AND event_id = ?");
                        $check_stmt->bind_param("ii", $task_id, $current_event_id);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();
                        $task = $check_result->fetch_assoc();
                        
                        if ($task['assigned_to'] != $current_user_id) {
                            echo json_encode(['error' => 'unauthorized']);
                            exit;
                        }
                    }
                    
                    $stmt = $conn->prepare("UPDATE tasks SET status = ? WHERE task_id = ? AND event_id = ?");
                    $stmt->bind_param("sii", $status, $task_id, $current_event_id);
                    echo $stmt->execute() ? 'success' : 'error';
                }
                exit;
        }
    }
}

// Get event details and tasks
$event = getEventDetails($conn, $current_event_id);
$_SESSION['current_event_code'] = $event['event_code']; // Store event code in session

// Get tasks based on user role
if ($user_role === 'member') {
    $tasks = getTasks($conn, $current_event_id, $current_user_id, $user_role);
} else {
    $tasks = getTasks($conn, $current_event_id);
}

// Handle AJAX request for task details
if (isset($_GET['task_id'])) {
    $task_id = $_GET['task_id'];
    $stmt = $conn->prepare("SELECT * FROM tasks WHERE task_id = ? AND event_id = ?");
    $stmt->bind_param("ii", $task_id, $current_event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    echo json_encode($result->fetch_assoc());
    exit;
}
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

        .tasks-container {
            max-width: 1200px;
            margin: 0 auto;
            color:#fff;
        }

        .tasks-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 20px;
}

.tasks-header h2 {
    color: #fff;
    margin: 0;
}

.add-task-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #0ef;
    color: #081b29;
    text-decoration: none;
    padding: 10px 20px;
    border-radius: 5px;
    border: 2px solid #0ef;
    font-weight: 500;
    transition: all 0.3s ease;
}

.add-task-btn:hover {
    background: transparent;
    color: #0ef;
}

.add-task-btn i {
    font-size: 1.2em;
}

        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(8, 27, 41, 0.9);
            border: 2px solid #0ef;
            border-radius: 10px;
            overflow: hidden;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #fff;
        }

        th {
            background: rgba(242, 249, 250, 0.1);
            color: #0ef;
        }

        tr:hover {
            background: rgba(0, 238, 255, 0.05);
        }

        .task-status {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            text-transform: uppercase;
        }

        .status-pending {
            background: #ffd700;
            color: #000;
        }

        .status-completed {
            background: #00ff00;
            color: #000;
        }

        .task-actions {
            display: flex;
            gap: 10px;
        }

        .task-action-btn {
            background: transparent;
            border: 1px solid #0ef;
            color: #0ef;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .task-action-btn:hover {
            background: #0ef;
            color: #081b29;
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
        <h2 class="header-title">Tasks for <?php echo htmlspecialchars($event['event_name'] ?? 'Event'); ?></h2>
        <div class="header-actions">
            <i class='bx bx-search'></i>
            <i class='bx bx-bell'></i>
            <i class='bx bx-user-circle'></i>
        </div>
    </div>

    <div class="tasks-container">
        <div class="tasks-header">
            <h2>Event Tasks</h2>
            <?php if ($hasPermission): ?>
            <a href="minutes.php<?= $base_url ?>" class="add-task-btn">
                <i class='bx bx-plus'></i> Add New Task
            </a>
            <?php endif; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Task ID</th>
                    <th>Description</th>
                    <th>Assigned To</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <?php if ($hasPermission): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($tasks)): ?>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($task['task_id']); ?></td>
                            <td><?php echo htmlspecialchars($task['description']); ?></td>
                            <td><?php echo htmlspecialchars($task['assigned_username']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($task['due_date'])); ?></td>
                            <td>
                                <span class="task-status status-<?php echo strtolower($task['status']); ?>">
                                    <?php echo htmlspecialchars($task['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y H:i:s', strtotime($task['created_at'])); ?></td>
                            <?php if ($hasPermission): ?>
                            <td class="task-actions">
                                <button class="task-action-btn" onclick="editTask(<?php echo $task['task_id']; ?>)">
                                    <i class='bx bx-edit'></i> Edit
                                </button>
                                <button class="task-action-btn" onclick="deleteTask(<?php echo $task['task_id']; ?>)">
                                    <i class='bx bx-trash'></i> Delete
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $hasPermission ? 7 : 6; ?>" style="text-align: center;">No tasks found for this event.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: relative; top: 50%; left: 50%; transform: translate(-50%, -50%); background: #081b29; padding: 20px; border-radius: 10px; border: 2px solid #0ef; width: 90%; max-width: 500px;">
        <h2 style="color: #fff; margin-bottom: 20px;">Edit Task</h2>
        <form id="editTaskForm">
            <input type="hidden" id="edit_task_id" name="task_id">
            <input type="hidden" name="action" value="edit">
            <div style="margin-bottom: 15px;">
                <label style="color: #fff; display: block; margin-bottom: 5px;">Description:</label>
                <textarea id="edit_description" name="description" style="width: 100%; padding: 8px; border-radius: 5px; background: rgba(255,255,255,0.1); color: #fff; border: 1px solid #0ef;"></textarea>
            </div>
            <div style="margin-bottom: 15px;">
                <label style="color: #fff; display: block; margin-bottom: 5px;">Due Date:</label>
                <input type="date" id="edit_due_date" name="due_date" style="width: 100%; padding: 8px; border-radius: 5px; background: rgba(255,255,255,0.1); color: #fff; border: 1px solid #0ef;">
            </div>
            <div style="margin-bottom: 15px;">
                <label style="color: #fff; display: block; margin-bottom: 5px;">Status:</label>
                <select id="edit_status" name="status" style="width: 100%; padding: 8px; border-radius: 5px; background: rgba(255,255,255,0.1); color: #fff; border: 1px solid #0ef;">
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="in progress">In Progress</option>
                </select>
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" onclick="closeEditModal()" style="padding: 8px 15px; border-radius: 5px; border: 1px solid #0ef; background: transparent; color: #0ef; cursor: pointer;">Cancel</button>
                <button type="submit" style="padding: 8px 15px; border-radius: 5px; border: none; background: #0ef; color: #081b29; cursor: pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

    <script>
        function editTask(taskId) {
            fetch(`<?= $_SERVER['PHP_SELF'] ?>?task_id=${taskId}`)
                .then(response => response.json())
                .then(task => {
                    document.getElementById('edit_task_id').value = task.task_id;
                    document.getElementById('edit_description').value = task.description;
                    document.getElementById('edit_due_date').value = task.due_date;
                    document.getElementById('edit_status').value = task.status.toLowerCase();
                    document.getElementById('editModal').style.display = 'block';
                });
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        document.getElementById('editTaskForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(result => {
                if (result === 'success') {
                    closeEditModal();
                    window.location.reload();
                } else {
                    alert('Error updating task');
                }
            });
        });

        function deleteTask(taskId) {
            if (confirm('Are you sure you want to delete this task?')) {
                const formData = new FormData();
                formData.append('task_id', taskId);
                formData.append('action', 'delete');

                fetch('<?= $_SERVER['PHP_SELF'] ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(result => {
                    if (result === 'success') {
                        window.location.reload();
                    } else {
                        alert('Error deleting task');
                    }
                });
            }
        }
    </script>
</body>
</html>