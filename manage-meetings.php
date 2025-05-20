<?php
session_start();
require 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Get event ID from URL
$event_id = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// Verify user is admin of this event
$is_admin = false;
if ($stmt = $conn->prepare("SELECT role FROM event_members WHERE event_id = ? AND user_id = ? AND role = 'admin'")) {
    $stmt->bind_param("ii", $event_id, $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $is_admin = $result->num_rows > 0;
    $stmt->close();
}

if (!$is_admin) {
    header("Location: dashboard.php");
    exit();
}

// Handle meeting deletion with transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_meeting'])) {
    $meeting_id = (int)$_POST['meeting_id'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // First delete related tasks
        $delete_tasks = $conn->prepare("DELETE FROM tasks WHERE meeting_id = ?");
        $delete_tasks->bind_param("i", $meeting_id);
        $delete_tasks->execute();
        
        // Then delete the meeting
        $delete_meeting = $conn->prepare("DELETE FROM meetings WHERE meeting_id = ? AND event_id = ?");
        $delete_meeting->bind_param("ii", $meeting_id, $event_id);
        $delete_meeting->execute();
        
        // If we get here, commit the transaction
        $conn->commit();
        $_SESSION['success_message'] = "Meeting and related tasks deleted successfully.";
        
    } catch (Exception $e) {
        // If anything goes wrong, roll back the transaction
        $conn->rollback();
        $_SESSION['error_message'] = "Error deleting meeting: " . $e->getMessage();
    }
    
    header("Location: manage-meetings.php?event_id=" . $event_id);
    exit();
}

