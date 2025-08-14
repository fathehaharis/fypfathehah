<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');
require '../connect.php';

$cust_id = (int)$_SESSION['cust_id'];

/* -------------------------------------------------
   CSRF token (required by cancel_booking.php)
------------------------------------------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* -------------------------------------------------
   Refund Policy for display (calendar days)
   (Matches new policy: 3+ days: 100%, 1-2 days: 50%, same day: 0%)
------------------------------------------------- */
const REFUND_POLICY_DAYS = [
    3 => 1.00,  // 3 or more days before pickup
    1 => 0.50,  // 1–2 days
    0 => 0.00   // same day
];
function buildRefundPolicyLinesDays(): array {
    return [
        "Cancel <b>3 or more calendar days</b> before pickup: <b>100% refund of rental fee</b>",
        "Cancel <b>1–2 calendar days</b> before pickup: <b>50% refund of rental fee</b>",
        "Cancel <b>Same day</b>: <b>0% refund of rental fee</b>"
    ];
}

/* -------------------------------------------------
   Status mapping to UI sections
------------------------------------------------- */
$status_map = [
    'pending'              => 'Pending',
    'waiting_verification' => 'Pending',
    'approved'             => 'Pending',
    'confirmed'            => 'Upcoming',
    'completed'            => 'Completed',
    'cancelled'            => 'Cancelled',
    'rejected'             => 'Cancelled'
];

$bookings = [
    'Pending'   => [],
    'Upcoming'  => [],
    'Completed' => [],
    'Cancelled' => []
];

/*
  Representative car image:
    - Prefer main image (image_type='main')
    - Else earliest uploaded image
*/
$stmt = $conn->prepare("
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
        c.car_brand,
        c.car_model,
        COALESCE(main_img.main_image_id, any_img.any_image_id) AS car_image_id,
        ds.delivery_service_type,
        ds.delivery_service_status,
        ds.delivery_service_fee
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
    LEFT JOIN (
        SELECT 
            booking_id,
            MAX(CASE WHEN service_type IN ('delivery','pickup_and_return') THEN service_type END) AS delivery_service_type,
            MAX(CASE WHEN service_type IN ('delivery','pickup_and_return') THEN status END) AS delivery_service_status,
            MAX(CASE WHEN service_type IN ('delivery','pickup_and_return') THEN fee END) AS delivery_service_fee
        FROM service
        GROUP BY booking_id
    ) ds ON ds.booking_id = b.booking_id
    WHERE b.cust_id = ?
    ORDER BY b.pickup_datetime DESC
");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $raw_status = strtolower(trim($row['status']));
    $section = $status_map[$raw_status] ?? 'Upcoming';
    $bookings[$section][] = $row;
}
$stmt->close();

/* -------------------------------------------------
   Helpers
------------------------------------------------- */
function computeDayCount(array $b): int {
    if (!empty($b['day_count']) && (int)$b['day_count'] > 0) return (int)$b['day_count'];
    try {
        $p = new DateTime($b['pickup_datetime']);
        $r = new DateTime($b['return_datetime']);
        return max(1, (int)$p->diff($r)->days);
    } catch (Throwable $e) {
        return 1;
    }
}

