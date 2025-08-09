<?php
session_start();

ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL); // Remove in production

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

$booking   = $_SESSION['booking_data']   ?? [];
$guarantor = $_SESSION['guarantor_data'] ?? [];

if (empty($booking) || empty($guarantor)) {
    header("Location: book_car.php");
    exit;
}

include '../connect.php';
require '../includes/profile_guard.php';
requireVerifiedProfile($conn, (int)$_SESSION['cust_id']);

$cust_id = (int)$_SESSION['cust_id'];

/* Fetch customer (driver) */
$stmt = $conn->prepare("
    SELECT full_name, phone_no, id_no, address, age,
           id_front_image, id_back_image,
           license_front_image, license_back_image,
           email
    FROM customer
    WHERE cust_id = ?
");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result(
    $c_full_name,$c_phone_no,$c_id_no,$c_address,$c_age,
    $c_id_front_image,$c_id_back_image,
    $c_license_front_image,$c_license_back_image,$c_email
);
$stmt->fetch();
$stmt->close();

/* Car (fetch daily_rate + status for availability check) */
$car_id = (int)($booking['car_id'] ?? 0);
if ($car_id <= 0) {
    header("Location: book_car.php");
    exit;
}
$stmt = $conn->prepare("SELECT car_brand, car_model, daily_rate, status FROM car WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$car) {
    header("Location: book_car.php");
    exit;
}
/* Availability re-check */
if (strcasecmp($car['status'], 'available') !== 0) {
    $_SESSION['booking_error'] = "The selected car has become unavailable. Please choose another vehicle.";
    header("Location: book_car.php?car_id=".$car_id);
    exit;
}

/* Date/time */
$pickup_raw = $booking['pickup_datetime'] ?? '';
$return_raw = $booking['return_datetime'] ?? '';
try { $pickupDT = new DateTime($pickup_raw); } catch (Throwable $e) { $pickupDT = false; }
try { $returnDT = new DateTime($return_raw); } catch (Throwable $e) { $returnDT = false; }

if (!$pickupDT || !$returnDT) {
    $_SESSION['date_error'] = "Invalid pickup/return timestamps.";
    header("Location: book_car.php?car_id=".$car_id);
    exit;
}

/* Ensure return strictly after pickup, full-day rental */
$intervalDays = $pickupDT->diff($returnDT)->days;
if ($returnDT <= $pickupDT || $intervalDays < 1) {
    $_SESSION['date_error'] = "Daily rental must be at least 1 full day.";
    header("Location: book_car.php?car_id=".$car_id);
    exit;
}

/* Overlap re-check (race condition protection) */
$overlapSql = "
    SELECT 1
    FROM booking
    WHERE car_id = ?
      AND status IN ('pending','confirmed')
      AND NOT (return_datetime <= ? OR pickup_datetime >= ?)
    LIMIT 1
";
$overlapStmt = $conn->prepare($overlapSql);
$pickup_sql  = $pickupDT->format('Y-m-d H:i:s');
$return_sql  = $returnDT->format('Y-m-d H:i:s');
$overlapStmt->bind_param('iss', $car_id, $pickup_sql, $return_sql);
$overlapStmt->execute();
$overlapStmt->store_result();
if ($overlapStmt->num_rows > 0) {
    $overlapStmt->close();
    $_SESSION['booking_error'] = "The selected period has just been taken. Please choose new dates.";
    header("Location: book_car.php?car_id=".$car_id);
    exit;
}
$overlapStmt->close();

/* Daily-only pricing */
$rental_days       = $intervalDays; // full days
$daily_rate        = (float)$car['daily_rate'];
$rental_subtotal   = $rental_days * $daily_rate;
$security_deposit  = 100.00;

/* Delivery logic */
$delivery_type      = $booking['delivery_type']      ?? 'self_pickup';
$delivery_location  = trim($booking['delivery_location'] ?? '');
$return_location    = trim($booking['return_location'] ?? '');
$requires_delivery  = in_array($delivery_type, ['delivery','pickup_and_return'], true);
$has_return_segment = ($delivery_type === 'pickup_and_return');

/* Provisional total (delivery fee pending) */
$provisional_total = $rental_subtotal + $security_deposit;

/* Persist back to session */
$_SESSION['booking_data']['rental_days']       = $rental_days;
$_SESSION['booking_data']['security_deposit']  = $security_deposit;
$_SESSION['booking_data']['rental_subtotal']   = $rental_subtotal;
$_SESSION['booking_data']['provisional_total'] = $provisional_total;

