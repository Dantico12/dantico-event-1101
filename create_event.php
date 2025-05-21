<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require 'db.php';
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

function generateEventCode($event_name) {
    // Break event name into words
    $words = preg_split('/\s+/', strtoupper(preg_replace('/[^A-Za-z\s]/', '', $event_name)));
    
    // Get first two letters of first two words
    $firstPart = '';
    if (count($words) >= 2) {
        $firstPart = substr($words[0], 0, 2) . substr($words[1], 0, 2);
    } elseif (count($words) == 1) {
        $firstPart = substr($words[0], 0, 4);
    } else {
        $firstPart = 'EVNT'; // fallback
    }

    // Generate random 2-digit number
    $randomNumber = rand(10, 99);

    return strtoupper($firstPart . $randomNumber);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST data received: " . print_r($_POST, true));
    
    // Validate required fields
    $required_fields = ['event_name', 'event_datetime', 'location', 'event_type', 'max_participants'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        $error_message = "Please fill in all required fields: " . implode(', ', $missing_fields);
        error_log("Missing fields: " . implode(', ', $missing_fields));
    } else {
        try {
            // Validate event date (must be at least 3 days from now)
            $event_datetime = $_POST['event_datetime'];
            $event_timestamp = strtotime($event_datetime);
            $min_allowed_timestamp = strtotime('+3 days');
            
            if ($event_timestamp < $min_allowed_timestamp) {
                $error_message = "Event date must be at least 3 days from today.";
                error_log("Invalid event date: " . $event_datetime);
            } else {
                // Start transaction
                $conn->begin_transaction();
    
                $event_name = trim($_POST['event_name']);
                $location = $_POST['location'];
                $event_type = $_POST['event_type'];
                $max_participants = $_POST['max_participants'];
                $phone_paybill = isset($_POST['phone_paybill']) ? $_POST['phone_paybill'] : null;
                
                // Generate unique event code
                do {
                    $event_code = generateEventCode($event_name);
                    $check_code = $conn->prepare("SELECT 1 FROM events WHERE event_code = ?");
                    $check_code->bind_param("s", $event_code);
                    $check_code->execute();
                    $result = $check_code->get_result();
                } while ($result->num_rows > 0);
                
                // Insert event with all fields
                $sql = "INSERT INTO events (
                    event_name, 
                    event_code, 
                    status, 
                    created_at,
                    phone_paybill,
                    event_type,
                    event_datetime,
                    location,
                    max_participants
                ) VALUES (?, ?, 'active', NOW(), ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                
                $stmt->bind_param("ssssssi", 
                    $event_name, 
                    $event_code, 
                    $phone_paybill,
                    $event_type,
                    $event_datetime,
                    $location,
                    $max_participants
                );
                
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                
                $event_id = $conn->insert_id;
                
                // Add creator as admin in event_members
                $member_sql = "INSERT INTO event_members (event_id, user_id, role, committee_role) VALUES (?, ?, 'admin', 'organizer')";
                $member_stmt = $conn->prepare($member_sql);
                
                if (!$member_stmt) {
                    throw new Exception("Prepare member insert failed: " . $conn->error);
                }
                
                $member_stmt->bind_param("ii", $event_id, $_SESSION['user_id']);
                
                if (!$member_stmt->execute()) {
                    throw new Exception("Execute member insert failed: " . $member_stmt->error);
                }
                
                $conn->commit();
                
                error_log("Event created successfully with ID: " . $event_id);
                
                $_SESSION['success_message'] = "Event created successfully!";
                header("Location: dashboard.php?event_id=" . $event_id);
                exit();
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error creating event: " . $e->getMessage();
            error_log("Error in event creation: " . $e->getMessage());
        }
    }
}

// Calculate minimum date for client-side validation (3 days from now)
$min_date = date('Y-m-d\TH:i', strtotime('+3 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - Dantico Events</title>
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

        .input-box input,
        .input-box select,
        .input-box textarea {
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
        }

        .input-box input:focus,
        .input-box input:valid,
        .input-box select:focus,
        .input-box select:valid,
        .input-box textarea:focus,
        .input-box textarea:valid {
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
        .input-box input:valid ~ label,
        .input-box select:focus ~ label,
        .input-box select:valid ~ label,
        .input-box textarea:focus ~ label,
        .input-box textarea:valid ~ label {
            top: -5px;
            color: #0ef;
        }

        .form-actions {
            text-align: center;
            margin-top: 20px;
        }

        .create-btn,
        .cancel-btn {
            width: 45%;
            height: 45px;
            background: transparent;
            border: 2px solid #0ef;
            outline: none;
            border-radius: 40px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            font-size: 16px;
            transition: 0.5s;
        }

        .create-btn:hover,
        .cancel-btn:hover {
            background: #0ef;
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
            color: #f00;
            text-align: center;
            margin-bottom: 15px;
            background: rgba(255, 0, 0, 0.1);
            padding: 10px;
            border-radius: 5px;
        }
        
        .date-info {
            color: #0ef;
            text-align: center;
            font-size: 14px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="bg-animate"></div>
        <div class="form-box">
            <h2>Create New Event</h2>

            <?php if (isset($error_message)): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="event-form" id="eventForm">
                <div class="input-box">
                    <input type="text" id="event_name" name="event_name" required>
                    <label for="event_name">Event Name*</label>
                </div>

                <div class="input-box">
                    <input type="datetime-local" id="event_datetime" name="event_datetime" min="<?php echo $min_date; ?>" required>
                    <label for="event_datetime">Date and Time*</label>
                </div>
                <div class="date-info">Events must be scheduled at least 3 days from today</div>

                <div class="input-box">
                    <input type="text" id="location" name="location" required>
                    <label for="location">Location*</label>
                </div>

                <div class="input-box">
                    <select id="event_type" name="event_type" required>
                        <option value="meeting">Wedding</option>
                        <option value="conference">Dowry</option>
                        <option value="workshop">Burial</option>
                        <option value="social">Parties and meeting</option>
                        <option value="other">Other</option>
                    </select>
                    <label for="event_type">Event Type*</label>
                </div>

                <div class="input-box">
                    <input type="number" id="max_participants" name="max_participants" min="1" required>
                    <label for="max_participants">Max Participants*</label>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="create-btn">Create Event</button>
                    <a href="dashboard.php" class="cancel-btn">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const eventForm = document.getElementById('eventForm');
            const datetimeInput = document.getElementById('event_datetime');
            
            eventForm.addEventListener('submit', function(e) {
                const selectedDate = new Date(datetimeInput.value);
                const minAllowedDate = new Date();
                minAllowedDate.setDate(minAllowedDate.getDate() + 3);
                
                if (selectedDate < minAllowedDate) {
                    e.preventDefault();
                    alert('Event date must be at least 3 days from today.');
                }
            });
        });
    </script>
</body>
</html>