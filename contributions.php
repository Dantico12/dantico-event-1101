<?php
// Include the database connection
include 'db.php';

// Get event ID from URL parameter
$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;

if ($event_id === 0) {
    echo "<div class='error-message'>No event selected. Please select an event.</div>";
    exit;
}

// Fetch event details
$event_sql = "SELECT event_name FROM events WHERE id = ?";
$stmt = $conn->prepare($event_sql);
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event_result = $stmt->get_result();
$event_name = "";
if ($event_result->num_rows > 0) {
    $event_row = $event_result->fetch_assoc();
    $event_name = $event_row['event_name'];
}

// Fetch contributions for the specific event
$sql = "SELECT c.sender_phone, c.amount, c.created_at, u.username 
        FROM contributions c
        LEFT JOIN users u ON c.user_id = u.id
        WHERE c.event_id = ? 
        ORDER BY c.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $event_id);
$stmt->execute();
$result = $stmt->get_result();

// Fetch summary for the specific event
$total_sql = "SELECT 
    COUNT(*) as total_contributions,
    SUM(amount) as total_amount,
    COUNT(DISTINCT sender_phone) as unique_contributors
    FROM contributions 
    WHERE event_id = ?";
$stmt = $conn->prepare($total_sql);
$stmt->bind_param("i", $event_id);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management Dashboard</title>
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

        .sidebar.collapse .menu-item span {
            opacity: 0;
        }

        .menu-item::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 2px;
            height: 100%;
            background: #0ef;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .menu-item:hover::after,
        .menu-item.active::after {
            opacity: 1;
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

        .notification-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #0ef;
            color: #081b29;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Scrollbar Styling */
        .sidebar-menu::-webkit-scrollbar {
            width: 5px;
        }

        .sidebar-menu::-webkit-scrollbar-track {
            background: rgba(8, 27, 41, 0.9);
        }

        .sidebar-menu::-webkit-scrollbar-thumb {
            background: #0ef;
            border-radius: 5px;
        }

        /* Hover effects for interactive elements */
        .header-actions i:hover,
        .toggle-btn:hover {
            color: #fff;
        }
        .contribution-table {
            width: 100%;
            border-collapse: collapse;
            color: #fff;
            margin-top: 20px;
        }

        .contribution-table th,
        .contribution-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 238, 255, 0.2);
        }

        .contribution-table th {
            background-color: rgba(0, 238, 255, 0.1);
            color: #0ef;
            font-weight: 600;
        }

        .contribution-table tbody tr:hover {
            background-color: rgba(0, 238, 255, 0.05);
        }

        .table-container {
            overflow-x: auto;
            background: rgba(8, 27, 41, 0.9);
            border-radius: 8px;
            padding: 20px;
        }

        .section-title {
            color: #0ef;
            margin-bottom: 20px;
            font-size: 1.2rem;
        }

        .no-data {
            color: #fff;
            text-align: center;
            padding: 20px;
        }

        .event-selector {
            margin-bottom: 20px;
        }

        .event-selector select {
            padding: 8px;
            background: rgba(0, 238, 255, 0.1);
            border: 1px solid #0ef;
            color: #fff;
            border-radius: 4px;
        }

        .error-message {
            color: #ff4444;
            padding: 15px;
            background: rgba(255, 68, 68, 0.1);
            border: 1px solid #ff4444;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .summary-box {
            background: rgba(0, 238, 255, 0.1);
            border: 1px solid #0ef;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            color: #fff;
        }

        .summary-box h4 {
            color: #0ef;
            margin-bottom: 10px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar">
        <div class="sidebar-header">
            <i class='bx bx-calendar-event' style="color: #0ef; font-size: 24px;"></i>
            <h2>Dantico Events</h2>
        </div>
        <div class="sidebar-menu">
            <!-- Dashboard -->
            <div class="menu-category">
                <div class="menu-item active">
                    <a href="./dashboard.php">
                        <i class='bx bx-home-alt'></i>
                        <span>Dashboard</span>
                    </a>
                </div>
            </div>

            <!-- Committees Section -->
            <div class="menu-category">
                <div class="category-title">Committees</div>
                <div class="menu-item">
                    <a href="./add-committee.php">
                        <i class='bx bx-plus-circle'></i>
                        <span>Add Committee</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="./committee-list.php">
                        <i class='bx bx-group'></i>
                        <span>Committee List</span>
                    </a>
                </div>
            </div>

            <!-- Communication Section -->
            <div class="menu-category">
                <div class="category-title">Communication</div>
                <div class="menu-item">
                    <a href="./chat.php">
                        <i class='bx bx-message-rounded-dots'></i>
                        <span>Chat System</span>
                        <div class="notification-badge">3</div>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="./video-conference.php">
                        <i class='bx bx-video'></i>
                        <span>Video Conference</span>
                    </a>
                </div>
            </div>
            <!--Contributions--> <i class='bx bx-video'></i>
            <div class="menu-category">
                <div class="category-title">Contributions</div>
                <div class="menu-item">
                    <a href="./make_contribution.php">
                        <i class='bx bx-plus-circle'></i>
                        <span>make contributions</span>
                       
                    </a>
                </div>
                <div class="menu-item">
                    <a href="./contributions.php">
                        <i class='bx bx-money'></i>
                        <span>contribiutions</span>
                    </a>
                </div>
            </div>

            <!-- Reviews Section -->
            <div class="menu-category">
                <div class="category-title">Reviews</div>
                <div class="menu-item">
                    <a href="./minutes.php">
                        <i class='bx bxs-timer'></i>
                        <span>Minutes</span>
                    </a>
                </div>
                <div class="menu-item">
                    <a href="./tasks.php">
                        <i class='bx bx-task' ></i>
                        <span>Tasks</span>
                    </a>
                </div>
            </div>

            </div>


            <!-- Other Tools -->
            <div class="menu-category">
                <div class="category-title">Tools</div>
                <div class="menu-item">
                    <a href="./schedule.php">
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
            <h2 class="header-title">Contrubutions</h2>
            <div class="header-actions">
                <i class='bx bx-search'></i>
                <i class='bx bx-bell'></i>
                <i class='bx bx-user-circle'></i>
            </div>
        </div>

        <div class="content-section">
    <!-- Summary Box -->
    <?php if ($event_name): ?>
    <div class="summary-box">
        <h4>Contribution Summary for <?php echo htmlspecialchars($event_name); ?></h4>
        <div class="summary-item">
    <span>Total Contributions:</span>
    <span><?php echo $summary['total_contributions'] ?? 0; ?></span>
</div>
        <div class="summary-item">
    <span>Total Amount:</span>
    <span>KES <?php echo number_format($summary['total_amount'] ?? 0, 2); ?></span>
       </div>

       <div class="summary-item">
    <span>Unique Contributors:</span>
    <span><?php echo $summary['unique_contributors'] ?? 0; ?></span>
     </div>
    </div>
    <?php endif; ?>

    <!-- Contributions Table -->
    <h3 class="section-title">Contributions List</h3>
    <div class="table-container">
        <table class="contribution-table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>Phone Number</th>
                    <th>Amount (KES)</th>
                    <th>Contribution Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result && $result->num_rows > 0) {
                    while($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($row["username"] ?? 'Unknown User') . "</td>";
                        echo "<td>" . htmlspecialchars($row["sender_phone"]) . "</td>";
                        echo "<td>" . number_format($row["amount"], 2) . "</td>";
                        echo "<td>" . htmlspecialchars($row["created_at"]) . "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='4' class='no-data'>No contributions found for this event</td></tr>";
                }
                ?>
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

        // Handle menu item clicks
        const menuItems = document.querySelectorAll('.menu-item');
        menuItems.forEach(item => {
            item.addEventListener('click', () => {
                // Remove active class from all items
                menuItems.forEach(i => i.classList.remove('active'));
                // Add active class to clicked item
                item.classList.add('active');
                // Update header title based on selected menu
                const spanText = item.querySelector('span').textContent;
                document.querySelector('.header-title').textContent = spanText;
            });
        });
    </script>
      <?php
    // Close the database connection
    $stmt->close();
    $conn->close();
    ?>
</body>
</html>