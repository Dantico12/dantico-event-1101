<?php
session_start();
require_once "db.php";
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
// Session validation
if (!isset($_SESSION['current_event_id']) || !isset($_SESSION['user_id'])) {
    header("Location: events.php");
    exit;
}

$current_event_id = $_SESSION['current_event_id'];
$current_user_id = $_SESSION['user_id'];

// Get event details
function getEventDetails($conn, $event_id) {
    $stmt = $conn->prepare("SELECT event_name FROM events WHERE id = ?");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Check user permission
function checkUserPermission($conn, $user_id, $event_id) {
    $stmt = $conn->prepare("
        SELECT role 
        FROM event_members 
        WHERE user_id = ? AND event_id = ? 
        AND role IN ('admin', 'secretary') 
        AND status = 'active'
    ");
    $stmt->bind_param("ii", $user_id, $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->num_rows > 0;
}

// Get tasks with error handling
function getTasks($conn, $event_id) {
    $query = "SELECT 
                t.*,
                COALESCE(u.username, 'Unassigned') as assigned_username
              FROM tasks t 
              LEFT JOIN users u ON t.assigned_to = u.id 
              WHERE t.event_id = ? 
              ORDER BY t.created_at DESC";
              
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("i", $event_id);
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return [];
    }
    
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// Process task actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hasPermission = checkUserPermission($conn, $current_user_id, $current_event_id);
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
        }
    }
}

// Get event details and tasks
$event = getEventDetails($conn, $current_event_id);
$hasPermission = checkUserPermission($conn, $current_user_id, $current_event_id);
$tasks = getTasks($conn, $current_event_id);

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
            
            <div class="menu-item">
                <a href="./tasks.php<?= $base_url ?>">
                    <i class='bx bx-task'></i>
                    <span>Tasks</span>
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
                <a href="./minutes.php" class="add-task-btn">
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
                                <td><?php echo htmlspecialchars($task['assigned_to']); ?></td>
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