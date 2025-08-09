<?php
/**
 * view_booking.php (Daily-only)
 * Read-only booking detail page.
 * Adjusted to new image schema (car_image via car_image_id + get_car_image.php) and delivery service handling.
 */

session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}
date_default_timezone_set('Asia/Kuala_Lumpur');
require '../connect.php';

if (!isset($_GET['booking_id']) || !ctype_digit($_GET['booking_id'])) {
    include '../includes/header.php';
    echo "<div style='max-width:640px;margin:60px auto;font-family:sans-serif;'>
            <p>Invalid booking ID.</p>
            <a href='bookings.php' style='color:#2f4d85;font-weight:600;text-decoration:none;'>&larr; Back to Bookings</a>
          </div>";
    include '../includes/footer.php';
    exit;
}

$booking_id = (int)$_GET['booking_id'];
$cust_id    = (int)$_SESSION['cust_id'];

/* -------- Fetch Booking + Car + Representative Image + Service + Agreement (UPDATED QUERY) -------- */
$sql = "
SELECT
    b.booking_id,
    b.cust_id,
    b.car_id,
    b.pickup_datetime,
    b.return_datetime,
    b.day_count,
    b.daily_rate,
    b.total_price,
    b.security_deposit,
    b.status,
    b.created_at,
    b.rejection_reason,
    c.car_brand,
    c.car_model,
    c.daily_rate AS car_daily_rate,
    c.year,
    c.color,
    c.mileage,
    c.plate_no,
    c.transmission,
    c.seat_capacity,
    COALESCE(main_img.main_image_id, any_img.any_image_id) AS car_image_id,
    svc.service_type,
    svc.fee AS service_fee,
    svc.status AS service_status,
    svc.delivery_location,
    svc.return_location,
    ag.agreement_id
FROM booking b
JOIN car c ON b.car_id = c.car_id
LEFT JOIN (
    SELECT car_id, MIN(car_image_id) AS main_image_id
    FROM car_image
    WHERE image_type='main'
    GROUP BY car_id
) main_img ON c.car_id = main_img.car_id
LEFT JOIN (
    SELECT car_id, MIN(car_image_id) AS any_image_id
    FROM car_image
    GROUP BY car_id
) any_img ON c.car_id = any_img.car_id
LEFT JOIN service svc
  ON svc.booking_id = b.booking_id
 AND svc.service_type IN ('delivery','pickup_and_return')
LEFT JOIN agreement_form ag
  ON ag.booking_id = b.booking_id
WHERE b.booking_id = ? AND b.cust_id = ?
LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $booking_id, $cust_id);
$stmt->execute();
$res = $stmt->get_result();
$booking = $res->fetch_assoc();
$stmt->close();

if (!$booking) {
    include '../includes/header.php';
    echo "<div style='max-width:640px;margin:60px auto;font-family:sans-serif;'>
            <p>Booking not found or access denied.</p>
            <a href='bookings.php' style='color:#2f4d85;font-weight:600;text-decoration:none;'>&larr; Back to Bookings</a>
          </div>";
    include '../includes/footer.php';
    exit;
}

