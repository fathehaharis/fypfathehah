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

/* Fetch customer (driver) */
$cust_id = (int)$_SESSION['cust_id'];
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
$stmt->bind_result($c_full_name,$c_phone_no,$c_id_no,$c_address,$c_age,
                   $c_id_front_image,$c_id_back_image,
                   $c_license_front_image,$c_license_back_image,$c_email);
$stmt->fetch();
$stmt->close();

/* Car */
$car_id = (int)($booking['car_id'] ?? 0);
if ($car_id <= 0) {
    header("Location: book_car.php");
    exit;
}
$stmt = $conn->prepare("SELECT car_brand, car_model, daily_rate, hourly_rate FROM car WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$car) {
    header("Location: book_car.php");
    exit;
}

/* Date/time */
$pickup_raw = $booking['pickup_datetime'] ?? '';
$return_raw = $booking['return_datetime'] ?? '';
try { $pickupDT = new DateTime($pickup_raw); } catch (Throwable $e) { $pickupDT = false; }
try { $returnDT = new DateTime($return_raw); } catch (Throwable $e) { $returnDT = false; }

if (!$pickupDT || !$returnDT || $returnDT <= $pickupDT) {
    $_SESSION['date_error'] = "Invalid pickup/return times.";
    header("Location: book_car.php?car_id=".$car_id);
    exit;
}

/* Pricing (mixed daily/hourly) */
$interval    = $pickupDT->diff($returnDT);
$total_hours = ($interval->days * 24) + $interval->h + ($interval->i > 0 ? 1 : 0);
if ($total_hours <= 0) $total_hours = 1;

$full_days      = intdiv($total_hours, 24);
$leftover_hours = $total_hours % 24;

$daily_rate  = (float)$car['daily_rate'];
$hourly_rate = (float)$car['hourly_rate'];

$subtotal = ($full_days * $daily_rate) + ($leftover_hours * $hourly_rate);
$security_deposit = 100.00;

/* Delivery logic (pending fee) */
$delivery_type      = $booking['delivery_type']      ?? 'self_pickup';
$delivery_location  = trim($booking['delivery_location'] ?? '');
$return_location    = trim($booking['return_location'] ?? '');
$requires_delivery  = in_array($delivery_type, ['delivery','pickup_and_return'], true);
$has_return_segment = ($delivery_type === 'pickup_and_return');

/* Provisional total (exclude delivery fee) */
$provisional_total = $subtotal + $security_deposit;

/* Persist computed values back to session (optional) */
$_SESSION['booking_data']['booking_duration']       = $full_days;
$_SESSION['booking_data']['booking_leftover_hours'] = $leftover_hours;
$_SESSION['booking_data']['security_deposit']       = $security_deposit;
$_SESSION['booking_data']['rental_subtotal']        = $subtotal;
$_SESSION['booking_data']['provisional_total']      = $provisional_total;

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
.badge-mix {
    display:inline-block; background:#3c4cb8; color:#fff; font-size:.65em;
    padding:3px 8px; border-radius:12px; letter-spacing:.5px; margin-left:6px;
}
.pending-fee { color:#b05a00; font-style:italic; }
.info-note { font-size:.78em; color:#68718a; margin-top:4px; }
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
.note-line { font-size:.75em; color:#666; }
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
        <tr>
            <th>Rental Pricing Mode</th>
            <td>
                <?php
                if ($full_days > 0 && $leftover_hours > 0) {
                    echo "Mixed (Daily + Hourly) <span class='badge-mix'>MIXED</span>";
                } elseif ($full_days > 0) {
                    echo "Daily";
                } else {
                    echo "Hourly";
                }
                ?>
            </td>
        </tr>
        <?php if ($full_days > 0): ?>
            <tr><th>Daily Rate</th><td>RM <?= number_format($daily_rate,2) ?></td></tr>
            <tr><th>Number of Days</th><td><?= $full_days ?> day(s)</td></tr>
        <?php endif; ?>
        <?php if ($leftover_hours > 0): ?>
            <tr><th>Hourly Rate</th><td>RM <?= number_format($hourly_rate,2) ?></td></tr>
            <tr><th>Extra Hours</th><td><?= $leftover_hours ?> hour(s)</td></tr>
        <?php endif; ?>
        <tr><th>Pickup Date & Time</th><td><?= htmlspecialchars($pickupDT->format('Y-m-d H:i:s')) ?></td></tr>
        <tr><th>Return Date & Time</th><td><?= htmlspecialchars($returnDT->format('Y-m-d H:i:s')) ?></td></tr>
        <tr><th>Delivery Type</th><td><?= htmlspecialchars(formatDeliveryType($delivery_type)) ?></td></tr>

        <?php if ($requires_delivery && $delivery_location): ?>
            <tr><th>Delivery Location / Notes</th><td><?= nl2br(htmlspecialchars($delivery_location)) ?></td></tr>
        <?php endif; ?>

        <?php if ($has_return_segment && $return_location): ?>
            <tr><th>Return Pickup Location / Notes</th><td><?= nl2br(htmlspecialchars($return_location)) ?></td></tr>
        <?php endif; ?>

        <tr><th>Rental Subtotal</th><td>RM <?= number_format($subtotal,2) ?></td></tr>

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