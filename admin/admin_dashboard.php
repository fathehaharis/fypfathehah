<?php
include '../connect.php';
session_start();

date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Get admin name
$admin_name = '';
$admin_id = $_SESSION['admin_id'];
$stmt = $conn->prepare("SELECT full_name FROM admin WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$stmt->bind_result($admin_name);
$stmt->fetch();
$stmt->close();

// Dashboard stats as before
$total_customers = $conn->query("SELECT COUNT(*) FROM customer")->fetch_row()[0];
$total_cars = $conn->query("SELECT COUNT(*) FROM car")->fetch_row()[0];
$total_bookings = $conn->query("SELECT COUNT(*) FROM booking")->fetch_row()[0];
$total_payments = $conn->query("SELECT COUNT(*) FROM payment WHERE payment_status = 'paid'")->fetch_row()[0];
$today = date('Y-m-d');
$pickup_today = $conn->query("SELECT COUNT(*) FROM booking WHERE DATE(pickup_datetime) = '$today' AND status NOT IN ('cancelled','rejected')")->fetch_row()[0];
$return_today = $conn->query("SELECT COUNT(*) FROM booking WHERE DATE(return_datetime) = '$today' AND status NOT IN ('cancelled','rejected')")->fetch_row()[0];

// --- Inspection Alert/Notification Logic ---

$now = date('Y-m-d H:i:s');
$one_hour_later = date('Y-m-d H:i:s', strtotime('+1 hour'));

$pickup_alerts = [];
$stmt = $conn->prepare("
    SELECT b.booking_id, b.pickup_datetime, c.car_brand, c.car_model, c.plate_no, cust.full_name, b.pickup_mileage, b.pickup_fuel_percent
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    LEFT JOIN customer cust ON b.cust_id = cust.cust_id
    WHERE b.pickup_datetime >= ? AND b.pickup_datetime < ? 
      AND (b.pickup_mileage IS NULL OR b.pickup_fuel_percent IS NULL)
      AND b.status IN ('confirmed','upcoming')
    ORDER BY b.pickup_datetime ASC
");
$stmt->bind_param("ss", $now, $one_hour_later);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $pickup_alerts[] = $row;
}
$stmt->close();

$return_alerts = [];
$stmt = $conn->prepare("
    SELECT b.booking_id, b.return_datetime, c.car_brand, c.car_model, c.plate_no, cust.full_name, b.return_mileage, b.return_fuel_percent
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    LEFT JOIN customer cust ON b.cust_id = cust.cust_id
    WHERE b.return_datetime >= ? AND b.return_datetime < ? 
      AND (b.return_mileage IS NULL OR b.return_fuel_percent IS NULL)
      AND b.status IN ('confirmed','approved','upcoming','completed')
    ORDER BY b.return_datetime ASC
");
$stmt->bind_param("ss", $now, $one_hour_later);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $return_alerts[] = $row;
}
$stmt->close();

// --- Approval Alert/Notification Logic ---
$approval_alerts = [];
$stmt = $conn->prepare("
    SELECT b.booking_id, c.car_brand, c.car_model, c.plate_no, cust.full_name, b.created_at
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    LEFT JOIN customer cust ON b.cust_id = cust.cust_id
    WHERE b.status = 'waiting_verification'
    ORDER BY b.created_at ASC
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $approval_alerts[] = $row;
}
$stmt->close();

// --- Refund Alert/Notification Logic ---
$refund_alerts = [];
$refund_sql = "SELECT r.refund_id, r.booking_id, r.amount, r.created_at, c.full_name, car.car_brand, car.car_model, car.plate_no
               FROM refunds r
               LEFT JOIN booking b ON r.booking_id = b.booking_id
               LEFT JOIN customer c ON b.cust_id = c.cust_id
               LEFT JOIN car ON b.car_id = car.car_id
               WHERE r.refund_status = 'pending'
               ORDER BY r.created_at ASC";
$res = $conn->query($refund_sql);
while ($res && $res->num_rows > 0 && $row = $res->fetch_assoc()) {
    $refund_alerts[] = $row;
}
?>
<?php include 'admin_header.php'; ?>

<style>
.admin-layout {
  display: flex;
  min-height: 100vh;
}
.admin-sidebar {
  width: 220px;
  background: #243570;
  padding: 30px 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-height: 100vh;
}
.admin-sidebar a {
  color: #fff;
  text-decoration: none;
  padding: 14px 32px;
  font-size: 1.07em;
  display: block;
  transition: background 0.18s;
  border-left: 4px solid transparent;
}
.admin-sidebar a:hover,
.admin-sidebar a.active {
  background: #2b5cbc;
  border-left: 4px solid #ffd600;
}
.admin-main-content {
  flex: 1;
  padding: 40px;
  background: #f6f7fb;
}
.dashboard-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 30px;
  margin-top: 10px;
}
.welcome-admin {
  font-size: 2.1em;
  font-weight: 800;
  color: #2b5cbc;
  letter-spacing: 1.2px;
  background: linear-gradient(90deg, #f9fffa 85%, #ffe877 100%);
  padding: 20px 34px 16px 0;
  border-radius: 0 18px 18px 0;
  box-shadow: 0 2px 15px #e0e7ef66;
  display: inline-block;
  margin-left: -40px;
}
.dashboard-stats {
  display: flex;
  flex-wrap: wrap;
  gap: 18px;
  margin-bottom: 30px;
}
.stat-card {
  flex: 1 1 220px;
  background: #fff;
  padding: 28px 18px;
  border-radius: 10px;
  text-align: center;
  box-shadow: 0 2px 7px #e0e7ef;
  min-width: 150px;
  transition: transform 0.12s, box-shadow 0.12s;
}
.stat-card:hover {
  transform: translateY(-5px) scale(1.03);
  box-shadow: 0 6px 22px #b5bee540;
}
.stat-card h2 {
  margin: 0;
  font-size: 2.2rem;
  color: #2b5cbc;
}
.stat-card p {
  margin: 8px 0 0;
  color: #53709a;
  font-size: 1.11em;
  letter-spacing: 0.5px;
}
.stat-card[onclick] {
  cursor:pointer;
  border: 2px solid #e4eaff;
  background: linear-gradient(120deg, #f8fafd 70%, #e5f3ff 100%);
}
.alert-inspection, .alert-approval, .alert-refund {
  background: #fffbe7;
  color: #bfa800;
  border: 1.5px solid #ffe877;
  border-radius: 8px;
  margin-bottom: 28px;
  padding: 20px 30px 14px 30px;
  font-size: 1.11em;
  box-shadow: 0 2px 8px #ffd60022;
  position: relative;
}
.alert-refund {
    background: #ffe9e7;
    color: #e54848;
    border: 1.5px solid #ffb3b3;
    box-shadow: 0 2px 8px #ffb3b333;
}
.alert-refund strong { color: #b32d2d; }
.alert-refund ul { margin-top: 12px; margin-bottom: 0; padding-left: 20px; }
.alert-refund li { margin-bottom: 8px; font-size: 1.02em; }
.alert-refund .refund-amount { font-weight: 600; color: #b32d2d; }
.alert-approval strong, .alert-inspection strong {
  color: #a65c00;
}
.alert-inspection .inspection-list,
.alert-approval .approval-list,
.alert-refund .refund-list {
  margin-top: 12px;
  margin-bottom: 0;
  padding-left: 20px;
}
.alert-inspection .inspection-item,
.alert-approval .approval-item,
.alert-refund .refund-item {
  margin-bottom: 8px;
  font-size: 1.02em;
}
.alert-approval {
  background: #ffe9e7;
  color: #e54848;
  border: 1.5px solid #ffb3b3;
  box-shadow: 0 2px 8px #ffb3b333;
}
.alert-approval strong { color: #b32d2d; }
.alert-approval a {
  color: #fff;
  background: #e54848;
  padding: 4px 12px;
  border-radius: 5px;
  font-weight: 600;
  text-decoration: none;
  font-size: 0.98em;
  margin-left: 16px;
}
.alert-approval a:hover { background: #b32d2d; }
@media (max-width: 900px) {
  .alert-inspection, .alert-approval, .alert-refund { padding: 12px 8vw 8px 8vw; }
}
@media (max-width: 900px) {
  .admin-layout { flex-direction: column; }
  .admin-sidebar { flex-direction: row; width: 100%; min-height: unset; padding: 0;}
  .admin-sidebar a { flex: 1; text-align: center; border-left: none; border-bottom: 4px solid transparent; }
  .admin-sidebar a:hover,
  .admin-sidebar a.active { border-left: none; border-bottom: 4px solid #ffd600;}
  .admin-main-content { padding: 18px; }
  .dashboard-header .welcome-admin { font-size: 1.3em; padding: 15px 10px 10px 0; margin-left: 0;}
  .dashboard-stats { flex-direction: column; }
}
</style>

<div class="admin-layout">
  <nav class="admin-sidebar">
    <a href="admin_dashboard.php" class="active">Dashboard</a>
    <a href="customers.php">Customers</a>
    <a href="cars.php">Cars</a>
    <a href="bookings.php">Bookings</a>
    <a href="drivers.php">Drivers</a>
    <a href="payments.php">Payments And Refunds</a>
    <a href="services.php">Delivery Services</a>
    <a href="report_monthly_income.php">Monthly Income Report</a>
    <a href="report_daily_income.php">Daily Income Report</a>
    <a href="report_most_popular_cars.php">Most Popular Cars Report</a>
  </nav>
  <main class="admin-main-content">
    <div class="dashboard-header">
      <span class="welcome-admin">
        👋 Welcome<?= $admin_name ? ', ' . htmlspecialchars($admin_name) : '' ?>!
      </span>
    </div>

    <?php if (!empty($refund_alerts)): ?>
      <div class="alert-refund">
        <strong>Action Required:</strong> The following refunds are <strong>pending</strong> and need to be processed:
        <ul class="refund-list">
          <?php foreach ($refund_alerts as $alert): ?>
            <li class="refund-item">
              <strong>Booking #<?= htmlspecialchars($alert['booking_id']) ?></strong>
              (<?= htmlspecialchars($alert['car_brand']) ?> <?= htmlspecialchars($alert['car_model']) ?> - <?= htmlspecialchars($alert['plate_no']) ?>,
              <?= htmlspecialchars($alert['full_name']) ?>)
              <span class="refund-amount">MYR <?= number_format($alert['amount'],2) ?></span>
              <span style="color:#888;font-size:0.97em;">Requested: <?= date('Y-m-d', strtotime($alert['created_at'])) ?></span>
              <a href="payments.php#refund-<?= intval($alert['refund_id']) ?>" style="margin-left:16px;color:#fff;background:#e54848;padding:4px 12px;border-radius:5px;font-weight:600;text-decoration:none;font-size:0.98em;">Process Refund</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($approval_alerts)): ?>
      <div class="alert-approval">
        <strong>Action Required:</strong> The following bookings are <strong>awaiting your approval</strong>:
        <ul class="approval-list">
          <?php foreach ($approval_alerts as $alert): ?>
            <li class="approval-item">
              <strong><?= htmlspecialchars($alert['car_brand']) ?> <?= htmlspecialchars($alert['car_model']) ?> (<?= htmlspecialchars($alert['plate_no']) ?>)</strong>
              &ndash; Booking #<?= $alert['booking_id'] ?>
              (Customer: <?= htmlspecialchars($alert['full_name']) ?>)
              <a href="booking_details.php?id=<?= $alert['booking_id'] ?>">Review & Approve</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if (!empty($pickup_alerts)): ?>
      <div class="alert-inspection">
        <strong>Reminder:</strong> The following cars are scheduled for customer pickup within the next hour and <strong>require inspection</strong>:
        <ul class="inspection-list">
          <?php foreach ($pickup_alerts as $alert): ?>
            <li class="inspection-item">
              <strong><?= htmlspecialchars($alert['car_brand']) ?> <?= htmlspecialchars($alert['car_model']) ?> (<?= htmlspecialchars($alert['plate_no']) ?>)</strong>
              &ndash; Booking #<?= $alert['booking_id'] ?>, Pickup at <strong><?= date('H:i', strtotime($alert['pickup_datetime'])) ?></strong>
              (Customer: <?= htmlspecialchars($alert['full_name']) ?>)
              <a href="inspection_add.php?booking_id=<?= $alert['booking_id'] ?>&type=pickup">Do Inspection</a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="dashboard-stats">
      <div class="stat-card">
        <h2><?= $total_customers ?></h2>
        <p>Customers</p>
      </div>
      <div class="stat-card">
        <h2><?= $total_cars ?></h2>
        <p>Cars</p>
      </div>
      <div class="stat-card">
        <h2><?= $total_bookings ?></h2>
        <p>Bookings</p>
      </div>
      <div class="stat-card">
        <h2><?= $total_payments ?></h2>
        <p>Payments Completed</p>
      </div>
      <div class="stat-card" onclick="window.location.href='pickup_return.php?tab=pickup&date=<?= $today ?>'">
        <h2><?= $pickup_today ?></h2>
        <p>Pickups Today</p>
      </div>
      <div class="stat-card" onclick="window.location.href='pickup_return.php?tab=return&date=<?= $today ?>'">
        <h2><?= $return_today ?></h2>
        <p>Returns Today</p>
      </div>
    </div>
    <!-- You can add more dashboard widgets/content here -->
  </main>
</div>

<?php include '../includes/footer.php'; ?>