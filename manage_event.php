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

// Updated query without created_by reference
$event_query = "
    SELECT 
        e.*,
        COUNT(DISTINCT em.user_id) as total_members,
        SUM(CASE WHEN em.committee_role IS NOT NULL THEN 1 ELSE 0 END) as committee_members
    FROM events e
    LEFT JOIN event_members em ON e.id = em.event_id
    WHERE e.id = ?
    GROUP BY e.id
";

if ($stmt = $conn->prepare($event_query)) {
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $event = $result->fetch_assoc();
    $stmt->close();
}

// Handle end event action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_event'])) {
    if ($stmt = $conn->prepare("UPDATE events SET status = 'ended', ended_at = NOW() WHERE id = ?")) {
        $stmt->bind_param("i", $event_id);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Event has been ended successfully.";
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Error ending the event. Please try again.";
        }
        $stmt->close();
    }
}

// Get current page for navigation highlighting
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Event - <?php echo htmlspecialchars($event['event_name']); ?></title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: #081b29;
            color: #fff;
            display: flex;
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
            flex: 1;
            margin-left: 260px;
            padding: 20px;
            width: calc(100% - 260px);
        }

        .content-header {
            background: rgba(0, 238, 255, 0.1);
            border: 2px solid #0ef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .content-header h1 {
            color: #0ef;
            font-size: 24px;
        }

        .event-overview {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .event-details {
            background: rgba(0, 238, 255, 0.1);
            border: 2px solid #0ef;
            border-radius: 10px;
            padding: 20px;
        }

        .event-actions {
            background: rgba(0, 238, 255, 0.1);
            border: 2px solid #0ef;
            border-radius: 10px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: rgba(0, 238, 255, 0.1);
            border: 1px solid #0ef;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            color: #0ef;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.8;
        }

        .detail-item {
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(0, 238, 255, 0.2);
            padding-bottom: 15px;
        }

        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .detail-label {
            color: #0ef;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 16px;
        }

        .btn {
            background: transparent;
            border: 2px solid #0ef;
            color: #fff;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            background: #0ef;
            color: #081b29;
        }

        .btn.danger {
            border-color: #ff4444;
            color: #ff4444;
        }

        .btn.danger:hover {
            background: #ff4444;
            color: #fff;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: #081b29;
            border: 2px solid #0ef;
            border-radius: 10px;
            padding: 30px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
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
          
        </ul>
        <div style="margin-top: auto; padding: 20px 0;">
            <a href="dashboard.php" class="btn danger" style="width: 100%;">
                <i class='bx bx-arrow-back'></i> Exit Management
            </a>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message"><?php echo htmlspecialchars($_SESSION['success_message']); ?></div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="content-header">
            <h1>Event Dashboard</h1>
        </div>

        <div class="stats-container">
            <div class="stat-box">
                <div class="stat-value"><?php echo $event['total_members']; ?></div>
                <div class="stat-label">Total Members</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?php echo $event['committee_members']; ?></div>
                <div class="stat-label">Committee Members</div>
            </div>
            <div class="stat-box">
                <div class="stat-value"><?php echo ucfirst($event['status']); ?></div>
                <div class="stat-label">Event Status</div>
            </div>
        </div>

        <div class="event-overview">
            <div class="event-details">
                <h2 style="color: #0ef; margin-bottom: 20px;">Event Information</h2>
                
                <div class="detail-item">
                    <div class="detail-label">Event Name</div>
                    <div class="detail-value"><?php echo htmlspecialchars($event['event_name']); ?></div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Event Code</div>
                    <div class="detail-value"><?php echo htmlspecialchars($event['event_code']); ?></div>
                </div>

                <div class="detail-item">
                    <div class="detail-label">Created Date</div>
                    <div class="detail-value"><?php echo date('F d, Y', strtotime($event['created_at'])); ?></div>
                </div>

                <?php if ($event['phone_paybill']): ?>
                <div class="detail-item">
                    <div class="detail-label">Paybill number</div>
                    <div class="detail-value"><?php echo nl2br(htmlspecialchars($event['phone_paybill'])); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="event-actions">
                <h2 style="color: #0ef; margin-bottom: 20px;">Quick Actions</h2>
                
                <button class="btn" onclick="window.location.href='manage_members.php?event_id=<?php echo $event_id; ?>'">
                    <i class='bx bx-user'></i> Manage Members
                </button>
                
                <button class="btn" onclick="window.location.href='manage_committee.php?event_id=<?php echo $event_id; ?>'">
                    <i class='bx bx-user-plus'></i> Manage Committee
                </button>
                
                <button class="btn" onclick="window.location.href='manage_settings.php?event_id=<?php echo $event_id; ?>'">
                    <i class='bx bx-cog'></i> Event Settings
                </button>
                
                <?php if ($event['status'] === 'active'): ?>
                <button class="btn danger" onclick="showEndEventModal()">
                    <i class='bx bx-power-off'></i> End Event
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- End Event Modal -->
    <div id="endEventModal" class="modal">
        <div class="modal-content">
        <h2 style="color: #ff4444; margin-bottom: 20px;">End Event</h2>
            <p>Are you sure you want to end this event? This action cannot be undone.</p>
            <div class="modal-buttons">
                <form method="POST">
                    <button type="submit" name="end_event" class="btn danger">Yes, End Event</button>
                </form>
                <button class="btn" onclick="hideEndEventModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        function showEndEventModal() {
            document.getElementById('endEventModal').style.display = 'flex';
        }

        function hideEndEventModal() {
            document.getElementById('endEventModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('endEventModal');
            if (event.target === modal) {
                hideEndEventModal();
            }
        }
    </script>
</body>
</html>