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

// Verify user is admin
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

// Get event details
$event_query = "SELECT event_name FROM events WHERE id = ?";
if ($stmt = $conn->prepare($event_query)) {
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $event = $result->fetch_assoc();
    $stmt->close();
}

// Fetch available members (who aren't already committee members)
$members_query = "
    SELECT 
        u.id,
        u.username,
        u.email
    FROM event_members em
    JOIN users u ON em.user_id = u.id
    WHERE em.event_id = ? 
    AND em.status = 'active'
    AND em.committee_role IS NULL
    ORDER BY u.username ASC

";

$available_members = [];
if ($stmt = $conn->prepare($members_query)) {
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_members[] = $row;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = (int)$_POST['member_id'];
    $committee_role = $_POST['committee_role'];

    // Check if role is already taken
    $role_check_query = "
        SELECT COUNT(*) as count 
        FROM event_members 
        WHERE event_id = ? 
        AND committee_role = ? 
        AND status = 'active'
    ";

    $role_taken = false;
    if ($stmt = $conn->prepare($role_check_query)) {
        $stmt->bind_param("is", $event_id, $committee_role);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $role_taken = ($row['count'] > 0);
        $stmt->close();
    }

    if ($role_taken && in_array($committee_role, ['chairman', 'secretary', 'treasurer'])) {
        $_SESSION['error_message'] = "This committee role is already taken.";
    } else {
        // Add member to committee
        if ($stmt = $conn->prepare("UPDATE event_members SET committee_role = ? WHERE event_id = ? AND user_id = ?")) {
            $stmt->bind_param("sii", $committee_role, $event_id, $member_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Committee member added successfully.";
                
            } else {
                $_SESSION['error_message'] = "Error adding committee member.";
            }
            $stmt->close();
        }
    }
}

// Get current committee members
$committee_query = "
    SELECT 
        u.id,
        u.username,
        u.email,
        em.committee_role,
        em.joined_at
    FROM event_members em
    JOIN users u ON em.user_id = u.id
    WHERE em.event_id = ?
    AND em.committee_role IS NOT NULL
    ORDER BY 
        CASE em.committee_role 
            WHEN 'chairman' THEN 1
            WHEN 'secretary' THEN 2
            WHEN 'treasurer' THEN 3
            ELSE 4
        END,
        u.username
";


$committee_members = [];
if ($stmt = $conn->prepare($committee_query)) {
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $committee_members[] = $row;
    }
    $stmt->close();
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Committee - <?php echo htmlspecialchars($event['event_name']); ?></title>
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

        .form-container {
            color: #fff;
            padding: 20px;
            max-width: 600px;
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #0ef;
            font-weight: 500;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid #0ef;
            border-radius: 5px;
            color: #080707;
            font-size: 16px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            box-shadow: 0 0 5px rgba(0, 238, 255, 0.5);
        }

        .btn-container {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #0ef;
            color: #081b29;
        }

        .btn-secondary {
            background: transparent;
            border: 1px solid #0ef;
            color: #0ef;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 238, 255, 0.3);
        }

        .section-title {
            color: #0ef;
            margin-bottom: 20px;
            font-size: 24px;
            border-bottom: 2px solid #0ef;
            padding-bottom: 10px;
            text-align: center;
        }
         
        .committee-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: rgba(255, 255, 255, 0.1);
        }

        .committee-table th,
        .committee-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 238, 255, 0.2);
            color: #fff;
        }

        .committee-table th {
            background: rgba(0, 238, 255, 0.2);
            color: #0ef;
        }

        .role-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            text-transform: capitalize;
        }

        .role-chairman { background: rgba(0, 255, 0, 0.1); color: #0f0; }
        .role-secretary { background: rgba(0, 191, 255, 0.1); color: #0bf; }
        .role-treasurer { background: rgba(255, 165, 0, 0.1); color: #fa0; }
        .role-member { background: rgba(255, 255, 255, 0.1); color: #fff; }
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
                    <i class='bx bx-user-plus'></i> Committee
                </a>
            </li>
         
        </ul>
        <div style="margin-top: auto; padding: 20px 0;">
            <a href="dashboard.php" class="btn danger" style="width: 100%;">
                <i class='bx bx-arrow-back'></i> Exit Management
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <button class="toggle-btn">
                <i class='bx bx-menu'></i>
            </button>
            <h2 class="header-title">Committee Management</h2>
            <div class="header-actions">
                <i class='bx bx-search'></i>
                <i class='bx bx-bell'></i>
                <i class='bx bx-user-circle'></i>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="message success-message">
                <?php echo htmlspecialchars($_SESSION['success_message']); 
                      unset($_SESSION['success_message']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="message error-message">
                <?php echo htmlspecialchars($_SESSION['error_message']); 
                      unset($_SESSION['error_message']); ?>
            </div>
        <?php endif; ?>

        <div class="content-section">
            <h3 class="section-title">Add New Committee Member</h3>
            <form method="POST" class="form-container">
                <div class="form-group">
                    <label for="member_id">Select Member</label>
                    <select id="member_id" name="member_id" required>
                        <option value="">Choose a member</option>
                        <?php foreach ($available_members as $member): ?>
                            <option value="<?php echo $member['id']; ?>">
                                <?php echo htmlspecialchars($member['username'] . ' (' . $member['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="committee_role">Committee Role</label>
                    <select id="committee_role" name="committee_role" required>
                        <option value="">Select role</option>
                        <option value="chairman">Chairman</option>
                        <option value="secretary">Secretary</option>
                        <option value="treasurer">Treasurer</option>
                        <option value="member">Committee Member</option>
                    </select>
                </div>

                <div class="btn-container">
                    <button type="button" class="btn btn-secondary" onclick="window.history.back()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add to Committee</button>
                </div>
            </form>
        </div>

        <div class="content-section">
            <h3 class="section-title">Current Committee Members</h3>
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
                        <td><?php echo htmlspecialchars($member['username']); ?></td>
                        <td>
                            <span class="role-badge role-<?php echo htmlspecialchars($member['committee_role']); ?>">
                                <?php echo ucfirst(htmlspecialchars($member['committee_role'])); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($member['email']); ?></td>
                 
                        <td><?php echo date('M d, Y', strtotime($member['joined_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
    </script>
</body>
</html>