function formatDeliveryType($t){ return ucwords(str_replace('_',' ', $t)); }

include '../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background:#eceef4; }
.review-section {
    max-width:780px; margin:40px auto; background:#fff; border-radius:13px;
    box-shadow:0 4px 16px rgba(44,60,102,0.09); padding:34px 44px 32px;
}
.review-title { font-size:1.4em; font-weight:700; color:#2f377d; margin-bottom:26px; }
.section-label {
    margin:28px 0 10px; font-weight:600; color:#2f377d; font-size:1.05em;
    border-bottom:1px solid #e4e6ef; padding-bottom:5px; letter-spacing:.3px;
}
.review-table { width:100%; border-collapse:collapse; margin-bottom:14px; }
.review-table th, .review-table td { padding:8px 12px; vertical-align:top; }
.review-table th {
    width:210px; background:#f5f6fa; font-weight:600; color:#39466d;
    border-right:1px solid #e2e5ee; font-size:.92em;
}
.review-table td { font-size:.95em; color:#333; }
.review-table tr:nth-child(even) td { background:#fafbfe; }
.total-row th, .total-row td { border-top:2px solid #c9d2e8; background:#f1f4fb; }
.total { font-size:1.12em; font-weight:700; color:#1f2f80; }
.pending-fee { color:#b05a00; font-style:italic; }
.note-line { font-size:.75em; color:#666; }
.img-preview-big {
    max-width:150px; max-height:110px; border:1px solid #cdd2dd;
    border-radius:8px; background:#f7f9fd; object-fit:cover; display:block; margin:4px 0;
}
.img-preview-small {
    max-width:120px; max-height:90px; border:1px solid #cdd2dd;
    border-radius:7px; background:#f7f9fd; object-fit:cover; display:block; margin:4px 0;
}
.no-img {
    display:inline-block; font-size:.75em; background:#eee; color:#666;
    padding:4px 10px; border-radius:12px;
}
.btn-row {
    margin-top:34px; text-align:right; display:flex; flex-wrap:wrap; gap:10px; justify-content:flex-end;
}
.action-btn {
    border:none; padding:12px 30px; border-radius:8px; font-size:1.02em;
    font-weight:600; cursor:pointer; transition:.18s;
}
.back-btn { background:#d1d5de; color:#222; text-decoration:none; }
.back-btn:hover { background:#bcc3cf; }
.edit-btn { background:#ffffff; border:2px solid #3c4cb8; color:#2a3e96; text-decoration:none; }
.edit-btn:hover { background:#3c4cb8; color:#fff; }
.next-btn { background:#3c4cb8; color:#fff; }
.next-btn:hover { background:#234c96; }
@media (max-width:880px) {
    .review-section { padding:26px 24px 30px; }
    .review-table th { width:40%; }
}
</style>

<div class="review-section">
    <div class="review-title">Review & Confirm Your Booking</div>

    <div class="section-label">Car & Booking Details</div>
    <table class="review-table">
        <tr><th>Car Selected</th><td><?= htmlspecialchars($car['car_brand'].' '.$car['car_model']) ?></td></tr>
        <tr><th>Pricing Mode</th><td>Daily Only</td></tr>
        <tr><th>Daily Rate</th><td>RM <?= number_format($daily_rate,2) ?></td></tr>
        <tr><th>Number of Days</th><td><?= (int)$rental_days ?> day(s)</td></tr>
        <tr><th>Pickup Date & Time</th><td><?= htmlspecialchars($pickupDT->format('Y-m-d H:i:s')) ?></td></tr>
        <tr><th>Return Date & Time</th><td><?= htmlspecialchars($returnDT->format('Y-m-d H:i:s')) ?></td></tr>
        <tr><th>Delivery Type</th><td><?= htmlspecialchars(formatDeliveryType($delivery_type)) ?></td></tr>

        <?php if ($requires_delivery && $delivery_location): ?>
            <tr><th>Delivery Location / Notes</th><td><?= nl2br(htmlspecialchars($delivery_location)) ?></td></tr>
        <?php endif; ?>

        <?php if ($has_return_segment && $return_location): ?>
            <tr><th>Return Pickup Location / Notes</th><td><?= nl2br(htmlspecialchars($return_location)) ?></td></tr>
        <?php endif; ?>

        <tr><th>Rental Subtotal</th><td>RM <?= number_format($rental_subtotal,2) ?></td></tr>

        <?php if ($requires_delivery): ?>
            <tr>
                <th>Delivery Fee</th>
                <td><span class="pending-fee">Pending (Admin will set later)</span></td>
            </tr>
        <?php endif; ?>

        <tr><th>Security Deposit</th><td>RM <?= number_format($security_deposit,2) ?></td></tr>
        <tr class="total-row">
            <th class="total">
                <?= $requires_delivery ? 'Provisional Total (Excl. Delivery)' : 'Total Payable' ?>
            </th>
            <td class="total">RM <?= number_format($provisional_total,2) ?></td>
        </tr>
        <?php if ($requires_delivery): ?>
            <tr>
                <td colspan="2" class="note-line">
                    Final total will increase once the delivery fee is confirmed.
                </td>
            </tr>
        <?php endif; ?>
    </table>

    <div class="section-label">Customer (Driver) Details</div>
    <table class="review-table">
        <tr><th>Full Name</th><td><?= htmlspecialchars($c_full_name) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($c_email) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($c_phone_no) ?></td></tr>
        <tr><th>ID Number</th><td><?= htmlspecialchars($c_id_no) ?></td></tr>
        <tr><th>Address</th><td><?= htmlspecialchars($c_address) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($c_age) ?></td></tr>
        <tr>
            <th>ID Front Image</th>
            <td>
                <?php if (!empty($c_id_front_image)): ?>
                    <img src="get_id_image.php?type=front&cust_id=<?= $cust_id ?>" class="img-preview-big" alt="ID Front">
                <?php else: ?><span class="no-img">Not uploaded</span><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>ID Back Image</th>
            <td>
                <?php if (!empty($c_id_back_image)): ?>
                    <img src="get_id_image.php?type=back&cust_id=<?= $cust_id ?>" class="img-preview-big" alt="ID Back">
                <?php else: ?><span class="no-img">Not uploaded</span><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>License Front</th>
            <td>
                <?php if (!empty($c_license_front_image)): ?>
                    <img src="get_id_image.php?type=license_front&cust_id=<?= $cust_id ?>" class="img-preview-big" alt="License Front">
                <?php else: ?><span class="no-img">Not uploaded</span><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>License Back</th>
            <td>
                <?php if (!empty($c_license_back_image)): ?>
                    <img src="get_id_image.php?type=license_back&cust_id=<?= $cust_id ?>" class="img-preview-big" alt="License Back">
                <?php else: ?><span class="no-img">Not uploaded</span><?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="section-label">Guarantor Details</div>
    <table class="review-table">
        <tr><th>Full Name</th><td><?= htmlspecialchars($guarantor['guarantor_full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($guarantor['guarantor_phone_no']) ?></td></tr>
        <tr><th>ID Number</th><td><?= htmlspecialchars($guarantor['guarantor_id_no']) ?></td></tr>
        <tr><th>Relationship</th><td><?= htmlspecialchars($guarantor['guarantor_relationship']) ?></td></tr>
        <tr>
            <th>ID Front Image</th>
            <td>
                <?php if (!empty($guarantor['guarantor_id_front']) && file_exists($guarantor['guarantor_id_front'])): ?>
                    <img src="show_temp_image.php?type=guarantor_id_front" class="img-preview-small" alt="Guarantor ID Front">
                <?php else: ?><span class="no-img">Not uploaded</span><?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>ID Back Image</th>
            <td>
                <?php if (!empty($guarantor['guarantor_id_back']) && file_exists($guarantor['guarantor_id_back'])): ?>
                    <img src="show_temp_image.php?type=guarantor_id_back" class="img-preview-small" alt="Guarantor ID Back">
                <?php else: ?><span class="no-img">Not uploaded</span><?php endif; ?>
            </td>
        </tr>
    </table>

    <div class="btn-row">
        <a href="booking_guarantor.php" class="action-btn back-btn">Back (Guarantor)</a>
        <a href="book_car.php?car_id=<?= htmlspecialchars($car_id) ?>" class="action-btn edit-btn">Edit Car / Dates</a>
        <form action="booking_agreement.php" method="post" style="display:inline;">
            <button type="submit" class="action-btn next-btn">Proceed to Agreement</button>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>