/* -------- Customer (driver) -------- */
$stmt = $conn->prepare("
    SELECT full_name, phone_no, email, id_no, address, age
    FROM customer
    WHERE cust_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* -------- Guarantor (latest for this customer) -------- */
$stmt = $conn->prepare("
    SELECT guarantor_id, full_name, phone_no, id_no, relationship
    FROM guarantor
    WHERE cust_id = ?
    ORDER BY guarantor_id DESC
    LIMIT 1
");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$guarantor = $stmt->get_result()->fetch_assoc();
$stmt->close();

/* -------- Agreement link -------- */
$agreement_id = $booking['agreement_id'] ?? null;
$agreement_download_link = $agreement_id ? "download_agreement.php?id=" . urlencode((string)$agreement_id) : "";

/* -------- Delivery / Service (already joined) -------- */
$has_service        = in_array($booking['service_type'] ?? '', ['delivery','pickup_and_return'], true);
$service_fee        = $booking['service_fee'];
$service_fee_pending= $has_service && is_null($service_fee);
$delivery_type_display = $has_service
    ? ($booking['service_type'] === 'pickup_and_return' ? 'Pickup & Return' : 'Delivery')
    : 'Self Pickup';
$delivery_fee_display = '-';
$expected_delivery_part = 0.0;

if ($has_service) {
    if ($service_fee_pending) {
        $delivery_fee_display = '<span class="fee-pending">Pending</span>';
    } elseif ((float)$service_fee === 0.0) {
        $delivery_fee_display = '<span class="fee-free">Free</span>';
    } else {
        $delivery_fee_display = 'RM '.number_format((float)$service_fee,2);
        $expected_delivery_part = (float)$service_fee;
    }
}

/* -------- Daily Rental Computation -------- */
$daily_rate = (float)($booking['daily_rate'] ?? $booking['car_daily_rate'] ?? 0);
$day_count  = (int)$booking['day_count'];
if ($day_count <= 0) {
    try {
        $p = new DateTime($booking['pickup_datetime']);
        $r = new DateTime($booking['return_datetime']);
        $diff_days = (int)$p->diff($r)->days;
        $day_count = max(1, $diff_days);
    } catch (Throwable $e) {
        $day_count = 1;
    }
}
$base_rental       = $daily_rate * $day_count;
$security_deposit  = (float)$booking['security_deposit'];
$stored_total      = (float)$booking['total_price'];
$expected_total    = $base_rental + $security_deposit + $expected_delivery_part;
$totals_match      = abs($stored_total - $expected_total) < 0.01;

/* -------- Status styling -------- */
$status = strtolower($booking['status']);
$status_class = match($status) {
    'pending', 'waiting_verification' => 'status-pending',
    'approved'  => 'status-approved',
    'confirmed' => 'status-confirmed',
    'completed' => 'status-completed',
    'cancelled', 'rejected' => 'status-cancelled',
    default     => 'status-upcoming'
};

include '../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background:#eceef4; }
.view-wrapper { max-width:860px; margin:42px auto 70px; background:#fff; border-radius:16px; box-shadow:0 6px 22px -3px rgba(34,52,94,0.12); padding:42px 50px 56px; font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; }
.view-title { font-size:1.55em; font-weight:700; color:#27386d; margin:0 0 28px; }
.section-label { margin:34px 0 10px; font-weight:700; font-size:.9em; letter-spacing:.5px; color:#25375e; text-transform:uppercase; }
.data-table { width:100%; border-collapse:collapse; margin-bottom:24px; }
.data-table th, .data-table td { padding:10px 14px; text-align:left; border-bottom:1px solid #eef1f5; vertical-align:top; }
.data-table th { width:230px; font-weight:600; background:#f7f9fc; font-size:.82em; letter-spacing:.45px; color:#2c3e60; }
.status-label { padding:6px 16px; border-radius:24px; font-weight:600; font-size:.75em; letter-spacing:.5px; display:inline-block; }
.status-pending { background:#fff4cf; color:#9f7800; }
.status-approved { background:#e8f1fd; color:#2c4f94; }
.status-confirmed, .status-upcoming { background:#e3edff; color:#28438f; }
.status-completed { background:#dff9e4; color:#1b7c3b; }
.status-cancelled { background:#ffe0e0; color:#c33b3b; }
.fee-pending { background:#fff2d9; color:#b36a08; padding:4px 10px; border-radius:10px; font-weight:600; font-size:.70em; }
.fee-free { background:#e3fbe6; color:#1d7a43; padding:5px 14px; border-radius:25px; font-weight:700; font-size:.65em; letter-spacing:.4px; }
.mismatch-flag { color:#b85600; font-weight:600; font-size:.72em; margin-left:8px; }
.match-flag { color:#227a41; font-weight:600; font-size:.72em; margin-left:8px; }
.inline-note { font-size:.68em; color:#6b7a93; margin-top:6px; line-height:1.4em; }
.back-link { display:inline-block; margin-top:40px; font-size:.82em; text-decoration:none; color:#2f4d85; font-weight:600; }
.back-link:hover { text-decoration:underline; }
.car-thumb { width:200px; height:118px; object-fit:cover; border-radius:12px; border:1px solid #d5dae3; background:#f1f4f9; display:block; margin-bottom:6px; }
.warning-box { background:#fff2f2; border:1px solid #f2c4c4; color:#a43434; padding:12px 16px; border-radius:10px; font-size:.72em; line-height:1.35em; }
.download-link { text-decoration:none; font-weight:600; color:#2f4d85; }
.download-link:hover { text-decoration:underline; }
</style>

<div class="view-wrapper">
    <div class="view-title">Booking Details</div>

    <div class="section-label">Booking & Car</div>
    <table class="data-table">
        <tr>
            <th>Booking ID</th>
            <td>#<?= htmlspecialchars($booking['booking_id']) ?></td>
        </tr>
        <tr>
            <th>Status</th>
            <td>
                <span class="status-label <?= $status_class ?>"><?= ucfirst($status) ?></span>
                <?php if ($status === 'rejected' && !empty($booking['rejection_reason'])): ?>
                    <div class="inline-note">Reason: <?= htmlspecialchars($booking['rejection_reason']) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Car</th>
            <td>
                <?php if (!empty($booking['car_image_id'])): ?>
                    <img class="car-thumb"
                         src="get_car_image.php?car_image_id=<?= (int)$booking['car_image_id'] ?>"
                         alt="Car Image"
                         onerror="this.src='/assets/images/no-car.png'">
                <?php else: ?>
                    <img class="car-thumb" src="/assets/images/no-car.png" alt="No Car Image">
                <?php endif; ?>
                <?= htmlspecialchars($booking['car_brand'].' '.$booking['car_model']) ?> (<?= htmlspecialchars($booking['plate_no']) ?>)
            </td>
        </tr>
        <tr>
            <th>Daily Rate</th>
            <td>RM <?= number_format($daily_rate,2) ?> x <?= $day_count ?> day(s) = RM <?= number_format($base_rental,2) ?></td>
        </tr>
        <tr>
            <th>Pickup Datetime</th>
            <td><?= htmlspecialchars($booking['pickup_datetime']) ?></td>
        </tr>
        <tr>
            <th>Return Datetime</th>
            <td><?= htmlspecialchars($booking['return_datetime']) ?></td>
        </tr>
        <tr>
            <th>Created At</th>
            <td><?= htmlspecialchars($booking['created_at']) ?></td>
        </tr>
    </table>

    <div class="section-label">Delivery / Service</div>
    <table class="data-table">
        <tr><th>Delivery Type</th><td><?= htmlspecialchars($delivery_type_display) ?></td></tr>
        <tr><th>Delivery Fee</th><td><?= $delivery_fee_display ?></td></tr>
        <?php if ($has_service): ?>
            <?php if (!empty($booking['delivery_location'])): ?>
                <tr><th>Delivery Location / Notes</th><td><?= nl2br(htmlspecialchars($booking['delivery_location'])) ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($booking['return_location'])): ?>
                <tr><th>Return Pickup Location / Notes</th><td><?= nl2br(htmlspecialchars($booking['return_location'])) ?></td></tr>
            <?php endif; ?>
            <tr><th>Service Status</th><td><?= htmlspecialchars($booking['service_status'] ?? '-') ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="section-label">Financial Summary</div>
    <table class="data-table">
        <tr><th>Base Rental</th><td>RM <?= number_format($base_rental,2) ?></td></tr>
        <tr><th>Security Deposit</th><td>RM <?= number_format($security_deposit,2) ?></td></tr>
        <tr><th>Delivery Fee (expected)</th><td>RM <?= number_format($expected_delivery_part,2) ?></td></tr>
        <tr>
            <th>Expected Total</th>
            <td>
                RM <?= number_format($expected_total,2) ?>
                <?php if ($totals_match): ?>
                    <span class="match-flag">OK</span>
                <?php else: ?>
                    <span class="mismatch-flag">Mismatch (Stored: RM <?= number_format($stored_total,2) ?>)</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><th>Stored Total (DB)</th><td>RM <?= number_format($stored_total,2) ?></td></tr>
    </table>
    <div class="inline-note">
        Stored total reflects delivery fee once applied. While delivery fee is pending, expected total excludes that fee.
    </div>

    <div class="section-label">Customer (Driver)</div>
    <table class="data-table">
        <tr><th>Full Name</th><td><?= htmlspecialchars($customer['full_name'] ?? '-') ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($customer['phone_no'] ?? '-') ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($customer['email'] ?? '-') ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($customer['id_no'] ?? '-') ?></td></tr>
        <tr><th>Address</th><td><?= nl2br(htmlspecialchars($customer['address'] ?? '-')) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($customer['age'] ?? '-') ?></td></tr>
    </table>

    <div class="section-label">Guarantor</div>
    <?php if ($guarantor): ?>
        <table class="data-table">
            <tr><th>Name</th><td><?= htmlspecialchars($guarantor['full_name']) ?></td></tr>
            <tr><th>Phone</th><td><?= htmlspecialchars($guarantor['phone_no']) ?></td></tr>
            <tr><th>ID No</th><td><?= htmlspecialchars($guarantor['id_no'] ?? '-') ?></td></tr>
            <tr><th>Relationship</th><td><?= htmlspecialchars($guarantor['relationship'] ?? '-') ?></td></tr>
        </table>
    <?php else: ?>
        <div class="warning-box">
            No guarantor record found for this customer.
        </div>
    <?php endif; ?>

    <div class="section-label">Agreement</div>
    <table class="data-table">
        <tr>
            <th>Agreement PDF</th>
            <td>
                <?php if ($agreement_id): ?>
                    <a class="download-link" href="<?= htmlspecialchars($agreement_download_link) ?>" target="_blank">Download Agreement</a>
                <?php else: ?>
                    Not generated yet.
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <a class="back-link" href="bookings.php">&larr; Return to My Bookings</a>
</div>

<?php include '../includes/footer.php'; ?>