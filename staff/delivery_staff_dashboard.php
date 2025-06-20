<?php
include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
if (!isset($_SESSION['staff_id'])) {
    header("Location: delivery_staff_login.php");
    exit;
}

$staff_id = $_SESSION['staff_id'];
$staff_name = $_SESSION['staff_name'];

// Filtering (status tabs)
$allowed_statuses = ['pending', 'out_for_delivery', 'delivered'];
$status_filter = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses) ? $_GET['status'] : 'all';

// Filtering (search)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] >= 1 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where = "s.staff_id = ?";
$params = [$staff_id];
$types = "i";
if ($status_filter !== 'all') {
    $where .= " AND s.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}
if ($search !== "") {
    $where .= " AND (s.service_type LIKE ? OR s.notes LIKE ? OR cu.username LIKE ? OR c.car_brand LIKE ? OR c.car_model LIKE ? OR b.booking_id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, array_fill(0, 6, $search_param));
    $types .= str_repeat("s", 6);
}

// Count total
$count_sql = "SELECT COUNT(*) as total
        FROM service s
        JOIN booking b ON s.booking_id = b.booking_id
        JOIN car c ON b.car_id = c.car_id
        JOIN customer cu ON b.cust_id = cu.cust_id
        WHERE $where";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total = ($count_result && $count_result->num_rows) ? $count_result->fetch_assoc()['total'] : 0;
$count_stmt->close();
$total_pages = max(1, ceil($total / $per_page));

// Fetch assigned services for this staff, with filter and pagination
$sql = "SELECT s.*, b.cust_id, c.car_brand, c.car_model, cu.username AS customer_name,
               b.pickup_datetime, b.return_datetime
        FROM service s
        JOIN booking b ON s.booking_id = b.booking_id
        JOIN car c ON b.car_id = c.car_id
        JOIN customer cu ON b.cust_id = cu.cust_id
        WHERE $where
        ORDER BY s.service_id DESC
        LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$services = [];
while ($row = $result->fetch_assoc()) {
    $services[] = $row;
}
$stmt->close();

// --- ALERT LOGIC ---
$now = new DateTime('now');
$alerts = [];
foreach ($services as $row) {
    // Alert for delivery 1 day before pickup
    if (
        in_array($row['service_type'], ['delivery', 'pickup_and_return']) &&
        !empty($row['pickup_datetime'])
    ) {
        $pickup = new DateTime($row['pickup_datetime']);
        $interval = $now->diff($pickup);
        if ($interval->days === 1 && $pickup > $now && $interval->invert === 0) {
            $alerts[] = "Reminder: Deliver car to customer for Booking ID <b>{$row['booking_id']}</b> (" .
                htmlspecialchars($row['car_brand'].' '.$row['car_model']) .
                ") at " . $pickup->format('d/m/Y H:i') . ".";
        }
    }
    // Alert for pickup 1 day before return (only for pickup_and_return)
    if (
        $row['service_type'] === 'pickup_and_return' &&
        !empty($row['return_datetime'])
    ) {
        $return = new DateTime($row['return_datetime']);
        $interval = $now->diff($return);
        if ($interval->days === 1 && $return > $now && $interval->invert === 0) {
            $alerts[] = "Reminder: Pickup car from customer for Booking ID <b>{$row['booking_id']}</b> (" .
                htmlspecialchars($row['car_brand'].' '.$row['car_model']) .
                ") at " . $return->format('d/m/Y H:i') . ".";
        }
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'], $_POST['service_id'])) {
    $service_id = intval($_POST['service_id']);
    $new_status = $_POST['status'];
    if (in_array($new_status, $allowed_statuses)) {
        $stmt = $conn->prepare("UPDATE service SET status = ? WHERE service_id = ? AND staff_id = ?");
        $stmt->bind_param("sii", $new_status, $service_id, $staff_id);
        $stmt->execute();
        $stmt->close();
        // Redirect to the same page to avoid resubmission and stay on the current filter/page/search
        $q = http_build_query([
            'status' => $status_filter,
            'page' => $page,
            'search' => $search
        ]);
        header("Location: delivery_staff_dashboard.php?$q");
        exit;
    } else {
        $error_msg = "Invalid status.";
    }
}
include 'staff_header.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Staff Dashboard</title>
    <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: #f7f9fa; margin:0; }
        .dashboard-container { max-width: 1100px; margin: 48px auto 0 auto; }
        .dashboard-header {
            background: #2b5cbc;
            color: #fff;
            border-radius: 12px 12px 0 0;
            padding: 28px 38px 23px 38px;
            box-shadow: 0 2px 16px #b5baf644;
        }
        .dashboard-header h2 { margin: 0; font-size: 2.3em; letter-spacing: 1px; }
        .dashboard-header .staff-name {font-weight: 700;}
        .tab-bar {
            margin: 32px 0 16px 0;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .tab-bar a {
            padding: 10px 22px;
            background: #f7fafd;
            color: #2b5cbc;
            border-radius: 8px 8px 0 0;
            font-weight: 700;
            font-size: 1.07em;
            text-decoration: none;
            border: 1.5px solid #e4e8f3;
            border-bottom: none;
            transition: background 0.12s, color 0.12s;
        }
        .tab-bar a.active, .tab-bar a:hover {
            background: #2b5cbc;
            color: #fff;
            border-color: #2b5cbc;
        }
        .services-search-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 14px;
            align-items: center;
            flex-wrap: wrap;
        }
        .services-search-bar input[type=text] {
            padding: 9px 14px;
            border-radius: 7px;
            border: 1.5px solid #b5bee5;
            font-size: 1.05em;
            background: #f7fafd;
            width: 200px;
            max-width: 50vw;
        }
        .services-search-bar button {
            padding: 9px 19px;
            background: #2b5cbc;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-weight: 600;
            font-size: 1.03em;
            cursor: pointer;
            transition: background 0.14s;
        }
        .services-search-bar button:hover {
            background: #243570;
        }
        .assigned-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 12px #e0e7ef55;
            margin-top: 0;
            overflow: hidden;
        }
        .assigned-table th, .assigned-table td {
            padding: 13px 10px;
            border-bottom: 1.2px solid #eef2fa;
            text-align: left;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .assigned-table th {
            background: #f8fafd;
            font-weight: 700;
            color: #2b5cbc;
            letter-spacing: 0.5px;
        }
        .assigned-table tr:last-child td {
            border-bottom: none;
        }
        .welcome-msg {
            margin: 30px 0 16px 0;
            font-size: 1.25em;
            color: #234c96;
        }
        .msg-success { color: #219150; margin-bottom: 16px;}
        .msg-error { color: #d42d2d; margin-bottom: 16px;}
        .status-form select, .status-form button {
            padding: 7px 10px;
            border-radius: 7px;
            border: 1.5px solid #b5bee5;
            font-size: 1em;
            background: #f7fafd;
            margin-right: 5px;
        }
        .status-form button {
            background: #2b5cbc;
            color: #fff;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.14s;
        }
        .status-form button:hover {
            background: #243570;
        }
        .alert-box {
            background: #fff7e3;
            border: 1.5px solid #f6c967;
            color: #c28d1d;
            padding: 18px 24px;
            border-radius: 10px;
            margin: 26px 0 20px 0;
            font-size: 1.08em;
            box-shadow: 0 2px 12px #ffe7b355;
            font-weight: bold;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin: 22px 0 0 0;
        }
        .pagination a, .pagination span {
            padding: 7px 15px;
            border-radius: 6px;
            background: #f5f5fd;
            color: #2b5cbc;
            text-decoration: none;
            font-weight: 600;
            border: 1.5px solid #e4e8f3;
            min-width: 31px;
            text-align: center;
            transition: background 0.12s, color 0.12s;
        }
        .pagination a:hover {
            background: #2b5cbc;
            color: #fff;
        }
        .pagination .current {
            background: #2b5cbc;
            color: #fff;
            border-color: #2b5cbc;
            pointer-events: none;
        }
        @media (max-width: 700px) {
            .dashboard-container { padding: 0 2vw; }
            .dashboard-header { padding: 14px 8px; }
            .dashboard-header h2 { font-size: 1.5em;}
            .alert-box { font-size: 0.97em; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h2>Welcome, <span class="staff-name"><?= htmlspecialchars($staff_name) ?></span></h2>
            <div style="margin-top:7px;">This is your delivery staff dashboard.</div>
        </div>

        <!-- Tabs -->
        <div class="tab-bar">
            <a href="delivery_staff_dashboard.php?status=all&search=<?= urlencode($search) ?>" class="<?= $status_filter == 'all' ? 'active' : '' ?>">All</a>
            <a href="delivery_staff_dashboard.php?status=pending&search=<?= urlencode($search) ?>" class="<?= $status_filter == 'pending' ? 'active' : '' ?>">Pending</a>
            <a href="delivery_staff_dashboard.php?status=out_for_delivery&search=<?= urlencode($search) ?>" class="<?= $status_filter == 'out_for_delivery' ? 'active' : '' ?>">Out for Delivery</a>
            <a href="delivery_staff_dashboard.php?status=delivered&search=<?= urlencode($search) ?>" class="<?= $status_filter == 'delivered' ? 'active' : '' ?>">Delivered</a>
        </div>

        <!-- Search Bar -->
        <form class="services-search-bar" method="get" action="delivery_staff_dashboard.php" autocomplete="off">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
            <input type="text" name="search" placeholder="Search service, customer, car..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Search</button>
            <?php if ($search): ?>
                <a href="delivery_staff_dashboard.php?status=<?= urlencode($status_filter) ?>" style="margin-left:15px;color:#888;font-size:0.98em;">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($alerts)): ?>
            <?php foreach ($alerts as $alert): ?>
                <div class="alert-box"><?= $alert ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="welcome-msg">
            Here are your assigned services:
        </div>
        <?php if (isset($success_msg)): ?>
            <div class="msg-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if (isset($error_msg)): ?>
            <div class="msg-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>
        <div style="overflow-x:auto;">
        <table class="assigned-table">
            <thead>
                <tr>
                    <th style="width:45px;">#</th>
                    <th style="width:110px;">Service Type</th>
                    <th style="width:110px;">Booking ID</th>
                    <th style="width:120px;">Customer</th>
                    <th style="width:130px;">Car</th>
                    <th style="width:90px;">Fee (RM)</th>
                    <th style="width:130px;">Pickup DateTime</th>
                    <th style="width:130px;">Return DateTime</th>
                    <th style="width:180px;">Location</th>
                    <th style="width:150px;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($services)): ?>
                <tr><td colspan="10" style="text-align:center;color:#888;">No assigned services found.</td></tr>
            <?php else: ?>
                <?php foreach ($services as $i => $row): ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><?= htmlspecialchars($row['service_type']) ?></td>
                        <td><?= htmlspecialchars($row['booking_id']) ?></td>
                        <td><?= htmlspecialchars($row['customer_name']) ?></td>
                        <td><?= htmlspecialchars($row['car_brand'].' '.$row['car_model']) ?></td>
                        <td><?= number_format($row['fee'], 2) ?></td>
                        <td>
                            <?= $row['pickup_datetime'] ? date('d/m/Y H:i', strtotime($row['pickup_datetime'])) : '-' ?>
                        </td>
                        <td>
                            <?= $row['return_datetime'] ? date('d/m/Y H:i', strtotime($row['return_datetime'])) : '-' ?>
                        </td>
                        <td><?= htmlspecialchars($row['notes']) ?></td>
                        <td>
                            <form method="post" class="status-form" style="display:inline;">
                                <input type="hidden" name="service_id" value="<?= $row['service_id'] ?>">
                                <select name="status">
                                    <option value="pending" <?= $row['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="out_for_delivery" <?= $row['status'] == 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                                    <option value="delivered" <?= $row['status'] == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                </select>
                                <button type="submit" name="update_status">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&page=1">&laquo; First</a>
                <a href="?status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&page=<?= $page-1 ?>">&lt; Prev</a>
            <?php endif; ?>
            <?php
            $range = 2;
            for ($p = max(1, $page - $range); $p <= min($total_pages, $page + $range); $p++): ?>
                <?php if ($p == $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="?status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&page=<?= $page+1 ?>">Next &gt;</a>
                <a href="?status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>&page=<?= $total_pages ?>">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>