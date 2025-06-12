<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

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
}
.stat-card h2 {
  margin: 0;
  font-size: 2.2rem;
  color: #2b5cbc;
}
.stat-card p {
  margin: 8px 0 0;
  color: #53709a;
}
@media (max-width: 900px) {
  .admin-layout { flex-direction: column; }
  .admin-sidebar { flex-direction: row; width: 100%; min-height: unset; padding: 0;}
  .admin-sidebar a { flex: 1; text-align: center; border-left: none; border-bottom: 4px solid transparent; }
  .admin-sidebar a:hover,
  .admin-sidebar a.active { border-left: none; border-bottom: 4px solid #ffd600;}
  .admin-main-content { padding: 18px; }
  .dashboard-stats { flex-direction: column; }
}
</style>

<div class="admin-layout">
  <nav class="admin-sidebar">
    <a href="admin_dashboard.php" class="active">Dashboard</a>
    <a href="customers.php">Manage Customers</a>
    <a href="cars.php">Manage Cars</a>
    <a href="bookings.php">Manage Bookings</a>
    <a href="drivers.php">Manage Drivers</a>
    <a href="guarantors.php">Manage Guarantors</a>
    <a href="payments.php">Manage Payments</a>
    <a href="refunds.php">Manage Refunds</a>
    <a href="services.php">Booking Services</a>
    <a href="agreement_forms.php">Agreement Forms</a>

  </nav>
  <main class="admin-main-content">
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
        <div class="stat-card" style="cursor:pointer;" onclick="window.location.href='pickup_return.php?tab=pickup&date=<?= $today ?>'">
        <h2><?= $pickup_today ?></h2>
        <p>Pickups Today</p>
        </div>
        <div class="stat-card" style="cursor:pointer;" onclick="window.location.href='pickup_return.php?tab=return&date=<?= $today ?>'">
        <h2><?= $return_today ?></h2>
        <p>Returns Today</p>
        </div>
    </div>
    <!-- You can add more dashboard widgets/content here -->
  </main>
</div>

<?php include '../includes/footer.php'; ?>