<?php
session_start();
require_once 'db.php'; // Database connection

// Ensure paybill column exists
$column_check_query = "SHOW COLUMNS FROM events LIKE 'phone_paybill'";
$column_result = $conn->query($column_check_query);

if ($column_result->num_rows == 0) {
    $alter_table_query = "ALTER TABLE events ADD COLUMN phone_paybill VARCHAR(50) NULL";
    if (!$conn->query($alter_table_query)) {
        die("Failed to add paybill column: " . $conn->error);
    }
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

// Get the event context URL to be used across navigation
$base_url = getEventContextURL();

// Validate and sanitize event context from URL or session
$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 
            (isset($_SESSION['current_event_id']) ? intval($_SESSION['current_event_id']) : null);
$event_code = isset($_GET['event_code']) ? $conn->real_escape_string($_GET['event_code']) : 
              (isset($_SESSION['current_event_code']) ? $conn->real_escape_string($_SESSION['current_event_code']) : null);

// Validate event context
if (!$event_id || !$event_code) {
    die("Invalid event context. Please select an event.");
}

// Verify event exists and matches the code
$event_verify_query = "SELECT id, event_name, event_code FROM events WHERE id = ? AND event_code = ?";
$stmt = $conn->prepare($event_verify_query);
$stmt->bind_param('is', $event_id, $event_code);
$stmt->execute();
$event_result = $stmt->get_result();

if ($event_result->num_rows === 0) {
    die("Event not found or invalid event code");
}

$event_details = $event_result->fetch_assoc();
$stmt->close();

// Initialize variables
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate paybill input
    $paybill_number = isset($_POST['paybill_number']) ? trim($_POST['paybill_number']) : '';

    if (empty($paybill_number)) {
        $error_message = "Paybill number cannot be empty.";
    } else {
        // Update paybill number for this specific event
        $update_query = "UPDATE events SET phone_paybill = ? WHERE id = ? AND event_code = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param('sis', $paybill_number, $event_id, $event_code);

        if ($stmt->execute()) {
            $success_message = "Paybill number updated successfully for the event.";
        } else {
            $error_message = "Failed to update paybill number. " . $conn->error;
        }
        $stmt->close();
    }
}

// Retrieve current paybill number if exists
$paybill_query = "SELECT phone_paybill FROM events WHERE id = ? AND event_code = ?";
$stmt = $conn->prepare($paybill_query);
$stmt->bind_param('is', $event_id, $event_code);
$stmt->execute();
$paybill_result = $stmt->get_result();
$current_paybill = $paybill_result->fetch_assoc()['phone_paybill'] ?? '';
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phone Number Management - <?php echo htmlspecialchars($event_details['name']); ?></title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }

        body {
            background-color: #0f172a;
            color: #e2e8f0;
            line-height: 1.6;
            display: flex;
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

        .menu-item {
            padding: 12px 20px 12px 30px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
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

        .container {
            flex-grow: 1;
            padding: 2rem;
            max-width: 600px;
            margin: 0 auto;
        }

        .phone-card {
            background-color: #1e293b;
            border-radius: 0.5rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4ade80;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #4ade80;
            background-color: #0f172a;
            color: #e2e8f0;
            border-radius: 0.25rem;
        }

        .btn {
            display: inline-block;
            width: 100%;
            padding: 0.75rem 1.5rem;
            background-color: #4ade80;
            color: #0f172a;
            border: none;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .btn:hover {
            background-color: #22c55e;
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.25rem;
        }

        .alert-success {
            background-color: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border: 1px solid #22c55e;
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border: 1px solid #ef4444;
        }

        .event-info {
            background-color: #0f172a;
            padding: 1rem;
            border-radius: 0.25rem;
            margin-bottom: 1rem;
            text-align: center;
        }
        .paybill-card {
            background-color: #1e293b;
            border-radius: 0.5rem;
            padding: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
            <div class="category-title">paybill</div>
            <div class="menu-item">
                <a href="./paybill.php<?= $base_url ?>">
                    <i class='bx bx-plus-circle'></i>
                    <span>Add Paybill</span>
                </a>
            </div>
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
            <div class="category-title">Reviews</div>
            <div class="menu-item">
                <a href="./minutes.php<?= $base_url ?>">
                    <i class='bx bxs-timer'></i>
                    <span>Minutes</span>
                </a>
            </div>
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
<div class="container">
        <div class="paybill-card">
            <div class="event-info">
                <h2><?php echo htmlspecialchars($event_details['event_name']); ?></h2>
                <p>Event Code: <?php echo htmlspecialchars($event_code); ?></p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="?event_id=<?php echo $event_id; ?>&event_code=<?php echo urlencode($event_code); ?>">
                <div class="form-group">
                    <label for="paybill_number">Paybill Number</label>
                    <input 
                        type="text" 
                        name="paybill_number" 
                        id="paybill_number" 
                        class="form-control" 
                        placeholder="Enter Paybill Number" 
                        value="<?php echo htmlspecialchars($current_paybill); ?>"
                        required
                    >
                </div>

                <button type="submit" class="btn">Update Paybill Number</button>
            </form>

            <?php if (!empty($current_paybill)): ?>
                <div class="event-info mt-3">
                    <p>Current Paybill Number: <strong><?php echo htmlspecialchars($current_paybill); ?></strong></p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Form validation
        document.querySelector('form').addEventListener('submit', function(event) {
            const paybillInput = document.getElementById('paybill_number');

            if (!paybillInput.value.trim()) {
                alert('Please enter a paybill number');
                event.preventDefault();
            }
        });

        // Auto-hide messages
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => alert.style.display = 'none');
        }, 5000);
    </script>
</body>
</html>