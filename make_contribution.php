<?php

session_start();

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
class MpesaGateway {
    private $consumer_key;
    private $consumer_secret;
    private $initiator_name;
    private $initiator_password;
    private $passkey;
    private $environment;
    private $conn;

    

    public function __construct($db_connection) {
        // Use credentials from the second implementation
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
    public function initiateSTKPush($phone_number, $amount, $event_id) {
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
        
        // Only log the request for now - don't insert into database yet
        $this->logPaymentRequest($order_ref, $phone_number, $amount, $event_id, $result);
        
        // Add the order_ref to the result so we can use it later
        $result['order_ref'] = $order_ref;
        $result['phone_number'] = $phone_number;
        $result['amount'] = $amount;
        $result['event_id'] = $event_id;
        
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

    private function logPaymentRequest($orderRef, $phone, $amount, $event_id, $response) {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'order_ref' => $orderRef,
            'phone' => $phone,
            'amount' => $amount,
            'event_id' => $event_id,
            'response' => $response
        ];
        error_log("M-Pesa Payment Request: " . json_encode($logData));
    }

    private function insertTransaction($orderRef, $phone, $amount, $event_id, $mpesaResponse) {
        $status = 'pending';
        $stmt = $this->conn->prepare("INSERT INTO contributions (order_ref, event_id, phone_number, amount, mpesa_response, transaction_status, contribution_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        
        if ($stmt) {
            $stmt->bind_param("sisdsb", $orderRef, $event_id, $phone, $amount, $mpesaResponse, $status);
            $stmt->execute();
            $stmt->close();
        } else {
            throw new Exception('Database insert failed: ' . $this->conn->error);
        }
    }

    public function recordContribution($event_id, $phone_number, $amount, $transaction_status) {
        $stmt = $this->conn->prepare("INSERT INTO contributions (event_id, phone_number, amount, transaction_status, contribution_date) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param("isds", $event_id, $phone_number, $amount, $transaction_status);
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

        .success-message {
            background: rgba(0, 238, 255, 0.1);
            border: 1px solid #0ef;
            color: #fff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            display: none;
        }

        .success-message.show {
            display: flex;
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
            Payment request sent successfully!
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
    document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('contributionForm');
    const successMessage = document.querySelector('.success-message');

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const phoneNumber = document.getElementById('phoneNumber').value;
        const amount = document.getElementById('amount').value;
        const submitButton = this.querySelector('button[type="submit"]');
        
        // Disable form while processing
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Processing...';

        try {
            const formData = new FormData();
            formData.append('phone_number', phoneNumber);
            formData.append('amount', amount);

            const response = await fetch('process_payment.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const text = await response.text();
            console.log('Raw response:', text);
            
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('JSON Parse Error:', e);
                throw new Error('Server returned invalid JSON');
            }

            console.log('Parsed response:', data);
            
            if (data.status === 'success' && data.data?.CheckoutRequestID) {
                successMessage.innerHTML = `
                    <i class='bx bx-check-circle' style="color: #0ef; font-size: 20px;"></i>
                    Please check your phone for the M-Pesa prompt
                `;
                successMessage.classList.add('show');
                form.reset();
                
                // Hide success message after 10 seconds
                setTimeout(() => {
                    successMessage.classList.remove('show');
                }, 10000);
            } else {
                throw new Error(data.message || 'Payment processing failed');
            }
        } catch (error) {
            console.error('Error details:', error);
            alert('Payment processing failed: ' + error.message);
        } finally {
            submitButton.disabled = false;
            submitButton.innerHTML = '<i class="bx bx-check"></i> Send M-Pesa Request';
        }
    });
});
</script>
</body>
</html>