// Handle ending meeting via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'end_meeting') {
    $meeting_id = (int)$_POST['meeting_id'];
    $response = array();
    
    try {
        $update_stmt = $conn->prepare("UPDATE meetings SET status = 'Ended', updated_at = NOW() WHERE meeting_id = ? AND event_id = ?");
        $update_stmt->bind_param("ii", $meeting_id, $event_id);
        
        if ($update_stmt->execute()) {
            $response['success'] = true;
            $response['message'] = "Meeting ended successfully.";
        } else {
            throw new Exception("Failed to update meeting status");
        }
        
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = "Error ending meeting: " . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Get event details
$event_query = "SELECT event_name FROM events WHERE id = ?";
if ($stmt = $conn->prepare($event_query)) {
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $event = $result->fetch_assoc();
    $stmt->close();
}

// Get meetings with task count
$meetings_query = "
    SELECT m.*, 
           COUNT(t.task_id) as task_count 
    FROM meetings m 
    LEFT JOIN tasks t ON m.meeting_id = t.meeting_id 
    WHERE m.event_id = ? 
    GROUP BY m.meeting_id 
    ORDER BY m.meeting_date DESC, m.meeting_time DESC";

$meetings = [];
if ($stmt = $conn->prepare($meetings_query)) {
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $meetings[] = $row;
    }
    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Meetings - <?php echo htmlspecialchars($event['event_name']); ?></title>
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
            color: #fff;
        }

        .sidebar {
            width: 260px;
            background: rgba(0, 238, 255, 0.1);
            border-right: 2px solid #0ef;
            padding: 20px;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
        }

        .sidebar-header {
            padding: 20px 0;
            text-align: center;
            border-bottom: 1px solid #0ef;
            margin-bottom: 20px;
        }

        .sidebar-header h2 {
            color: #0ef;
            font-size: 20px;
            margin-bottom: 10px;
        }

        .nav-links {
            list-style: none;
        }

        .nav-links li {
            margin-bottom: 10px;
        }

        .nav-links a {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            text-decoration: none;
            color: #fff;
            border-radius: 8px;
            transition: all 0.3s ease;
            gap: 10px;
        }

        .nav-links a:hover {
            background: rgba(0, 238, 255, 0.1);
            color: #0ef;
        }

        .nav-links a.active {
            background: #0ef;
            color: #081b29;
        }

        .main-content {
            margin-left: 260px;
            padding: 20px;
        }

        .content-header {
            background: rgba(0, 238, 255, 0.1);
            border: 2px solid #0ef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .content-section {
            background: rgba(0, 238, 255, 0.1);
            border: 2px solid #0ef;
            border-radius: 10px;
            padding: 20px;
        }

        .meetings-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .meetings-table th,
        .meetings-table td {
            padding: 15px;
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

        .badge {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-scheduled {
            background: rgba(0, 255, 0, 0.2);
            color: #0f0;
        }

        .badge-ended {
            background: rgba(255, 0, 0, 0.2);
            color: #f00;
        }

        .badge-progress {
            background: rgba(255, 165, 0, 0.2);
            color: #ffa500;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            border: none;
            margin-right: 5px;
        }

        .btn-danger {
            background: transparent;
            border: 1px solid #ff4444;
            color: #ff4444;
        }

        .btn-warning {
            background: transparent;
            border: 1px solid #ffa500;
            color: #ffa500;
        }

        .btn-danger:hover {
            background: #ff4444;
            color: #fff;
        }

        .btn-warning:hover {
            background: #ffa500;
            color: #fff;
        }

        .success-message, .error-message {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .success-message {
            background: rgba(0, 255, 0, 0.1);
            border: 1px solid #00ff00;
            color: #00ff00;
        }

        .error-message {
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid #ff0000;
            color: #ff0000;
        }

        .search-box {
            margin-bottom: 20px;
        }

        .search-box input {
            width: 100%;
            padding: 10px;
            border: 1px solid #0ef;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .search-box input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }
    </style>
</head>
<body>
   <!-- Sidebar -->
   <div class="sidebar">
        <div class="sidebar-header">
            <h2><?php echo htmlspecialchars($event['event_name']); ?></h2>
            <p>Management Panel</p>
        </div>
        <ul class="nav-links">
            <li>
                <a href="manage_event.php?event_id=<?php echo $event_id; ?>" 
                   class="<?php echo $current_page === 'manage_event.php' ? 'active' : ''; ?>">
                    <i class='bx bx-grid-alt'></i> Dashboard
                </a>
            </li>
            <li>
                <a href="manage_members.php?event_id=<?php echo $event_id; ?>"
                   class="<?php echo $current_page === 'manage_members.php' ? 'active' : ''; ?>">
                    <i class='bx bx-user'></i> Members
                </a>
            </li>
            <li>
                <a href="add-committee.php?event_id=<?php echo $event_id; ?>"
                   class="<?php echo $current_page === 'add-committee.php' ? 'active' : ''; ?>">
                    <i class='bx bx-user-plus'></i> Committee
                </a>
            </li>
            <li>
                <a href="manage-meetings.php?event_id=<?php echo $event_id; ?>"
                   class="<?php echo $current_page === 'manage-meetings.php' ? 'active' : ''; ?>">
                    <i class='bx bx-timer'></i> Meetings
                </a>
            </li>
         
        </ul>
        <div style="margin-top: auto; padding: 20px 0;">
            <a href="dashboard.php" class="btn danger" style="width: 100%;">
                <i class='bx bx-arrow-back'></i> Exit Management
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="content-header">
            <h1>Manage Meetings</h1>
            <p>Event: <?php echo htmlspecialchars($event['event_name']); ?></p>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message"><?php echo htmlspecialchars($_SESSION['success_message']); ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="error-message"><?php echo htmlspecialchars($_SESSION['error_message']); ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="content-section">
            <div class="search-box">
                <input type="text" id="meetingSearch" placeholder="Search meetings..." onkeyup="searchMeetings()">
            </div>

            <table class="meetings-table" id="meetingsTable">
                <thead>
                    <tr>
                        <th>Meeting Type</th>
                        <th>Date</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Status</th>
                        <th>Tasks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($meetings as $meeting): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(ucfirst($meeting['meeting_type'])); ?></td>
                        <td><?php echo date('M d, Y', strtotime($meeting['meeting_date'])); ?></td>
                        <td><?php echo date('h:i A', strtotime($meeting['meeting_time'])); ?></td>
                        <td><?php echo date('h:i A', strtotime($meeting['end_time'])); ?></td>
                        <td>
                            <span class="badge badge-<?php echo strtolower(str_replace(' ', '', $meeting['status'])); ?>">
                                <?php echo htmlspecialchars($meeting['status']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-info">
                                <?php echo $meeting['task_count']; ?> tasks
                            </span>
                        </td>
                        <td>
    <?php if ($meeting['status'] !== 'Ended'): ?>
    <button type="button" class="btn btn-warning" 
            onclick="endMeeting(<?php echo $meeting['meeting_id']; ?>)">
        <i class='bx bx-time'></i> End Meeting
    </button>
    <?php endif; ?>
    <form method="POST" style="display: inline;">
        <input type="hidden" name="meeting_id" value="<?php echo $meeting['meeting_id']; ?>">
        <button type="submit" name="delete_meeting" class="btn btn-danger"
                onclick="return confirm('Warning: This will also delete all tasks associated with this meeting. Are you sure you want to proceed?')">
            <i class='bx bx-trash'></i> Delete
        </button>
    </form>
</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function searchMeetings() {
            const input = document.getElementById('meetingSearch');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('meetingsTable');
            const rows = table.getElementsByTagName('tr');

            for (let i = 1; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const text = cell.textContent || cell.innerText;
                        if (text.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        }
        function endMeeting(meetingId) {
    if (!confirm('Are you sure you want to end this meeting?')) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'end_meeting');
    formData.append('meeting_id', meetingId);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Find and update the meeting row
            const row = document.querySelector(`button[onclick="endMeeting(${meetingId})"]`).closest('tr');
            const statusCell = row.querySelector('td:nth-child(5)');
            const actionCell = row.querySelector('td:last-child');
            
            // Update status
            statusCell.innerHTML = '<span class="badge badge-ended">Ended</span>';
            
            // Remove the end meeting button
            const endButton = actionCell.querySelector('.btn-warning');
            if (endButton) {
                endButton.remove();
            }
            
            // Show success message
            alert(data.message);
        } else {
            alert(data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while ending the meeting');
    });
}
    </script>
</body>
</html>