<?php
include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$tab = $_GET['tab'] ?? 'pickup';
$date = $_GET['date'] ?? date('Y-m-d');

// Query: Get bookings for this date (either pickup or return), join driver, service, car, and car_image
$field = $tab == 'return' ? 'return_datetime' : 'pickup_datetime';

$query = "
  SELECT 
      b.booking_id,
      b.$field as date_time,
      s.service_type,
      d.full_name AS driver_name,
      c.car_id,
      c.car_brand,
      c.car_model,
      c.plate_no,
      ci.image_path AS car_image
  FROM booking b
  LEFT JOIN service s ON b.booking_id = s.booking_id
  LEFT JOIN driver d ON b.driver_id = d.driver_id
  LEFT JOIN car c ON b.car_id = c.car_id
  LEFT JOIN (
      SELECT car_id, image_path FROM car_image WHERE car_image_id IN (
          SELECT MIN(car_image_id) FROM car_image GROUP BY car_id
      )
  ) ci ON c.car_id = ci.car_id
  WHERE DATE(b.$field) = ?
    AND b.status NOT IN ('cancelled','rejected')
  ORDER BY b.$field ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param('s', $date);
$stmt->execute();
$result = $stmt->get_result();
$bookings = $result->fetch_all(MYSQLI_ASSOC);

?>
<?php include 'admin_header.php'; ?>

<style>
.pickupreturn-header {
  font-size: 2em;
  font-weight: 600;
  margin-bottom: 12px;
  color: #21243d;
}
.pickupreturn-tabs {
  display: flex;
  gap: 10px;
  margin-bottom: 8px;
}
.pickupreturn-tab {
  padding: 8px 34px;
  border-radius: 12px 12px 0 0;
  font-weight: 500;
  font-size: 1.13em;
  background: none;
  border: none;
  outline: none;
  cursor: pointer;
  color: #a8b2c9;
  border-bottom: 3px solid transparent;
  transition: background 0.17s, color 0.17s, border-bottom 0.17s;
  position: relative;
  margin-bottom: -1px;
}
.pickupreturn-tab.selected {
  color: #181d37;
  background: #f7f8fc;
  border-bottom: 3px solid #b5bee5;
  box-shadow: 0 2px 0 0 #b5bee5;
}
.pickupreturn-tab:not(.selected):hover {
  background: #f0f3fa;
  color: #3a4a7c;
}
.pickupreturn-tabs-underline {
  height: 2px;
  background: #e8eaf1;
  margin-bottom: 20px;
}
.pickupreturn-filter-row {
  display: flex;
  gap: 12px;
  align-items: center;
  margin-bottom: 20px;
}
.pickupreturn-filter-row label {
  font-weight: 500;
  font-size: 1.07em;
}
.pickupreturn-filter-row input[type="date"] {
  padding: 7px 14px;
  border-radius: 6px;
  border: 1px solid #bbb;
  font-size: 1em;
}
.pickupreturn-filter-row button {
  background: #4158d0;
  color: #fff;
  padding: 8px 22px;
  border: none;
  border-radius: 7px;
  font-weight: 500;
  font-size: 1.09em;
  transition: background 0.18s;
  cursor: pointer;
}
.pickupreturn-filter-row button:hover {
  background: #2b5cbc;
}
.booking-card-list {
  margin-top: 14px;
}
.booking-card {
  display: flex;
  align-items: center;
  gap: 18px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 2px 8px #e7eaf1;
  padding: 18px 24px;
  margin-bottom: 18px;
  transition: box-shadow .18s;
  border: 1px solid #f0f1f5;
}
.booking-card:hover {
  box-shadow: 0 4px 20px #d9e3f8;
}
.booking-card-img {
  width: 65px;
  height: 50px;
  border-radius: 8px;
  overflow: hidden;
  background: #e8edfa;
  border: 1px solid #d5e2fc;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.booking-card-img img {
  max-width: 100%;
  max-height: 100%;
  display: block;
}
.booking-card-info {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.booking-card-car {
  font-size: 1.1em;
  color: #227be9;
  font-weight: 600;
  text-decoration: none;
  margin-bottom: 2px;
  display: inline-block;
}
.booking-card-plate {
  color: #7ca1c7;
  font-size: 0.96em;
  font-weight: 500;
  margin-bottom: 3px;
}
.booking-card-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 18px;
  color: #222;
  font-size: 1.01em;
}
.booking-card-driver {
  display: flex;
  align-items: center;
  gap: 5px;
}
.booking-card-driver i {
  color: #2b5cbc;
  font-style: normal;
  font-size: 1.2em;
}
.booking-card-service {
  display: flex;
  align-items: center;
  gap: 4px;
  color: #2b5cbc;
  font-size: 1.05em;
  font-weight: 500;
}
.booking-card-service svg {
  width: 1.15em;
  height: 1.15em;
  display: inline-block;
  vertical-align: middle;
  margin-right: 2px;
  fill: #2b5cbc;
}
.booking-card-datetime {
  margin-left: auto;
  color: #4158d0;
  font-size: 1.04em;
  display: flex;
  align-items: center;
  gap: 5px;
}
.booking-card-datetime i {
  font-style: normal;
  font-size: 1.18em;
}
@media (max-width: 700px) {
  .booking-card { flex-direction: column; gap: 10px; padding: 12px 7px; }
  .booking-card-img { width: 90px; height: 62px; }
  .booking-card-datetime { margin-left: 0; margin-top: 3px;}
}
</style>

