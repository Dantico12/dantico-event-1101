<?php
session_start();
require_once "db.php";

// Enable error reporting for debugging
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

// Check if user has access to view this page
$required_roles = ['Admin', 'Organizer', 'Secretary', 'Chairman'];
if (!hasAccess($required_roles, $user_role, $committee_role)) {
    header("Location: access-denied.php");
    exit();
}

// Get the event context URL to be used across navigation
$base_url = getEventContextURL();

// Initialize messages
$meeting_msg = '';
$task_msg = '';

// Handle Meeting Form Submission (only for authorized roles)
if (isset($_POST['save_meeting']) && hasAccess(['Admin', 'Organizer', 'Secretary'], $user_role, $committee_role)) {
    try {
        // Validate meeting date/time
        $meeting_datetime = new DateTime($_POST['date'] . ' ' . $_POST['start_time']);
        $current_datetime = new DateTime();
        $min_meeting_datetime = (new DateTime())->add(new DateInterval('PT5M')); // 5 minutes from now
        
        if ($meeting_datetime < $min_meeting_datetime) {
            throw new Exception("Meeting must be scheduled at least 5 minutes from now");
        }
        
        // Validate end time is after start time
        $end_datetime = new DateTime($_POST['date'] . ' ' . $_POST['end_time']);
        if ($end_datetime <= $meeting_datetime) {
            throw new Exception("End time must be after start time");
        }

        // Prepare meeting data
        $meeting_data = [
            'event_id' => $_SESSION['current_event_id'],
            'meeting_type' => $_POST['meeting_type'],
            'meeting_date' => $_POST['date'],
            'meeting_time' => $_POST['start_time'],
            'end_time' => $_POST['end_time'],
            'status' => ($_POST['save_meeting'] === 'draft') ? 'draft' : 'scheduled',
            'created_by' => $_SESSION['user_id']
        ];

        // Insert meeting into the database
        $stmt = $conn->prepare("INSERT INTO meetings (event_id, meeting_type, meeting_date, meeting_time, end_time, status, created_by) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssssi", 
            $meeting_data['event_id'], 
            $meeting_data['meeting_type'], 
            $meeting_data['meeting_date'], 
            $meeting_data['meeting_time'],
            $meeting_data['end_time'],
            $meeting_data['status'], 
            $meeting_data['created_by']);
        
        if ($stmt->execute()) {
            $meeting_msg = "Meeting saved successfully!";
        } else {
            $meeting_msg = "Error saving meeting: " . $stmt->error;
        }
    } catch (Exception $e) {
        $meeting_msg = "Error: " . $e->getMessage();
    }
}

// Handle Task Form Submission (only for authorized roles)
if (isset($_POST['add_task']) && hasAccess(['Admin', 'Organizer', 'Secretary', 'Chairman'], $user_role, $committee_role)) {
    try {
        // Validate due date is at least 1 day in future
        $due_date = new DateTime($_POST['due_date']);
        $min_due_date = (new DateTime())->add(new DateInterval('P1D')); // 1 day from now
        
        if ($due_date < $min_due_date) {
            throw new Exception("Due date must be at least 1 day in the future");
        }

        // Prepare task data (without meeting_id)
        $task_data = [
            'event_id' => $_SESSION['current_event_id'],
            'description' => $_POST['task_description'],
            'assigned_to' => $_POST['assigned_to'],
            'due_date' => $_POST['due_date'],
            'status' => 'pending'
        ];

        // Insert task into the database (without meeting_id)
        $stmt = $conn->prepare("INSERT INTO tasks (event_id, description, assigned_to, due_date, status) 
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", 
            $task_data['event_id'],
            $task_data['description'],
            $task_data['assigned_to'],
            $task_data['due_date'],
            $task_data['status']);
        
        if ($stmt->execute()) {
            $task_msg = "Task added successfully!";
        } else {
            $task_msg = "Error adding task: " . $stmt->error;
        }
    } catch (Exception $e) {
        $task_msg = "Error: " . $e->getMessage();
    }
}

