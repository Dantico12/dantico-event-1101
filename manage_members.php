<?php
session_start();
require 'db.php';

function removeMemberAndTasks($conn, $event_id, $member_id) {
    $conn->begin_transaction();
    try {
        // Delete tasks assigned to the member
        $task_stmt = $conn->prepare("DELETE FROM tasks WHERE event_id = ? AND assigned_to = ?");
        $task_stmt->bind_param("ii", $event_id, $member_id);
        $task_stmt->execute();
        $task_stmt->close();

        // Delete the event member record
        $member_stmt = $conn->prepare("DELETE FROM event_members WHERE event_id = ? AND user_id = ?");
        $member_stmt->bind_param("ii", $event_id, $member_id);
        $member_stmt->execute();
        $member_stmt->close();

        // Record this removal in a new table to track removed members
        $removed_stmt = $conn->prepare("INSERT INTO removed_members (event_id, user_id, removed_at) VALUES (?, ?, NOW())");
        $removed_stmt->bind_param("ii", $event_id, $member_id);
        $removed_stmt->execute();
        $removed_stmt->close();

        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        return false;
    }
}

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

// Handle member removal

// Replace your existing member removal code with this:
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_member'])) {
    $member_id = (int)$_POST['member_id'];
    
    if (removeMemberAndTasks($conn, $event_id, $member_id)) {
        $_SESSION['success_message'] = "Member and associated tasks removed successfully.";
    } else {
        $_SESSION['error_message'] = "Error removing member. Please try again.";
    }
    
    header("Location: manage_members.php?event_id=" . $event_id);
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

$members_query = "SELECT 
    em.user_id,
    em.event_id,
    em.role,
    em.committee_role,
    em.joined_at,
    em.status,
    u.username,
    u.email
FROM event_members em
JOIN users u ON em.user_id = u.id
WHERE em.event_id = ? 
AND em.status = 'active'
ORDER BY em.role DESC, u.username ASC";

$members = [];
if ($stmt = $conn->prepare($members_query)) {
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - <?php echo htmlspecialchars($event['event_name']); ?></title>
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

        .members-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .members-table th,
        .members-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 238, 255, 0.2);
        }

        .members-table th {
            background: rgba(0, 238, 255, 0.1);
            color: #0ef;
            font-weight: 500;
        }

        .members-table tr:hover {
            background: rgba(0, 238, 255, 0.05);
        }

        .badge {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-admin {
            background: rgba(0, 238, 255, 0.2);
            color: #0ef;
        }

        .badge-member {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            border: none;
        }

        .btn-danger {
            background: transparent;
            border: 1px solid #ff4444;
            color: #ff4444;
        }

        .btn-danger:hover {
            background: #ff4444;
            color: #fff;
        }

        .success-message {
            background: rgba(0, 255, 0, 0.1);
            border: 1px solid #00ff00;
            color: #00ff00;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .error-message {
            background: rgba(255, 0, 0, 0.1);
            border: 1px solid #ff0000;
            color: #ff0000;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .search-box {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 10px;
            border: 1px solid #0ef;
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .search-box input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .member-count {
            color: #0ef;
            margin-bottom: 15px;
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
                <a href="add_committee.php?event_id=<?php echo $event_id; ?>"
                   class="<?php echo $current_page === 'add_committee.php' ? 'active' : ''; ?>">
                    <i class='bx bx-user-plus'></i> Committee
                </a>
            </li>
            <li>
                <a href="manage-meetings.php?event_id=<?php echo $event_id; ?>"
                   class="<?php echo $current_page === 'manage-meetings.php' ? 'active' : ''; ?>">
                    <i class='bx bx-user-plus'></i> Meetings
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
            <h1>Manage Members</h1>
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
                <input type="text" id="memberSearch" placeholder="Search members..." onkeyup="searchMembers()">
            </div>

            <div class="member-count">
                Total Members: <?php echo count($members); ?>
            </div>
            
<table class="members-table" id="membersTable">
    <thead>
        <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Role</th>
            <th>Committee Role</th>
            <th>Joined Date</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($members as $member): ?>
        <tr>
            <td><?php echo htmlspecialchars($member['username']); ?></td>
            <td><?php echo htmlspecialchars($member['email']); ?></td>
        
            <td>
                <span class="badge <?php echo $member['role'] === 'admin' ? 'badge-admin' : 'badge-member'; ?>">
                    <?php echo ucfirst(htmlspecialchars($member['role'])); ?>
                </span>
            </td>
            <td>
                <?php echo $member['committee_role'] ? htmlspecialchars(ucfirst($member['committee_role'])) : '-'; ?>
            </td>
            <td><?php echo date('M d, Y', strtotime($member['joined_at'])); ?></td>
            <td>
                <?php if ($member['role'] !== 'admin'): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="member_id" value="<?php echo $member['user_id']; ?>">
                    <button type="submit" name="remove_member" class="btn btn-danger"
                            onclick="return confirm('Are you sure you want to remove this member?')">
                        <i class='bx bx-user-x'></i> Remove
                    </button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
        </div>
    </div>

    <script>
        function searchMembers() {
            const input = document.getElementById('memberSearch');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('membersTable');
            const rows = table.getElementsByTagName('tr');

            for (let i = 1; i < rows.length; i++) {
                const nameCol = rows[i].getElementsByTagName('td')[0];
                const emailCol = rows[i].getElementsByTagName('td')[1];
                const phoneCol = rows[i].getElementsByTagName('td')[2];
                
                if (nameCol && emailCol && phoneCol) {
                    const name = nameCol.textContent || nameCol.innerText;
                    const email = emailCol.textContent || emailCol.innerText;
                    const phone = phoneCol.textContent || phoneCol.innerText;
                    
                    if (name.toLowerCase().indexOf(filter) > -1 || 
                        email.toLowerCase().indexOf(filter) > -1 || 
                        phone.toLowerCase().indexOf(filter) > -1) {
                        rows[i].style.display = '';
                    } else {
                        rows[i].style.display = 'none';
                    }
                }
            }
        }
    </script>
</body>
</html>