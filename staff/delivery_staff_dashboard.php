<?php
include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['staff_id'])) {
    header("Location: delivery_staff_login.php");
    exit;
}

$staff_id = (int)$_SESSION['staff_id'];
$staff_name = (string)($_SESSION['staff_name'] ?? '');

// Search filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Build WHERE clause (no status filtering)
$where = "s.staff_id = ?";
$params = [$staff_id];
$types = "i";

if ($search !== "") {
    $where .= " AND (s.service_type LIKE ? OR cu.full_name LIKE ? OR b.booking_id LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, array_fill(0, 3, $search_param));
    $types .= "sss";
}

// Count total
$count_sql = "SELECT COUNT(*) as total
        FROM service s
        JOIN booking b ON s.booking_id = b.booking_id
        JOIN customer cu ON b.cust_id = cu.cust_id
        WHERE $where";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total = ($count_result && $count_result->num_rows) ? (int)$count_result->fetch_assoc()['total'] : 0;
$count_stmt->close();
$total_pages = max(1, (int)ceil($total / $per_page));

// Fetch services
$sql = "SELECT s.*, b.cust_id, b.booking_id, cu.full_name AS customer_name,
               b.pickup_datetime, b.return_datetime
        FROM service s
        JOIN booking b ON s.booking_id = b.booking_id
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

