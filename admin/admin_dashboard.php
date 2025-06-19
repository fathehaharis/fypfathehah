<?php
include '../connect.php';
session_start();

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
$pickup_today = $conn->query("SELECT COUNT(*) FROM booking WHERE DATE(pickup_datetime) = '$today'")->fetch_row()[0];
$return_today = $conn->query("SELECT COUNT(*) FROM booking WHERE DATE(return_datetime) = '$today'")->fetch_row()[0];
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
  </nav>
  <main class="admin-main-content">
    <div class="dashboard-header">
      <span class="welcome-admin">
        👋 Welcome<?= $admin_name ? ', ' . htmlspecialchars($admin_name) : '' ?>!
      </span>
    </div>
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