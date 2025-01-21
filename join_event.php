<?php
session_start();
require 'db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle event code submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_code'])) {
    $event_code = strtoupper(trim($_POST['event_code']));
    
    // Check if event exists
    if ($stmt = $conn->prepare("SELECT id, name FROM events WHERE event_code = ? AND status = 'active'")) {
        $stmt->bind_param("s", $event_code);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $event = $result->fetch_assoc();
            
            if ($event) {
                // Check if user is already a member
                if ($check_stmt = $conn->prepare("SELECT id FROM event_members WHERE event_id = ? AND user_id = ?")) {
                    $check_stmt->bind_param("ii", $event['id'], $_SESSION['user_id']);
                    if ($check_stmt->execute()) {
                        $check_result = $check_stmt->get_result();
                        
                        if ($check_result->num_rows === 0) {
                            // Join the event
                            if ($join_stmt = $conn->prepare("INSERT INTO event_members (event_id, user_id, role, joined_via_link, status) VALUES (?, ?, 'member', 0, 'active')")) {
                                $join_stmt->bind_param("ii", $event['id'], $_SESSION['user_id']);
                                if ($join_stmt->execute()) {
                                    $_SESSION['success_message'] = "Successfully joined: " . htmlspecialchars($event['name']);
                                    header("Location: dashboard.php?event_id=" . $event['id'] . "&event_code=" . urlencode($event_code));
                                    exit();
                                } else {
                                    $error = "Error joining event. Please try again.";
                                }
                                $join_stmt->close();
                            }
                        } else {
                            $error = "You're already a member of this event.";
                        }
                    }
                    $check_stmt->close();
                }
            } else {
                $error = "Invalid event code. Please try again.";
            }
        }
        $stmt->close();
    }
}

// Fetch joined events
$joined_events = [];
$events_query = "
    SELECT 
        e.*,
        em.joined_at,
        em.role,
        em.committee_role,
        em.status
    FROM events e 
    JOIN event_members em ON e.id = em.event_id 
    WHERE em.user_id = ? AND em.status = 'active'
    ORDER BY em.joined_at DESC
";