<div style="max-width:900px;margin:30px auto 0 auto;">
  <div class="pickupreturn-header">Pickup / Return</div>
  <div class="pickupreturn-tabs">
    <a href="?tab=pickup&date=<?= htmlspecialchars($date) ?>" class="pickupreturn-tab<?= $tab=='pickup' ? ' selected' : '' ?>">Pickup</a>
    <a href="?tab=return&date=<?= htmlspecialchars($date) ?>" class="pickupreturn-tab<?= $tab=='return' ? ' selected' : '' ?>">Return</a>
  </div>
  <div class="pickupreturn-tabs-underline"></div>
  <form method="get" class="pickupreturn-filter-row">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <label for="date-filter">Date</label>
    <input type="date" id="date-filter" name="date" value="<?= htmlspecialchars($date) ?>">
    <button type="submit">Filter</button>
  </form>
  <div class="booking-card-list">
    <?php if (!$bookings): ?>
      <div style="color:#888;background:#fff;border-radius:10px;padding:30px;box-shadow:0 4px 16px #ccc;">
        No <?= $tab ?>s found for this date.
      </div>
    <?php else: ?>
      <?php foreach ($bookings as $b): ?>
        <a href="booking_details.php?id=<?= $b['booking_id'] ?>" style="text-decoration:none;">
          <div class="booking-card">
            <div class="booking-card-img">
              <?php if (!empty($b['car_image'])): ?>
                <img src="data:image/jpeg;base64,<?= base64_encode($b['car_image']) ?>" alt="Car Image">
              <?php else: ?>
                <span style="color:#bcd;">No Image</span>
              <?php endif; ?>
            </div>
            <div class="booking-card-info">
              <a class="booking-card-car" href="booking_details.php?id=<?= $b['booking_id'] ?>">
                <?= htmlspecialchars($b['car_brand'] . ' ' . $b['car_model'] . ' (A)') ?>
              </a>
              <div class="booking-card-plate"><?= htmlspecialchars($b['plate_no']) ?></div>
              <div class="booking-card-row">
                <span class="booking-card-driver"><i>👤</i> <?= htmlspecialchars($b['driver_name'] ?: 'Unassigned') ?></span>
                <span class="booking-card-service">
                  <!-- Delivery/Service Icon (van/truck SVG) -->
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M3 7c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h1a2 2 0 0 0 4 0h6a2 2 0 0 0 4 0h1c1.1 0 2-.9 2-2v-4.5a2 2 0 0 0-.41-1.2l-2.99-4A2 2 0 0 0 17.5 7H3zm0 2h14.5l3 4H21V17c0 .55-.45 1-1 1h-1a2 2 0 0 0-4 0H8a2 2 0 0 0-4 0H3c-.55 0-1-.45-1-1V9c0-.55.45-1 1-1zm3 7a1 1 0 1 1 2 0 1 1 0 0 1-2 0zm12 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0z"/>
                  </svg>
                  <?= htmlspecialchars($b['service_type'] ?: 'Self Pickup') ?>
                </span>
                <span class="booking-card-datetime"><i>←</i> <?= date('d M Y g:i A', strtotime($b['date_time'])) ?></span>
              </div>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php include '../includes/footer.php'; ?>