// Alerts
$now = new DateTime('now');
$alerts = [];
foreach ($services as $row) {
    if (in_array($row['service_type'], ['delivery','pickup_and_return'], true) && !empty($row['pickup_datetime'])) {
        $pickup = new DateTime($row['pickup_datetime']);
        $interval = $now->diff($pickup);
        if ($interval->days === 1 && $pickup > $now && $interval->invert === 0) {
            $alerts[] = "Reminder: Deliver car for Booking <b>{$row['booking_id']}</b> at " . $pickup->format('d/m/Y H:i') . ".";
        }
    }
    if ($row['service_type'] === 'pickup_and_return' && !empty($row['return_datetime'])) {
        $return = new DateTime($row['return_datetime']);
        $interval = $now->diff($return);
        if ($interval->days === 1 && $return > $now && $interval->invert === 0) {
            $alerts[] = "Reminder: Pickup car back for Booking <b>{$row['booking_id']}</b> at " . $return->format('d/m/Y H:i') . ".";
        }
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
        body { background: #f6f7fb; margin:0; }
        .dashboard-layout { display: flex; min-height: 100vh; }
        .dashboard-sidebar { width: 220px; background: #243570; padding: 30px 0; display: flex; flex-direction: column; gap: 5px; }
        .dashboard-sidebar a { color: #fff; text-decoration: none; padding: 14px 32px; font-size: 1.07em; display: block; transition: background .18s; border-left: 4px solid transparent; }
        .dashboard-sidebar a:hover, .dashboard-sidebar a.active { background: #2b5cbc; border-left: 4px solid #ffd600; }
        .dashboard-main-content { flex: 1; padding: 40px; background: #f6f7fb; }
        .dashboard-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 30px; margin-top: 10px; }
        .welcome-staff { font-size: 2.1em; font-weight: 800; color: #2b5cbc; letter-spacing: 1.2px; background: linear-gradient(90deg,#f9fffa 85%,#ffe877 100%); padding: 20px 34px 16px 0; border-radius: 0 18px 18px 0; box-shadow: 0 2px 15px #e0e7ef66; display: inline-block; margin-left: -40px; }
        .services-search-bar { display: flex; gap: 8px; margin-bottom: 14px; align-items: center; flex-wrap: wrap; }
        .services-search-bar input[type=text] { padding: 9px 14px; border-radius: 7px; border: 1.5px solid #b5bee5; font-size: 1.05em; background: #f7fafd; width: 200px; max-width: 50vw; }
        .services-search-bar button { padding: 9px 19px; background: #2b5cbc; color: #fff; border: none; border-radius: 7px; font-weight: 600; font-size: 1.03em; cursor: pointer; transition: background 0.14s; }
        .services-search-bar button:hover { background: #243570; }
        .assigned-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 0 0 12px 12px; box-shadow: 0 2px 12px #e0e7ef55; margin-top: 0; overflow: hidden; }
        .assigned-table th, .assigned-table td { padding: 13px 10px; border-bottom: 1.2px solid #eef2fa; text-align: left; vertical-align: middle; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .assigned-table th { background: #f8fafd; font-weight: 700; color: #2b5cbc; letter-spacing: 0.5px; }
        .pagination { display:flex; justify-content:center; gap:8px; margin:22px 0 0 0; }
        .pagination a, .pagination span { padding: 7px 15px; border-radius: 6px; background: #f5f5fd; color: #2b5cbc; text-decoration: none; font-weight: 600; border: 1.5px solid #e4e8f3; min-width: 31px; text-align: center; transition: background 0.12s, color 0.12s; }
        .pagination a:hover { background: #2b5cbc; color: #fff; }
        .pagination .current { background: #2b5cbc; color: #fff; border-color: #2b5cbc; pointer-events: none; }
        @media (max-width: 900px){
            .dashboard-layout { flex-direction: column; }
            .dashboard-sidebar { flex-direction: row; width: 100%; min-height: unset; padding: 0; overflow-x: auto; }
            .dashboard-sidebar a { flex: 1; text-align: center; border-left: none; border-bottom: 4px solid transparent; white-space: nowrap; }
            .dashboard-sidebar a:hover, .dashboard-sidebar a.active { border-left:none; border-bottom:4px solid #ffd600; }
            .dashboard-main-content { padding: 18px; }
            .dashboard-header .welcome-staff { font-size: 1.3em; padding: 15px 10px 10px 0; margin-left: 0; }
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <nav class="dashboard-sidebar" aria-label="Staff navigation">
        <a href="delivery_staff_dashboard.php" class="active">Dashboard</a>
        <a href="delivery_staff_profile.php">My Profile</a>
        <a href="delivery_staff_logout.php">Logout</a>
    </nav>
    <main class="dashboard-main-content">
        <div class="dashboard-header">
            <span class="welcome-staff">👋 Welcome, <?= htmlspecialchars($staff_name) ?>!</span>
        </div>

        <form class="services-search-bar" method="get" action="delivery_staff_dashboard.php" autocomplete="off">
            <input type="text" name="search" placeholder="Search service, customer, booking..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">Search</button>
            <?php if ($search): ?>
                <a href="delivery_staff_dashboard.php" style="margin-left:15px;color:#888;font-size:0.98em;">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (!empty($alerts)): ?>
            <?php foreach ($alerts as $alert): ?>
                <div style="background:#fffbe7;color:#bfa800;border:1.5px solid #ffe877;border-radius:8px;margin-bottom:12px;padding:10px 14px;font-weight:700;"><?= $alert ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div style="overflow-x:auto;">
            <table class="assigned-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Service Type</th>
                        <th>Booking ID</th>
                        <th>Customer</th>
                        <th>Pickup</th>
                        <th>Return</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;">No assigned services found.</td></tr>
                <?php else: foreach ($services as $i => $row): ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><?= htmlspecialchars($row['service_type']) ?></td>
                        <td>
                            <a href="delivery_staff_booking_details.php?id=<?= (int)$row['booking_id'] ?>" style="color:#227be9;font-weight:600;text-decoration:underline;">
                                <?= (int)$row['booking_id'] ?>
                            </a>
                        </td>
                        <td><?= htmlspecialchars($row['customer_name']) ?></td>
                        <td><?= $row['pickup_datetime'] ? date('d/m/Y H:i', strtotime($row['pickup_datetime'])) : '-' ?></td>
                        <td><?= $row['return_datetime'] ? date('d/m/Y H:i', strtotime($row['return_datetime'])) : '-' ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?search=<?= urlencode($search) ?>&page=1">&laquo; First</a>
                <a href="?search=<?= urlencode($search) ?>&page=<?= $page-1 ?>">&lt; Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                <?php if ($p == $page): ?>
                    <span class="current"><?= $p ?></span>
                <?php else: ?>
                    <a href="?search=<?= urlencode($search) ?>&page=<?= $p ?>"><?= $p ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="?search=<?= urlencode($search) ?>&page=<?= $page+1 ?>">Next &gt;</a>
                <a href="?search=<?= urlencode($search) ?>&page=<?= $total_pages ?>">Last &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include '../includes/footer.php'; ?>
</body>
</html>