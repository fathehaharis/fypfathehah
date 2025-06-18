<div class="admin-sidebar">
    <a href="admin_dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">Dashboard</a>
    <a href="customers.php" class="<?= basename($_SERVER['PHP_SELF']) == 'customers.php' ? 'active' : '' ?>">Customers</a>
    <a href="cars.php" class="<?= basename($_SERVER['PHP_SELF']) == 'cars.php' ? 'active' : '' ?>">Cars</a>
    <a href="bookings.php" class="<?= basename($_SERVER['PHP_SELF']) == 'bookings.php' ? 'active' : '' ?>">Manage Bookings</a>
    <a href="drivers.php" class="<?= basename($_SERVER['PHP_SELF']) == 'drivers.php' ? 'active' : '' ?>">Drivers</a>
    <a href="payments.php" class="<?= basename($_SERVER['PHP_SELF']) == 'payments.php' ? 'active' : '' ?>">Manage Payments</a>
    <a href="refunds.php" class="<?= basename($_SERVER['PHP_SELF']) == 'refunds.php' ? 'active' : '' ?>">Manage Refunds</a>
    <a href="services.php" class="<?= basename($_SERVER['PHP_SELF']) == 'services.php' ? 'active' : '' ?>">Booking Services</a>
    <a href="agreement_forms.php" class="<?= basename($_SERVER['PHP_SELF']) == 'agreement_forms.php' ? 'active' : '' ?>">Agreement Forms</a>
</div>

<style>
.admin-sidebar {
  position: fixed;
  top: 78px; /* Adjust this if your header is a different height */
  left: 0;
  width: 220px; /* Match the dashboard sidebar width */
  height: calc(100vh - 78px);
  background: #22346b;
  box-shadow: 2px 0 12px #0001;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  padding: 0;
}
.admin-sidebar a {
  display: block;
  color: #fff;
  background: none;
  padding: 18px 32px 18px 28px;
  font-size: 1.1em;
  font-weight: 400;
  letter-spacing: 0.01em;
  text-decoration: none;
  transition: background 0.13s, color 0.13s, border 0.13s;
  border-left: 6px solid transparent;
  margin-bottom: 0;
  line-height: 1.2;
}
.admin-sidebar a.active,
.admin-sidebar a:hover {
  background: #3c60c5;
  color: #fff;
  border-left: 6px solid #ffe600;
}
@media (max-width: 900px) {
  .admin-sidebar { width: 56px; }
  .admin-sidebar a {
    font-size: 0;
    padding: 18px 8px 18px 8px;
    text-align: center;
  }
  .admin-sidebar a.active,
  .admin-sidebar a:hover {
    border-left: none;
    border-right: 6px solid #ffe600;
  }
}
</style>