if ($stmt = $conn->prepare($events_query)) {
    $stmt->bind_param("i", $_SESSION['user_id']);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $joined_events = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Event</title>
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
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .wrapper {
            position: relative;
            width: 750px;
            height: auto;
            min-height: 450px;
            background: transparent;
            border: 2px solid #0ef;
            box-shadow: 0 0 25px #0ef;
            padding: 40px;
            overflow: hidden;
        }

        .form-box {
            position: relative;
            z-index: 1;
        }

        h2 {
            font-size: 32px;
            color: #fff;
            text-align: center;
            margin-bottom: 30px;
        }

        .input-box {
            position: relative;
            width: 100%;
            height: 50px;
            margin: 25px 0;
        }

        .input-box input {
            width: 100%;
            height: 100%;
            background: transparent;
            border: none;
            outline: none;
            border-bottom: 2px solid #fff;
            padding-right: 23px;
            font-size: 16px;
            color: #fff;
            font-weight: 500;
            transition: .5s;
            text-transform: uppercase;
        }

        .input-box input:focus,
        .input-box input:valid {
            border-bottom-color: #0ef;
        }

        .input-box label {
            position: absolute;
            top: 50%;
            left: 0;
            transform: translateY(-50%);
            font-size: 16px;
            color: #fff;
            pointer-events: none;
            transition: .5s;
        }

        .input-box input:focus ~ label,
        .input-box input:valid ~ label {
            top: -5px;
            color: #0ef;
        }

        .btn {
            position: relative;
            width: 100%;
            height: 45px;
            background: transparent;
            border: 2px solid #0ef;
            outline: none;
            border-radius: 40px;
            cursor: pointer;
            font-size: 16px;
            color: #fff;
            font-weight: 600;
            z-index: 1;
            overflow: hidden;
            margin-top: 20px;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: -100%;
            left: 0;
            width: 100%;
            height: 300%;
            background: linear-gradient(#081b29, #0ef, #081b29, #0ef);
            z-index: -1;
            transition: .5s;
        }

        .btn:hover::before {
            top: 0;
        }

        .bg-animate {
            position: absolute;
            top: -4px;
            right: 0;
            width: 850px;
            height: 600px;
            background: linear-gradient(45deg, #081b29, #0ef);
            border-bottom: 3px solid #0ef;
            transform: rotate(10deg) skewY(40deg);
            transform-origin: bottom right;
            transition: 1.5s ease;
        }

        .error-message {
            color: #ff4444;
            text-align: center;
            margin-top: 10px;
            background: rgba(255, 68, 68, 0.1);
            padding: 10px;
            border-radius: 5px;
            border: 1px solid #ff4444;
        }

        .joined-events {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #0ef;
        }

        .joined-events h3 {
            color: #fff;
            text-align: center;
            margin-bottom: 20px;
            font-size: 24px;
        }

        .event-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
        }

        .event-card {
            background: rgba(0, 238, 255, 0.1);
            border: 1px solid #0ef;
            border-radius: 10px;
            padding: 15px;
            color: #fff;
            transition: all 0.3s ease;
        }

        .event-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 238, 255, 0.3);
        }

        .event-card h4 {
            color: #0ef;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .event-card p {
            margin: 5px 0;
            font-size: 14px;
        }

        .role-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-bottom: 8px;
        }

        .role-admin {
            background: #0ef;
            color: #081b29;
        }

        .role-member {
            background: rgba(0, 238, 255, 0.3);
            color: #0ef;
        }

        .event-link {
            text-decoration: none;
            color: inherit;
        }

        /* New styles for event card actions */
        .event-card-actions {
            display: flex;
            justify-content: center;
            margin-top: 10px;
            gap: 10px;
        }

        .event-action-btn {
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            cursor: pointer;
            border: 1px solid #0ef;
            background: transparent;
            color: #0ef;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .event-action-btn:hover {
            background: #0ef;
            color: #081b29;
        }

        .event-action-btn.manage {
            border-color: #ff9900;
            color: #ff9900;
        }

        .event-action-btn.manage:hover {
            background: #ff9900;
            color: #081b29;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="bg-animate"></div>
        <div class="form-box">
            <h2>Join Event</h2>
            
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <div class="input-box">
                    <input type="text" required name="event_code" id="event_code" maxlength="10">
                    <label>Event Code</label>
                </div>
                
                <?php if (isset($error)): ?>
                    <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
                
                <button type="submit" class="btn">Join Event</button>
            </form>

            <?php if (!empty($joined_events)): ?>
            <div class="joined-events">
                <h3>Your Events</h3>
                <div class="event-cards">
                    <?php foreach ($joined_events as $event): ?>
                    <div class="event-card">
                        <span class="role-badge role-<?php echo strtolower($event['role']); ?>">
                            <?php echo htmlspecialchars($event['role']); ?>
                        </span>
                        <h4><?php echo htmlspecialchars($event['name']); ?></h4>
                        <p>
                            <i class='bx bx-calendar'></i>
                            <?php echo date('M d, Y', strtotime($event['joined_at'])); ?>
                        </p>
                        
                        <?php if ($event['committee_role']): ?>
                        <p>
                            <i class='bx bx-user-pin'></i>
                            <?php echo htmlspecialchars($event['committee_role']); ?>
                        </p>
                        <?php endif; ?>
                        
                        <div class="event-card-actions">
                            <?php if ($event['role'] === 'admin'): ?>
                                <a href="manage_event.php?event_id=<?php echo $event['id']; ?>" class="event-action-btn manage">
                                    Manage Event
                                </a>
                                <a href="dashboard.php?event_id=<?php echo $event['id']; ?>&event_code=<?php echo urlencode($event['event_code']); ?>" 
                                   class="event-action-btn">
                                    Join Event
                                </a>
                            <?php else: ?>
                                <a href="dashboard.php?event_id=<?php echo $event['id']; ?>&event_code=<?php echo urlencode($event['event_code']); ?>" 
                                   class="event-action-btn">
                                    Join Event
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('event_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    </script>
</body>
</html>