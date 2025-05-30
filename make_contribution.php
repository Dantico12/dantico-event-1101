<?php
session_start();
require_once 'db.php';

// Function to generate base URL with event context
function getEventContextURL() {
    $base_url = '';
    if (isset($_SESSION['current_event_id']) && isset($_SESSION['current_event_code'])) {
        $base_url = '?event_id=' . urlencode($_SESSION['current_event_id']) .
        '&event_code=' . urlencode($_SESSION['current_event_code']);
    }
    return $base_url;
}

$base_url = getEventContextURL();
$current_event_id = $_SESSION['current_event_id'] ?? null;

// Function to fetch user roles
function getUserRoles($conn, $user_id, $event_id) {
    $sql = "SELECT em.role, em.committee_role, e.* 
            FROM event_members em
            JOIN events e ON em.event_id = e.id 
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

// Initialize user roles
$user_role = '';
$committee_role = '';
if (isset($_SESSION['user_id']) && isset($_SESSION['current_event_id'])) {
    $user_roles = getUserRoles($conn, $_SESSION['user_id'], $_SESSION['current_event_id']);
    $user_role = $user_roles['role'] ?? '';
    $committee_role = $user_roles['committee_role'] ?? '';
}

function getCurrentMeetingInfo($conn, $current_event_id) {
    if (!$current_event_id) {
        return [
            'has_meeting' => false,
            'message' => 'No event selected'
        ];
    }
    date_default_timezone_set('Africa/Nairobi');
    $current_time = date('Y-m-d H:i:s');
    // Get all upcoming meetings for this event
    $query = "SELECT
        meeting_id,
        meeting_type,
        meeting_date,
        meeting_time,
        end_time,
        CONCAT(meeting_date, ' ', meeting_time) as start_datetime,
        CONCAT(meeting_date, ' ', end_time) as end_datetime
        FROM meetings
        WHERE event_id = ?
        AND CONCAT(meeting_date, ' ', end_time) >= ?
        ORDER BY CONCAT(meeting_date, ' ', meeting_time) ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $current_event_id, $current_time);
    $stmt->execute();
    $result = $stmt->get_result();
    $meetings = [];
    while ($row = $result->fetch_assoc()) {
        $meetings[] = $row;
    }
    if (empty($meetings)) {
        return [
            'has_meeting' => false,
            'message' => 'No upcoming meetings scheduled',
            'meetings' => []
        ];
    }
    return [
        'has_meeting' => true,
        'meetings' => $meetings,
        'current_time' => $current_time
    ];
}

// Handle AJAX request
if(isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    echo json_encode(getCurrentMeetingInfo($conn, $current_event_id));
    exit;
}

// Initial meeting info for page load
$initial_meeting_info = getCurrentMeetingInfo($conn, $current_event_id);

class MpesaGateway {
    private $consumer_key;
    private $consumer_secret;
    private $initiator_name;
    private $initiator_password;
    private $passkey;
    private $environment;
    private $conn;

    public function __construct($db_connection) {
        $this->consumer_key = '4CXELNo5HnT5uW2rNR7Rls6JUQX6DscFYIrsunDpAQIgi99p';
        $this->consumer_secret = '5mLUeJ480thfZGJ6fkKENY8jtMXvdulXvzYYObYUtrrPsoEanGEZ3zJTbZqT8RIe';
        $this->initiator_name = "testapi";
        $this->initiator_password = "Safaricom999!*!";
        $this->passkey = 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919';
        $this->environment = 'sandbox';
        $this->conn = $db_connection;
    }