function daysToPickup(array $b): int {
    try {
        $now = (new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur')))->setTime(0,0,0,0);
        $pickup = (new DateTime($b['pickup_datetime'], new DateTimeZone('Asia/Kuala_Lumpur')))->setTime(0,0,0,0);
        return (int)$now->diff($pickup)->format('%r%a'); // negative if after pickup
    } catch (Throwable $e) {
        return -999;
    }
}

function canCancel(array $b): bool {
    // Now using calendar day logic, matching cancel_booking.php!
    $status = strtolower($b['status']);
    if (!in_array($status, ['pending','approved','waiting_verification','confirmed'], true)) {
        return false;
    }
    $days_to_pickup = daysToPickup($b);
    if ($days_to_pickup < 0) return false; // after pickup
    return true; // always allow, refund depends on policy
}

/* -------------------------------------------------
   Flash messages
------------------------------------------------- */
$flash_success = $_SESSION['cancel_success'] ?? '';
$flash_error   = $_SESSION['cancel_error'] ?? '';
unset($_SESSION['cancel_success'], $_SESSION['cancel_error']);

include '../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.bookings-main-layout { display:flex; gap:36px; max-width:1200px; margin:44px auto 60px; }
.bookings-sidebar { width:210px; min-width:180px; background:#f7faff; border-radius:13px; box-shadow:0 4px 18px rgba(44,60,102,0.07); padding:32px 0; }
.bookings-sidebar ul { list-style:none; margin:0; padding:0; }
.bookings-sidebar-btn { display:block; padding:14px 26px; border:none; background:none; width:100%; text-align:left; font-size:1.04em; font-weight:600; color:#3c4cb8; border-radius:0 22px 22px 0; transition:.15s; cursor:pointer; border-left:4px solid transparent; }
.bookings-sidebar-btn.active, .bookings-sidebar-btn:hover { background:#e7edfa; color:#234c96; border-left:4px solid #3c4cb8; }
.bookings-content { flex:1; background:#fff; padding:36px 44px; border-radius:13px; box-shadow:0 4px 18px rgba(44,60,102,0.09); }
.bookings-title { font-size:1.4em; font-weight:700; color:#2f377d; margin-bottom:26px; text-align:center; }
.booking-table { width:100%; border-collapse:collapse; }
.booking-table th, .booking-table td { border-bottom:1px solid #f0f1f4; padding:9px 8px 9px 0; text-align:left; font-size:1.01em; vertical-align:middle; }
.booking-table th.delivery-col, .booking-table td.delivery-col { width:120px; }
.booking-status { padding:4px 12px; border-radius:8px; font-weight:600; font-size:.98em; display:inline-block; }
.status-pending { background:#fffbe7; color:#bfa800; }
.status-upcoming { background:#f7faff; color:#2f377d; }
.status-completed { background:#e3fbe6; color:#219150; }
.status-cancelled { background:#fde9e9; color:#d42d2d; }
.booking-car-img-thumb { width:88px; height:56px; object-fit:cover; border-radius:7px; border:1px solid #dadada; background:#f2f3f8; margin-right:7px; vertical-align:middle; }
.action-btn { background:#3c4cb8; color:#fff; border:none; border-radius:7px; padding:7px 16px; font-size:1em; font-weight:500; text-decoration:none; cursor:pointer; transition:.17s; display:inline-block; }
.action-btn.cancel { background:#d42d2d; }
.action-btn.cancel:hover { background:#b82323; }
.action-btn.view:hover { background:#234c96; }
.badge-delivery { display:inline-block; margin-left:6px; background:#ffedc2; color:#9b6200; padding:3px 8px; font-size:.65em; font-weight:700; border-radius:12px; letter-spacing:.5px; text-transform:uppercase; }
.delivery-fee-pending { font-size:.78em; font-weight:600; color:#b36a08; background:#fff3d9; padding:3px 8px; border-radius:8px; display:inline-block; }
.delivery-fee-free { font-size:.70em; font-weight:700; color:#1d7a43; background:#e3fbe6; padding:4px 10px; border-radius:20px; display:inline-block; letter-spacing:.4px; }
.delivery-fee-dash { color:#999; font-size:.9em; }
.fee-tooltip-wrap { position:relative; display:inline-block; }
.fee-info-icon { display:inline-block; width:16px; height:16px; line-height:16px; text-align:center; font-size:11px; font-weight:700; border-radius:50%; background:#3c4cb8; color:#fff; margin-left:4px; cursor:help; }
.fee-tooltip-wrap .fee-tooltip { visibility:hidden; opacity:0; position:absolute; z-index:10; top:22px; left:0; background:#1f2740; color:#fff; padding:8px 10px; border-radius:8px; font-size:.72em; width:210px; transition:.15s; box-shadow:0 4px 12px rgba(10,20,45,.25); }
.fee-tooltip-wrap:hover .fee-tooltip { visibility:visible; opacity:1; }
.back-btn { width:180px; margin:22px auto 0; display:block; background:#c2c7d6; color:#2f377d; border:none; padding:13px 0; border-radius:8px; font-size:1.08em; font-weight:600; cursor:pointer; text-align:center; text-decoration:none; transition:.18s; }
.back-btn:hover { background:#b4bac9; color:#162040; }
.refund-policy-box { background:#f5f8ff; border:1px solid #d7e2f5; padding:12px 16px; border-radius:10px; font-size:.78em; color:#2c3e60; margin:0 0 26px; line-height:1.4em; }
.flash { padding:12px 16px; border-radius:10px; margin:0 0 22px; font-size:.85em; font-weight:600; }
.flash.success { background:#e3fbe6; color:#1d7a43; border:1px solid #b6e9c0; }
.flash.error { background:#fde9e9; color:#b82323; border:1px solid #f5c2c2; }
@media (max-width:900px){
  .bookings-main-layout { flex-direction:column; gap:0; }
  .bookings-sidebar { width:100%; border-radius:13px 13px 0 0; display:flex; justify-content:space-around; padding:0; }
  .bookings-sidebar ul { display:flex; width:100%; }
  .bookings-sidebar-btn { border-radius:0; border-left:none; border-bottom:4px solid transparent; text-align:center; padding:14px 0; font-size:1em; }
  .bookings-sidebar-btn.active, .bookings-sidebar-btn:hover { border-bottom:4px solid #3c4cb8; background:#e7edfa; }
  .bookings-content { padding:22px 4vw; }
  .booking-table th.delivery-col, .booking-table td.delivery-col { width:auto; }
}
</style>
<script>
function showSection(section){
    document.querySelectorAll('.bookings-content-section').forEach(div=>div.style.display='none');
    const el=document.getElementById('section-'+section);
    if(el) el.style.display='';
    document.querySelectorAll('.bookings-sidebar-btn').forEach(btn=>btn.classList.remove('active'));
    const btn=document.getElementById('sidebar-btn-'+section);
    if(btn) btn.classList.add('active');
}
document.addEventListener('DOMContentLoaded',()=>{
    let defaultSection='Pending';
    <?php if (empty($bookings['Pending']) && !empty($bookings['Upcoming'])): ?>
        defaultSection='Upcoming';
    <?php elseif (empty($bookings['Pending']) && empty($bookings['Upcoming']) && !empty($bookings['Completed'])): ?>
        defaultSection='Completed';
    <?php elseif (empty($bookings['Pending']) && empty($bookings['Upcoming']) && empty($bookings['Completed'])): ?>
        defaultSection='Cancelled';
    <?php endif; ?>
    showSection(defaultSection);
    ['Pending','Upcoming','Completed','Cancelled'].forEach(s=>{
        const b=document.getElementById('sidebar-btn-'+s);
        if(b) b.onclick=()=>showSection(s);
    });
});
</script>

<div class="bookings-main-layout">
    <nav class="bookings-sidebar">
        <ul>
            <li><button class="bookings-sidebar-btn active" id="sidebar-btn-Pending" type="button">Pending</button></li>
            <li><button class="bookings-sidebar-btn" id="sidebar-btn-Upcoming" type="button">Upcoming</button></li>
            <li><button class="bookings-sidebar-btn" id="sidebar-btn-Completed" type="button">Completed</button></li>
            <li><button class="bookings-sidebar-btn" id="sidebar-btn-Cancelled" type="button">Cancelled</button></li>
        </ul>
    </nav>
    <div class="bookings-content">
        <div class="bookings-title">My Bookings</div>

        <?php if ($flash_success): ?>
            <div class="flash success"><?= htmlspecialchars($flash_success) ?></div>
        <?php endif; ?>
        <?php if ($flash_error): ?>
            <div class="flash error"><?= htmlspecialchars($flash_error) ?></div>
        <?php endif; ?>

<div class="refund-policy-box">
    <strong>Cancellation Refund Policy</strong><br>
    <ul style="margin:8px 0 6px 16px; padding:0;">
        <li>Cancel <b>3 or more calendar days</b> before pickup: <b>100% refund of rental fee</b></li>
        <li>Cancel <b>1–2 calendar days</b> before pickup: <b>50% refund of rental fee</b></li>
        <li>Cancel <b>Same day</b>: <b>0% refund of rental fee</b></li>
    </ul>
    <span style="color:#3b4a6b;">Security deposit is non-refundable.</span>
</div>

        <?php foreach (['Pending','Upcoming','Completed','Cancelled'] as $section): ?>
            <div id="section-<?= $section ?>" class="bookings-content-section" style="display:none;">
                <h3 class="bookings-section-title" style="margin:0 0 14px;font-size:1.05em;color:#2f377d;"><?= htmlspecialchars($section) ?> Bookings</h3>
                <?php if (empty($bookings[$section])): ?>
                    <div class="no-bookings" style="color:#666;font-size:.92em;">No <?= strtolower($section) ?> bookings found.</div>
                <?php else: ?>
                    <table class="booking-table">
                        <tr>
                            <th>Car</th>
                            <th>Pickup Date</th>
                            <th>Return Date</th>
                            <th>Duration</th>
                            <th class="delivery-col">Delivery Fee (RM)</th>
                            <th>Total (RM)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <?php foreach ($bookings[$section] as $b): ?>
                            <?php
                                $duration_days = computeDayCount($b);
                                $raw_status    = strtolower($b['status']);
                                $status_class  = match($section) {
                                    'Pending'   => 'status-pending',
                                    'Completed' => 'status-completed',
                                    'Cancelled' => 'status-cancelled',
                                    default     => 'status-upcoming'
                                };
                                $is_cancellable = ($section === 'Pending' || $section === 'Upcoming') && canCancel($b);

                                $has_delivery          = !empty($b['delivery_service_type']);
                                $delivery_fee          = $b['delivery_service_fee'];
                                $delivery_fee_is_set   = $has_delivery && $delivery_fee !== null;
                                $show_delivery_badge   = $has_delivery && !$delivery_fee_is_set;

                                $base_total = ($duration_days * (float)$b['daily_rate']) + (float)$b['security_deposit'];
                                if ($has_delivery) {
                                    if ($delivery_fee_is_set) {
                                        $fee_included_in_total = abs((float)$b['total_price'] - ($base_total + (float)$delivery_fee)) < 0.01;
                                    } else {
                                        $fee_included_in_total = false;
                                    }
                                } else {
                                    $fee_included_in_total = abs((float)$b['total_price'] - $base_total) < 0.01;
                                }

                                $can_pay = (
                                    $section === 'Pending' &&
                                    $raw_status === 'approved' &&
                                    $fee_included_in_total
                                );

                                $pay_blocker_reason = '';
                                if ($section === 'Pending' && $raw_status === 'approved' && !$can_pay) {
                                    if ($has_delivery && !$delivery_fee_is_set) {
                                        $pay_blocker_reason = 'Waiting delivery fee';
                                    } elseif (!$fee_included_in_total) {
                                        $pay_blocker_reason = 'Updating total...';
                                    }
                                }
                            ?>
                            <tr>
                                <td>
                                    <?php if (!empty($b['car_image_id'])): ?>
                                        <img class="booking-car-img-thumb"
                                             src="get_car_image.php?car_image_id=<?= (int)$b['car_image_id'] ?>"
                                             alt="Car Image"
                                             onerror="this.src='/assets/images/no-car.png'">
                                    <?php else: ?>
                                        <img class="booking-car-img-thumb" src="/assets/images/no-car.png" alt="No Car Image">
                                    <?php endif; ?>
                                    <?= htmlspecialchars($b['car_brand'].' '.$b['car_model']) ?>
                                    <?php if ($show_delivery_badge): ?>
                                        <span class="badge-delivery" title="Delivery / pickup service selected, fee pending.">Delivery Service</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($b['pickup_datetime']))) ?></td>
                                <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($b['return_datetime']))) ?></td>
                                <td><?= $duration_days ?> day(s)</td>

                                <td class="delivery-col">
                                    <?php if (!$has_delivery): ?>
                                        <span class="delivery-fee-dash">-</span>
                                    <?php elseif ($has_delivery && !$delivery_fee_is_set): ?>
                                        <span class="delivery-fee-pending" title="Admin has not set the delivery fee yet.">Pending</span>
                                    <?php else: ?>
                                        <?php if ((float)$delivery_fee === 0.0): ?>
                                            <span class="delivery-fee-free" title="Delivery / pickup service is free.">Free</span>
                                        <?php else: ?>
                                            <span class="fee-tooltip-wrap" title="Delivery / pickup service fee applied.">
                                                <?= number_format((float)$delivery_fee, 2) ?>
                                                <span class="fee-info-icon">i</span>
                                                <span class="fee-tooltip">
                                                    Delivery service fee has been added.<br>
                                                    Total includes base + deposit + delivery fee.
                                                </span>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <td><?= number_format((float)$b['total_price'], 2) ?></td>
                                <td><span class="booking-status <?= $status_class ?>"><?= ucfirst($raw_status) ?></span></td>
                                <td class="booking-actions">
                                    <a class="action-btn view" href="view_booking.php?booking_id=<?= (int)$b['booking_id'] ?>">View</a>

                                    <?php if ($section === 'Pending'): ?>
                                        <?php if ($raw_status === 'approved'): ?>
                                            <?php if ($can_pay): ?>
                                                <a class="action-btn" style="background:#f8a100;" href="payment.php?booking_id=<?= (int)$b['booking_id'] ?>">Pay</a>
                                            <?php else: ?>
                                                <span style="color:#999;font-size:0.85em;"><?= htmlspecialchars($pay_blocker_reason) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:0.92em;">Awaiting admin approval</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($is_cancellable): ?>
                                        <form action="cancel_booking.php" method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel this booking?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>">
                                            <button type="submit" class="action-btn cancel">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button class="back-btn" onclick="window.location.href='dashboard.php'">Back</button>
    </div>
</div>

<?php include '../includes/footer.php'; ?>