<?php
session_start();
include '../connect.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$admin_id = (int)$_SESSION['admin_id'];

/* --------------------------------------------------------------------------
   Helper Functions
-------------------------------------------------------------------------- */

function fetchScalar(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($val);
    $stmt->fetch();
    $stmt->close();
    return $val ?? 0;
}

function fetchRows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if ($types !== '' && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && $row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
    return $rows;
}

/* --------------------------------------------------------------------------
   Admin Name
-------------------------------------------------------------------------- */
$admin_name = '';
$stmt = $conn->prepare("SELECT COALESCE(NULLIF(full_name,''), username) AS display_name FROM admin WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($admin_name);
$stmt->fetch();
$stmt->close();

/* --------------------------------------------------------------------------
   Dates / Time Windows
-------------------------------------------------------------------------- */
$nowDT          = new DateTime('now');
$oneHourLaterDT = (clone $nowDT)->modify('+1 hour');
$todayDate      = $nowDT->format('Y-m-d');
$nowStr         = $nowDT->format('Y-m-d H:i:s');
$oneHourStr     = $oneHourLaterDT->format('Y-m-d H:i:s');

/* --------------------------------------------------------------------------
   Core Dashboard Counts
-------------------------------------------------------------------------- */
$total_customers = fetchScalar($conn, "SELECT COUNT(*) FROM customer");
$total_cars      = fetchScalar($conn, "SELECT COUNT(*) FROM car");
$total_bookings  = fetchScalar($conn, "SELECT COUNT(*) FROM booking");
$total_payments  = fetchScalar($conn, "SELECT COUNT(*) FROM payment WHERE payment_status = 'paid'");

$pickup_today = fetchScalar(
    $conn,
    "SELECT COUNT(*) FROM booking 
     WHERE DATE(pickup_datetime) = ? 
       AND status NOT IN ('cancelled','rejected')",
    's',
    [$todayDate]
);

$return_today = fetchScalar(
    $conn,
    "SELECT COUNT(*) FROM booking 
     WHERE DATE(return_datetime) = ? 
       AND status NOT IN ('cancelled','rejected')",
    's',
    [$todayDate]
);

/* Optional: current month revenue (uncomment if desired)
$currentMonthRevenue = fetchScalar(
    $conn,
    \"SELECT COALESCE(SUM(amount),0) FROM payment 
      WHERE payment_status='paid' 
        AND DATE_FORMAT(payment_date,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')\"
);
*/

/* --------------------------------------------------------------------------
   Alerts: Pickups (inspection), Returns (inspection), Approvals, Refunds
-------------------------------------------------------------------------- */

$pickup_alerts = fetchRows(
    $conn,
    "SELECT b.booking_id, b.pickup_datetime, c.car_brand, c.car_model, c.plate_no,
            cust.full_name, b.pickup_mileage, b.pickup_fuel_percent
       FROM booking b
       JOIN car c ON b.car_id = c.car_id
  LEFT JOIN customer cust ON b.cust_id = cust.cust_id
      WHERE b.pickup_datetime >= ? 
        AND b.pickup_datetime < ? 
        AND (b.pickup_mileage IS NULL OR b.pickup_fuel_percent IS NULL)
        AND b.status IN ('confirmed','upcoming')
   ORDER BY b.pickup_datetime ASC",
    'ss',
    [$nowStr, $oneHourStr]
);

$return_alerts = fetchRows(
    $conn,
    "SELECT b.booking_id, b.return_datetime, c.car_brand, c.car_model, c.plate_no,
            cust.full_name, b.return_mileage, b.return_fuel_percent
       FROM booking b
       JOIN car c ON b.car_id = c.car_id
  LEFT JOIN customer cust ON b.cust_id = cust.cust_id
      WHERE b.return_datetime >= ?
        AND b.return_datetime < ?
        AND (b.return_mileage IS NULL OR b.return_fuel_percent IS NULL)
        AND b.status IN ('confirmed','approved','upcoming','completed')
   ORDER BY b.return_datetime ASC",
    'ss',
    [$nowStr, $oneHourStr]
);

$approval_alerts = fetchRows(
    $conn,
    "SELECT b.booking_id, c.car_brand, c.car_model, c.plate_no, cust.full_name, b.created_at
       FROM booking b
       JOIN car c ON b.car_id = c.car_id
  LEFT JOIN customer cust ON b.cust_id = cust.cust_id
      WHERE b.status = 'waiting_verification'
   ORDER BY b.created_at ASC"
);

$refund_alerts = fetchRows(
    $conn,
    "SELECT r.refund_id, r.booking_id, r.amount, r.created_at, cust.full_name,
            car.car_brand, car.car_model, car.plate_no
       FROM refunds r
  LEFT JOIN booking b ON r.booking_id = b.booking_id
  LEFT JOIN customer cust ON b.cust_id = cust.cust_id
  LEFT JOIN car ON b.car_id = car.car_id
      WHERE r.refund_status = 'pending'
   ORDER BY r.created_at ASC"
);

include 'admin_header.php';
?>
<style>
.admin-layout { display:flex; min-height:100vh; }
.admin-sidebar {
  width:220px; background:#243570; padding:30px 0; display:flex; flex-direction:column; gap:5px;
}
.admin-sidebar a {
  color:#fff; text-decoration:none; padding:14px 32px; font-size:1.07em; display:block;
  transition:background .18s; border-left:4px solid transparent;
}
.admin-sidebar a:hover, .admin-sidebar a.active {
  background:#2b5cbc; border-left:4px solid #ffd600;
}
.admin-main-content { flex:1; padding:40px; background:#f6f7fb; }
.dashboard-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:30px; margin-top:10px; }
.welcome-admin {
  font-size:2.1em; font-weight:800; color:#2b5cbc; letter-spacing:1.2px;
  background:linear-gradient(90deg,#f9fffa 85%,#ffe877 100%);
  padding:20px 34px 16px 0; border-radius:0 18px 18px 0; box-shadow:0 2px 15px #e0e7ef66;
  display:inline-block; margin-left:-40px;
}
.dashboard-stats { display:flex; flex-wrap:wrap; gap:18px; margin-bottom:30px; }
.stat-card {
  flex:1 1 220px; background:#fff; padding:28px 18px; border-radius:10px; text-align:center;
  box-shadow:0 2px 7px #e0e7ef; min-width:150px; transition:transform .12s, box-shadow .12s;
}
.stat-card:hover { transform:translateY(-5px) scale(1.03); box-shadow:0 6px 22px #b5bee540; }
.stat-card h2 { margin:0; font-size:2.2rem; color:#2b5cbc; }
.stat-card p { margin:8px 0 0; color:#53709a; font-size:1.11em; letter-spacing:.5px; }
.stat-card[onclick] { cursor:pointer; border:2px solid #e4eaff; background:linear-gradient(120deg,#f8fafd 70%,#e5f3ff 100%); }

.alert-box {
  background:#fffbe7; color:#bfa800; border:1.5px solid #ffe877;
  border-radius:8px; margin-bottom:28px; padding:20px 30px 14px 30px;
  font-size:1.05em; box-shadow:0 2px 8px #ffd60022; position:relative;
}
.alert-box ul { margin-top:12px; margin-bottom:0; padding-left:20px; }
.alert-box li { margin-bottom:8px; font-size:1.02em; }
.alert-box.alert-danger {
  background:#ffe9e7; color:#e54848; border:1.5px solid #ffb3b3; box-shadow:0 2px 8px #ffb3b333;
}
.alert-box.alert-danger strong { color:#b32d2d; }
.alert-box.alert-refund { background:#ffe9e7; }
.alert-box.alert-refund .refund-amount { font-weight:600; color:#b32d2d; }
.alert-box a.action-btn {
  color:#fff; background:#e54848; padding:4px 12px; border-radius:5px;
  font-weight:600; text-decoration:none; font-size:0.95em; margin-left:14px;
}
.alert-box a.action-btn:hover { background:#b32d2d; }
@media (max-width:900px){
  .admin-layout { flex-direction:column; }
  .admin-sidebar { flex-direction:row; width:100%; min-height:unset; padding:0; overflow-x:auto; }
  .admin-sidebar a { flex:1; text-align:center; border-left:none; border-bottom:4px solid transparent; white-space:nowrap; }
  .admin-sidebar a:hover, .admin-sidebar a.active { border-left:none; border-bottom:4px solid #ffd600; }
  .admin-main-content { padding:18px; }
  .dashboard-header .welcome-admin { font-size:1.3em; padding:15px 10px 10px 0; margin-left:0; }
  .dashboard-stats { flex-direction:column; }
}
</style>

<div class="admin-layout">
  <nav class="admin-sidebar" aria-label="Admin navigation">
    <a href="admin_dashboard.php" class="active">Dashboard</a>
    <a href="customers.php">Customers</a>
    <a href="cars.php">Cars</a>
    <a href="bookings.php">Bookings</a>
    <a href="payments.php">Payments &amp; Refunds</a>
    <a href="services.php">Delivery Services</a>
    <a href="report_monthly_income.php">Monthly Income Report</a>
    <a href="report_daily_income.php">Daily Income Report</a>
    <a href="report_most_popular_cars.php">Most Popular Cars</a>
  </nav>

  <main class="admin-main-content">
    <div class="dashboard-header">
      <span class="welcome-admin">👋 Welcome<?= $admin_name ? ', ' . htmlspecialchars($admin_name) : '' ?>!</span>
    </div>

    <?php if (!empty($refund_alerts)): ?>
      <div class="alert-box alert-refund">
        <strong>Action Required:</strong> Pending refunds awaiting processing:
        <ul>
          <?php foreach ($refund_alerts as $r): ?>
            <li>
              <strong>Booking #<?= htmlspecialchars($r['booking_id']) ?></strong>
              (<?= htmlspecialchars($r['car_brand']) ?> <?= htmlspecialchars($r['car_model']) ?> - <?= htmlspecialchars($r['plate_no']) ?>,
              <?= htmlspecialchars($r['full_name']) ?>)
              <span class="refund-amount">MYR <?= number_format((float)$r['amount'], 2) ?></span>
              <span style="color:#888;font-size:0.9em;">Requested: <?= htmlspecialchars(date('Y-m-d', strtotime($r['created_at']))) ?></span>
              <a class="action-btn" href="payments.php#refund-<?= (int)$r['refund_id'] ?>">Process</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($approval_alerts)): ?>
      <div class="alert-box alert-danger">
        <strong>Action Required:</strong> Bookings awaiting approval:
        <ul>
          <?php foreach ($approval_alerts as $a): ?>
            <li>
              <strong><?= htmlspecialchars($a['car_brand']) ?> <?= htmlspecialchars($a['car_model']) ?> (<?= htmlspecialchars($a['plate_no']) ?>)</strong>
              – Booking #<?= (int)$a['booking_id'] ?> (Customer: <?= htmlspecialchars($a['full_name']) ?>)
              <a class="action-btn" href="booking_details.php?id=<?= (int)$a['booking_id'] ?>">Review</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($pickup_alerts)): ?>
      <div class="alert-box">
        <strong>Reminder:</strong> Pickups in next hour needing inspection:
        <ul>
          <?php foreach ($pickup_alerts as $p): ?>
            <li>
              <strong><?= htmlspecialchars($p['car_brand']) ?> <?= htmlspecialchars($p['car_model']) ?> (<?= htmlspecialchars($p['plate_no']) ?>)</strong>
              – Booking #<?= (int)$p['booking_id'] ?>, Pickup at <strong><?= htmlspecialchars(date('H:i', strtotime($p['pickup_datetime']))) ?></strong>
              (Customer: <?= htmlspecialchars($p['full_name']) ?>)
              <a class="action-btn" href="inspection_add.php?booking_id=<?= (int)$p['booking_id'] ?>&type=pickup">Inspect</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($return_alerts)): ?>
      <div class="alert-box">
        <strong>Reminder:</strong> Returns in next hour needing inspection:
        <ul>
          <?php foreach ($return_alerts as $r): ?>
            <li>
              <strong><?= htmlspecialchars($r['car_brand']) ?> <?= htmlspecialchars($r['car_model']) ?> (<?= htmlspecialchars($r['plate_no']) ?>)</strong>
              – Booking #<?= (int)$r['booking_id'] ?>, Return at <strong><?= htmlspecialchars(date('H:i', strtotime($r['return_datetime']))) ?></strong>
              (Customer: <?= htmlspecialchars($r['full_name']) ?>)
              <a class="action-btn" href="inspection_add.php?booking_id=<?= (int)$r['booking_id'] ?>&type=return">Inspect</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="dashboard-stats">
      <div class="stat-card">
        <h2><?= (int)$total_customers ?></h2>
        <p>Customers</p>
      </div>
      <div class="stat-card">
        <h2><?= (int)$total_cars ?></h2>
        <p>Cars</p>
      </div>
      <div class="stat-card">
        <h2><?= (int)$total_bookings ?></h2>
        <p>Bookings</p>
      </div>
      <div class="stat-card">
        <h2><?= (int)$total_payments ?></h2>
        <p>Payments Completed</p>
      </div>
      <div class="stat-card" onclick="window.location.href='pickup_return.php?tab=pickup&date=<?= htmlspecialchars($todayDate) ?>'">
        <h2><?= (int)$pickup_today ?></h2>
        <p>Pickups Today</p>
      </div>
      <div class="stat-card" onclick="window.location.href='pickup_return.php?tab=return&date=<?= htmlspecialchars($todayDate) ?>'">
        <h2><?= (int)$return_today ?></h2>
        <p>Returns Today</p>
      </div>
      <?php /* Example extra KPI:
      <div class="stat-card">
        <h2><?= number_format((float)$currentMonthRevenue, 2) ?></h2>
        <p>Revenue (This Month)</p>
      </div>
      */ ?>
    </div>
  </main>
</div>

<?php include '../includes/footer.php'; ?>