// Fetch event members for task assignment
$members_query = "SELECT em.user_id, u.username, em.role, em.committee_role 
                  FROM event_members em
                  JOIN users u ON em.user_id = u.id
                  WHERE em.event_id = ? 
                  AND em.status = 'active'
                  ORDER BY em.role DESC, u.username ASC";
$stmt = $conn->prepare($members_query);
$stmt->bind_param("i", $_SESSION['current_event_id']);
$stmt->execute();
$members_result = $stmt->get_result();
$event_members = $members_result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meeting Tasks Tracker</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --sidebar-width: 260px;
            --header-height: 70px;
            --primary-color: #0ef;
            --bg-dark: #081b29;
            --transition-speed: 0.3s;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: var(--bg-dark);
            color: #fff;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: var(--sidebar-width);
            background: rgba(8, 27, 41, 0.95);
            border-right: 2px solid var(--primary-color);
            transition: all var(--transition-speed) ease;
            z-index: 100;
        }

        .sidebar-header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 15px;
            border-bottom: 2px solid var(--primary-color);
        }

        .sidebar-header h2 {
            color: #fff;
            font-size: 20px;
            margin-left: 15px;
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
            color: var(--primary-color);
            font-size: 12px;
            text-transform: uppercase;
            padding: 10px 20px;
            letter-spacing: 1px;
            opacity: 0.7;
        }

        .menu-item {
            padding: 12px 20px 12px 30px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
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
            color: var(--primary-color);
            transition: all var(--transition-speed) ease;
        }

        .menu-item span {
            color: #fff;
            white-space: nowrap;
            transition: all var(--transition-speed) ease;
            margin-left: 10px;
        }

        .notification-badge {
            background: var(--primary-color);
            color: var(--bg-dark);
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 12px;
            margin-left: auto;
        }

        .main-content {
            margin-left: var(--sidebar-width);
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .section {
            background: rgba(8, 27, 41, 0.95);
            border: 2px solid var(--primary-color);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0, 238, 255, 0.1);
        }

        .section-title {
            color: var(--primary-color);
            font-size: 20px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: var(--primary-color);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--primary-color);
            border-radius: 8px;
            color: #fff;
            transition: all var(--transition-speed) ease;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            transition: all var(--transition-speed) ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: var(--bg-dark);
            border: none;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            background: rgba(0, 238, 255, 0.1);
            border: 1px solid var(--primary-color);
            color: #fff;
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
        <div class="container">
            <!-- Meeting Form Section -->
            <div class="section">
                <h2 class="section-title">
                    <i class='bx bx-calendar-edit'></i>
                    Schedule New Meeting
                </h2>
                <?php if ($meeting_msg): ?>
                    <div class="alert"><?php echo htmlspecialchars($meeting_msg); ?></div>
                <?php endif; ?>
                
                <form id="meetingForm" method="POST" action="">
                    <div class="form-group">
                        <label>Meeting Type</label>
                        <select name="meeting_type" required>
                            <?php
                            $meeting_types = [
                                'board' => 'Board Meeting',
                                'committee' => 'Committee Meeting',
                                'planning' => 'Planning Session'
                            ];
                            foreach ($meeting_types as $value => $label) {
                                echo '<option value="' . htmlspecialchars($value) . '">' . 
                                     htmlspecialchars($label) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="date" required>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Start Time</label>
                            <input type="time" name="start_time" required>
                        </div>
                        <div class="form-group">
                            <label>End Time</label>
                            <input type="time" name="end_time" required>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 15px;">
                        <button type="submit" name="save_meeting" value="draft" class="btn btn-outline">
                            <i class='bx bx-save'></i> Save Draft
                        </button>
                        <button type="submit" name="save_meeting" value="final" class="btn btn-primary">
                            <i class='bx bx-check-circle'></i> Schedule Meeting
                        </button>
                    </div>
                </form>
            </div>

            <!-- Task Form Section -->
<div class="section">
    <h2 class="section-title">
        <i class='bx bx-task'></i>
        Add New Task
    </h2>
    <?php if ($task_msg): ?>
        <div class="alert"><?php echo htmlspecialchars($task_msg); ?></div>
    <?php endif; ?>
    
    <form id="taskForm" method="POST" action="">
        <div class="form-group">
            <label>Task Description</label>
            <textarea name="task_description" required></textarea>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div class="form-group">
                <label>Assigned To</label>
                <select name="assigned_to" required>
                    <option value="">Select member...</option>
                    <?php 
                    if ($event_members && mysqli_num_rows($members_result) > 0) {
                        foreach ($event_members as $member) {
                            $member_text = htmlspecialchars($member['username']);
                            if ($member['committee_role']) {
                                $member_text .= ' (' . htmlspecialchars($member['committee_role']) . ')';
                            } else {
                                $member_text .= ' (' . htmlspecialchars($member['role']) . ')';
                            }
                            echo '<option value="' . htmlspecialchars($member['username']) . '">' . 
                                 $member_text . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>Due Date</label>
                <input type="date" name="due_date" required>
            </div>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" name="add_task" class="btn btn-primary">
                <i class='bx bx-plus'></i> Add Task
            </button>
        </div>
    </form>
</div>

           
        </div>
    </div>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Meeting form validation
    const meetingForm = document.getElementById('meetingForm');
    if (meetingForm) {
        meetingForm.addEventListener('submit', function(e) {
            const dateInput = meetingForm.querySelector('input[name="date"]');
            const startTimeInput = meetingForm.querySelector('input[name="start_time"]');
            const endTimeInput = meetingForm.querySelector('input[name="end_time"]');
            
            // Get current date/time plus 5 minutes
            const now = new Date();
            const minDateTime = new Date(now.getTime() + 5 * 60000); // Add 5 minutes
            
            // Create meeting datetime object
            const meetingDateTime = new Date(dateInput.value + 'T' + startTimeInput.value);
            
            // Validate meeting is in future
            if (meetingDateTime < minDateTime) {
                e.preventDefault();
                alert('Meeting must be scheduled at least 5 minutes from now');
                return false;
            }
            
            // Validate end time is after start time
            const endDateTime = new Date(dateInput.value + 'T' + endTimeInput.value);
            if (endDateTime <= meetingDateTime) {
                e.preventDefault();
                alert('End time must be after start time');
                return false;
            }
        });
    }

    // Task form validation
    const taskForm = document.getElementById('taskForm');
    if (taskForm) {
        taskForm.addEventListener('submit', function(e) {
            const dueDateInput = taskForm.querySelector('input[name="due_date"]');
            
            // Get current date plus 1 day
            const now = new Date();
            const tomorrow = new Date(now);
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setHours(0, 0, 0, 0); // Start of day
            
            // Create due date object
            const dueDate = new Date(dueDateInput.value);
            dueDate.setHours(0, 0, 0, 0);
            
            // Validate due date is in future
            if (dueDate < tomorrow) {
                e.preventDefault();
                alert('Due date must be at least 1 day in the future');
                return false;
            }
        });
    }

    // Set minimum values on date/time inputs
    const today = new Date().toISOString().split('T')[0];
    document.querySelector('input[name="date"]').min = today;
    
    // For meeting time - set minimum time (current time + 5 minutes)
    const now = new Date();
    const minTime = new Date(now.getTime() + 5 * 60000); // Add 5 minutes
    const minTimeString = minTime.toTimeString().substr(0, 5);
    document.querySelector('input[name="start_time"]').min = minTimeString;
    
    // For task due date - set minimum date (tomorrow)
    const tomorrow = new Date(now);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowString = tomorrow.toISOString().split('T')[0];
    document.querySelector('input[name="due_date"]').min = tomorrowString;
});
</script>
</body>
</html>