    private function generateAccessToken() {
        $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($this->consumer_key . ':' . $this->consumer_secret)],
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        $result = json_decode($response, true);
        return $result['access_token'] ?? null;
    }

    public function initiateSTKPush($phone_number, $amount, $event_id, $user_id) {
        // Sanitize phone number
        $phone_number = $this->sanitizePhoneNumber($phone_number);

        $access_token = $this->generateAccessToken();
        if (!$access_token) {
            return ['error' => 'Failed to generate access token'];
        }

        $timestamp = date('YmdHis');
        $business_short_code = '174379';
        $password = base64_encode($business_short_code . $this->passkey . $timestamp);
        $order_ref = 'ORDER' . $timestamp . rand(1000, 9999);

        $curl_post_data = [
            'BusinessShortCode' => $business_short_code,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => ceil($amount),
            'PartyA' => $phone_number,
            'PartyB' => $business_short_code,
            'PhoneNumber' => $phone_number,
            'CallBackURL' => 'https://yourwebsite.com/mpesa_callback.php',
            'AccountReference' => $order_ref,
            'TransactionDesc' => "Contribution for Event ID $event_id"
        ];

        $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $access_token
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($curl_post_data)
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        $result = json_decode($response, true);
        
        // Log the payment request and insert transaction with user_id
        $this->logPaymentRequest($order_ref, $phone_number, $amount, $event_id, $user_id, $result);
        $this->insertTransaction($order_ref, $phone_number, $amount, $event_id, $user_id, json_encode($result));

        return $result;
    }

    private function sanitizePhoneNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 9) {
            return '254' . $phone;
        } elseif (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
            return '254' . substr($phone, 1);
        } elseif (strlen($phone) === 12 && substr($phone, 0, 3) === '254') {
            return $phone;
        }
        throw new Exception('Invalid phone number format');
    }

    private function logPaymentRequest($orderRef, $phone, $amount, $event_id, $user_id, $response) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'order_ref' => $orderRef,
            'phone' => $phone,
            'amount' => $amount,
            'event_id' => $event_id,
            'user_id' => $user_id,
            'response' => $response
        ];
        error_log("M-Pesa Payment Request: " . json_encode($logData));
    }
    
    private function insertTransaction($orderRef, $phone, $amount, $event_id, $user_id, $mpesaResponse) {
        $status = 'pending';
        $stmt = $this->conn->prepare("INSERT INTO contributions (
            sender_phone, 
            amount,
            event_id,
            user_id,
            checkout_request_id,
            transaction_status,
            mpesa_response
        ) VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $checkoutRequestId = json_decode($mpesaResponse, true)['CheckoutRequestID'] ?? null;
            $stmt->bind_param("sdiiiss", 
                $phone, 
                $amount, 
                $event_id, 
                $user_id,
                $checkoutRequestId,
                $status,
                $mpesaResponse
            );
            $stmt->execute();
            $stmt->close();
        } else {
            throw new Exception('Database insert failed: ' . $this->conn->error);
        }
    }

    public function updateTransactionStatus($checkoutRequestId, $transactionStatus, $mpesaReceiptNumber = null) {
        $stmt = $this->conn->prepare("UPDATE contributions SET 
            transaction_status = ?,
            mpesa_receipt_number = ?
            WHERE checkout_request_id = ?");
            
        if ($stmt) {
            $stmt->bind_param("sss", $transactionStatus, $mpesaReceiptNumber, $checkoutRequestId);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        } else {
            throw new Exception('Database update failed: ' . $this->conn->error);
        }
    }

    public function recordContribution($event_id, $phone_number, $amount, $transaction_status, $user_id) {
        $stmt = $this->conn->prepare("INSERT INTO contributions (event_id, phone_number, amount, transaction_status, contribution_date, user_id) VALUES (?, ?, ?, ?, NOW(), ?)");
        $stmt->bind_param("isdsi", $event_id, $phone_number, $amount, $transaction_status, $user_id);
        return $stmt->execute();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Make Contribution - Event Management Dashboard</title>
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

        /* Sidebar Styles */
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
            overflow-y: auto;
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

        .menu-item a {
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .sidebar.collapse .menu-item span {
            opacity: 0;
        }

        /* Main Content Styles */
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

        /* Enhanced Form Styles */
        .form-container {
            background-color: #081b29;
            padding: 30px;
            border-radius: 10px;
            border: 2px solid #0ef;
            max-width: 800px;
            margin: 0 auto;
        }

      
        
        .form-title {
            color: #fff;
            margin-bottom: 25px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title i {
            color: #0ef;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group label i {
            color: #0ef;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #0ef;
            background-color: rgba(0, 238, 255, 0.05);
            color: #fff;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            box-shadow: 0 0 10px rgba(0, 238, 255, 0.3);
        }

        .form-group select option {
            background-color: #081b29;
            color: #fff;
        }

        .button-group {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }

        .submit-btn,
        .reset-btn {
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .submit-btn {
            background: #0ef;
            color: #081b29;
            border: none;
        }

        .reset-btn {
            background: transparent;
            color: #fff;
            border: 2px solid #0ef;
        }

        .submit-btn:hover {
            background: #fff;
        }

        .reset-btn:hover {
            background: rgba(0, 238, 255, 0.1);
        }
        .success-message {
    background: rgba(0, 238, 255, 0.1);
    border: 1px solid #0ef;
    color: #fff;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
    display: none;
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 1000;
    animation: slideIn 0.3s ease-out;
}

.success-message.show {
    display: flex;
    align-items: center;
    gap: 10px;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

        /* Responsive Styles */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .submit-btn,
            .reset-btn {
                width: 100%;
                justify-content: center;
            }

            .main-content {
                left: var(--collapsed-width);
                width: calc(100% - var(--collapsed-width));
            }

            .sidebar {
                width: var(--collapsed-width);
            }

            .sidebar-header h2,
            .category-title,
            .menu-item span {
                opacity: 0;
            }
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

      <!-- Main Content -->
      <div class="main-content">
        <div class="header">
            <button class="toggle-btn">
                <i class='bx bx-menu'></i>
            </button>
            <h2 class="header-title">M-Pesa Contribution</h2>
            <div class="header-actions">
                <i class='bx bx-search'></i>
                <i class='bx bx-bell'></i>
                <i class='bx bx-user-circle'></i>
            </div>
        </div>

        <div class="form-container">
            <div class="success-message">
                <i class='bx bx-check-circle' style="color: #0ef; font-size: 20px;"></i>
                <span>STK push sent successfully! Check your phone to complete the payment.</span>
            </div>
            
            <h3 class="form-title">
                <i class='bx bx-money'></i>
                M-Pesa Contribution
            </h3>
            
            <form id="contributionForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>
                            <i class='bx bx-phone'></i>
                            Phone Number
                        </label>
                        <input 
                            type="tel" 
                            id="phoneNumber" 
                            placeholder="Enter M-Pesa registered phone number" 
                            required 
                            pattern="(07|01)[0-9]{8}" 
                            title="Please enter a valid Kenyan phone number (07/01)"
                        >
                    </div>

                    <div class="form-group">
                        <label>
                            <i class='bx bx-dollar'></i>
                            Contribution Amount
                        </label>
                        <input 
                            type="number" 
                            id="amount" 
                            placeholder="Enter amount in KES" 
                            required 
                            min="1"
                        >
                    </div>
                </div>

                <div class="button-group">
                    <button type="reset" class="reset-btn">
                        <i class='bx bx-reset'></i>
                        Reset
                    </button>
                    <button type="submit" class="submit-btn">
                        <i class='bx bx-check'></i>
                        Send M-Pesa Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('contributionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = this;
            const phoneNumber = document.getElementById('phoneNumber').value;
            const amount = document.getElementById('amount').value;
            const successMessage = document.querySelector('.success-message');
            
            // Disable form submission while processing
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Processing...';
            
            const formData = new FormData();
            formData.append('phone_number', phoneNumber);
            formData.append('amount', amount);

            fetch('process_payment.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // Show success message
                    successMessage.classList.add('show');
                    
                    // Clear form fields
                    form.reset();
                    
                    // Hide success message after 3 seconds
                    setTimeout(() => {
                        successMessage.classList.remove('show');
                    }, 3000);
                } else {
                    // Handle error response
                    throw new Error(data.message || 'Payment initiation failed');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert(error.message || 'An error occurred during payment processing');
            })
            .finally(() => {
                // Reset submit button
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="bx bx-check"></i> Send M-Pesa Request';
            });
        });

        // Add reset confirmation
        document.querySelector('.reset-btn').addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to clear the form?')) {
                e.preventDefault();
            }
        });

        // Toggle sidebar functionality
        document.querySelector('.toggle-btn').addEventListener('click', function() {
            document.querySelector('.sidebar').classList.toggle('collapse');
            document.querySelector('.main-content').classList.toggle('expand');
        });
    </script>
</body>
</html>