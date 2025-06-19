<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
include '../connect.php';

// Set Malaysia timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<p>Invalid booking ID.</p>";
    include '../includes/footer.php';
    exit;
}

$booking_id = intval($_GET['id']);

// Handle Approve/Reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'])) {
    $action = $_POST['booking_action'];
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE booking SET status = 'approved' WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_message'] = "Booking approved.";
        header("Location: booking_details.php?id=" . urlencode($booking_id));
        exit;
    } elseif ($action === 'reject' && !empty($_POST['rejection_reason'])) {
        $rejection_reason = trim($_POST['rejection_reason']);
        $stmt = $conn->prepare("UPDATE booking SET status = 'rejected', rejection_reason = ? WHERE booking_id = ?");
        $stmt->bind_param("si", $rejection_reason, $booking_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['flash_message'] = "Booking rejected.";
        header("Location: booking_details.php?id=" . urlencode($booking_id));
        exit;
    }
}

// Fetch booking + car + customer info
$stmt = $conn->prepare("
    SELECT
        b.*,
        c.car_brand,
        c.car_model,
        c.daily_rate AS car_daily_rate,
        c.hourly_rate AS car_hourly_rate,
        c.year,
        c.color,
        c.mileage,
        c.plate_no,
        c.transmission,
        c.seat_capacity,
        cust.full_name as customer_name,
        cust.phone_no as customer_phone,
        cust.email as customer_email
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    LEFT JOIN customer cust ON b.cust_id = cust.cust_id
    WHERE b.booking_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo "<p>Booking not found.</p>";
    include '../includes/footer.php';
    exit;
}

// Fetch car image
$stmt = $conn->prepare("SELECT image_path FROM car_image WHERE car_id = ? ORDER BY car_image_id ASC LIMIT 1");
$stmt->bind_param("i", $booking['car_id']);
$stmt->execute();
$stmt->bind_result($car_image);
$stmt->fetch();
$stmt->close();

// Fetch driver details from driver table (using booking's driver_id)
$driver = null;
if (!empty($booking['driver_id'])) {
    $stmt = $conn->prepare("SELECT * FROM driver WHERE driver_id = ?");
    $stmt->bind_param("i", $booking['driver_id']);
    $stmt->execute();
    $driver = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Fetch guarantor (if any) by driver_id
$guarantor = null;
if ($driver) {
    $stmt = $conn->prepare("SELECT * FROM guarantor WHERE driver_id = ? ORDER BY guarantor_id DESC LIMIT 1");
    $stmt->bind_param("i", $booking['driver_id']);
    $stmt->execute();
    $guarantor = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Fetch all services for this booking, including notes (delivery location)
$stmt = $conn->prepare("SELECT service_type, fee, notes FROM service WHERE booking_id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate rental breakdown
$daily_rate = (float)($booking['daily_rate'] ?? 0);
$hourly_rate = (float)($booking['hourly_rate'] ?? 0);
$day_count = (int)($booking['day_count'] ?? 0);
$hour_count = (int)($booking['hour_count'] ?? 0);
$subtotal = ($daily_rate * $day_count) + ($hourly_rate * $hour_count);

// Delivery info (from service table)
$delivery_type_display = '-';
$delivery_fee = 0.00;
$total_services_fee = 0.00;
$delivery_location = '';
foreach ($services as $s) {
    $total_services_fee += (float)$s['fee'];
    if ($s['service_type'] === 'delivery' || $s['service_type'] === 'pickup_and_return') {
        $delivery_type_display = ucwords(str_replace('_', ' ', $s['service_type']));
        $delivery_fee = (float)$s['fee'];
        if (!empty($s['notes'])) {
            $delivery_location = $s['notes'];
        }
    }
}

// Get security deposit from booking, fallback to 100 if not set
$security_deposit = isset($booking['security_deposit']) ? (float)$booking['security_deposit'] : 100.00;

$total_price = $subtotal + $total_services_fee + $security_deposit;

// --------- Get agreement_id for this booking --------- //
$stmt = $conn->prepare("SELECT agreement_id FROM agreement_form WHERE booking_id = ? LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$stmt->bind_result($agreement_id);
$stmt->fetch();
$stmt->close();

$agreement_download_link = "";
if ($agreement_id) {
    $agreement_download_link = "download_agreement.php?id=" . urlencode($agreement_id);
}

// Get all booking images (car condition, etc)
$stmt = $conn->prepare("SELECT image_path, image_type, capture_type, uploaded_at, remarks FROM booking_image WHERE booking_id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$img_result = $stmt->get_result();
$booking_imgs = $img_result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- E-INSPECTION STATUS (from booking_image table, by capture_type) ---- //
$has_return_img = false;
foreach ($booking_imgs as $img) {
    if (isset($img['capture_type'])) {
        if (strtolower($img['capture_type']) == 'return') $has_return_img = true;
    }
}
$inspection_return = (
    !empty($booking['return_mileage']) &&
    !empty($booking['return_fuel_percent']) &&
    !empty($booking['return_datetime']) &&
    $has_return_img
);
$pickup_filled = (
    !empty($booking['pickup_mileage']) &&
    !empty($booking['pickup_fuel_percent']) &&
    !empty($booking['pickup_datetime'])
);

include 'admin_header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background: #eceef4; }
.review-section {
    max-width: 800px;
    margin: 40px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 32px 40px 28px 40px;
}
.review-title {
    font-size: 1.35em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 24px;
}
.review-table { width:100%; border-collapse:collapse; margin-bottom: 30px; }
.review-table th, .review-table td { padding: 8px 12px; }
.review-table th { text-align: left; background: #f0f0f0; width: 180px; }
.review-table td:last-child { text-align: right; }
.total { font-size:1.1em; font-weight: bold; color: #203090; }
.section-label { margin: 18px 0 8px 0; font-weight: 600; color: #444; }
.car-img-thumb {
    width: 150px;
    height: 90px;
    object-fit: cover;
    border-radius: 7px;
    border: 1px solid #dadada;
    background: #f2f3f8;
    margin-bottom: 10px;
    display: block;
}
.status-label {
    padding: 4px 13px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.98em;
    display: inline-block;
}
.status-pending, .status-waiting_verification { background: #fffbe7; color: #bfa800; }
.status-confirmed, .status-upcoming { background: #f7faff; color: #2f377d; }
.status-completed { background: #e3fbe6; color: #219150; }
.status-cancelled, .status-rejected { background: #fde9e9; color: #d42d2d; }
.back-btn {
    background: #ccc;
    color: #222;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    margin-top: 20px;
    display: block;
    width: 180px;
    margin-left: auto;
    margin-right: auto;
}
.back-btn:hover {background: #bbb;}
.agreement-link {
    display: inline-block;
    margin: 12px 0 20px 0;
    padding: 10px 24px;
    background: #3c4cb8;
    color: #fff;
    border-radius: 7px;
    font-weight: 600;
    text-decoration: none;
    font-size: 1.05em;
    box-shadow: 0 2px 6px rgba(60,60,60,0.05);
    transition: background 0.17s;
}
.agreement-link:hover {
    background: #234c96;
}
.einspection-label {
    color: #8a96ad;
    font-weight: 700;
    font-size: 1.11em;
    margin-top: 26px;
    margin-bottom: 8px;
    letter-spacing: 1px;
}
.einspection-row {
    display: flex;
    gap: 14px;
    margin-bottom: 12px;
}
.einspection-btn {
    padding: 9px 26px 9px 18px;
    background: #f4f6fa;
    border-radius: 11px;
    border: none;
    font-size: 1.08em;
    color: #21243d;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: none;
    outline: none;
    cursor: pointer;
    transition: background 0.15s;
    text-decoration: none;
}
.einspection-btn svg {
    width: 1.24em;
    height: 1.24em;
    display: inline-block;
    vertical-align: middle;
}
.einspection-btn.done {
    color: #0a9151;
    font-weight: 600;
    background: #f4f6fa;
}
.einspection-btn:not(.done) {
    color: #222;
    font-weight: 500;
}
.einspection-btn:hover { background: #e9eef7; }
.action-form-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 18px;
    margin-bottom: 10px;
    background: #f8fafc;
    border-radius: 10px;
    padding: 14px 20px;
    box-shadow: 0 2.5px 10px #e5e8f3a5;
    width: fit-content;
}
.action-btn {
    padding: 10px 30px;
    border-radius: 8px;
    border: none;
    color: #fff;
    font-size: 1.09em;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.18s, box-shadow 0.14s;
    margin: 0;
    box-shadow: 0 2px 7px #2bc96018;
    outline: none;
    border: 2px solid transparent;
    display: inline-block;
}
.action-btn.approve {
    background: #23c960;
}
.action-btn.approve:focus,
.action-btn.approve:hover {
    background: #1ea753;
    border-color: #19a34c;
}
.action-btn.reject {
    background: #e54848;
}
.action-btn.reject:focus,
.action-btn.reject:hover {
    background: #b32d2d;
    border-color: #b32d2d;
}
.rejection-reason-input {
    padding: 10px 17px;
    border: 1.5px solid #e1e6f3;
    font-size: 1.03em;
    min-width: 230px;
    outline: none;
    background: #fff;
    color: #31436e;
    font-weight: 500;
    border-radius: 7px;
    transition: border 0.15s;
    margin-left: 12px;
    margin-right: 0;
    display: none;
}
.rejection-reason-input:focus {
    border-color: #4156c7;
    background: #f2f7ff;
}
@media (max-width: 600px) {
    .action-form-row {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        padding: 10px 7px;
        width: 100%;
    }
    .action-btn,
    .rejection-reason-input {
        border-radius: 8px !important;
        border: 1.5px solid #e1e6f3 !important;
        box-shadow: none !important;
        margin: 0 !important;
        width: 100%;
    }
}
</style>

<div class="review-section">
    <div class="review-title">Booking Details (Admin View)</div>
    <?php if (!empty($_SESSION['flash_message'])): ?>
        <div style="background:#e6fcf3;color:#218c6d;padding:10px 20px;border-radius:7px;margin-bottom:10px;">
            <?= htmlspecialchars($_SESSION['flash_message']); unset($_SESSION['flash_message']); ?>
        </div>
    <?php endif; ?>

    <?php $status = strtolower($booking['status']); ?>
    <?php if ($status == 'waiting_verification'): ?>
        <form method="post" action="booking_details.php?id=<?= $booking_id ?>" class="action-form-row" id="approvalForm" autocomplete="off" onsubmit="return validateRejectReason();">
            <button type="submit" name="booking_action" value="approve" class="action-btn approve">Approve</button>
            <button type="button" id="rejectBtn" class="action-btn reject" style="margin-left:12px;">Reject</button>
            <input type="text" name="rejection_reason" class="rejection-reason-input" id="rejectionReason" placeholder="Enter rejection reason..." autocomplete="off">
            <button type="submit" name="booking_action" value="reject" id="confirmRejectBtn" class="action-btn reject" style="display:none;margin-left:12px;">Confirm Reject</button>
        </form>
        <script>
        document.getElementById('rejectBtn').addEventListener('click', function() {
            document.getElementById('rejectionReason').style.display = 'inline-block';
            document.getElementById('confirmRejectBtn').style.display = 'inline-block';
            document.getElementById('rejectionReason').focus();
            this.style.display = 'none';
        });
        function validateRejectReason() {
            var reasonInput = document.getElementById('rejectionReason');
            var confirmBtn = document.getElementById('confirmRejectBtn');
            if (confirmBtn.style.display === 'inline-block' && confirmBtn === document.activeElement) {
                if (!reasonInput.value.trim()) {
                    reasonInput.style.borderColor = "#e54848";
                    reasonInput.focus();
                    return false;
                }
            }
            return true;
        }
        </script>
        <hr>
    <?php elseif ($status != 'cancelled' && $status != 'rejected'): ?>
        <!-- E-INSPECTION section: only for statuses that are NOT cancelled or rejected -->
        <div class="einspection-label">E-INSPECTION</div>
        <div class="einspection-row">
            <a class="einspection-btn<?= $pickup_filled ? ' done' : '' ?>" href="inspection_add.php?booking_id=<?= $booking_id ?>&type=pickup">
                <?php if ($pickup_filled): ?>
                    <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="10" fill="#17C964"/><path d="M14.3 8.3a1 1 0 1 0-1.6-1.2l-3 4-1.4-1.3A1 1 0 1 0 6.3 11.2l2.1 1.8a1 1 0 0 0 1.4-.2l3.5-4.5Z" fill="#fff"/></svg>
                <?php else: ?>
                    <svg width="16" height="16" fill="none"><rect width="16" height="16" rx="8" fill="#b5bee5"/><path d="M8 4v8M4 8h8" stroke="#222" stroke-width="1.5" stroke-linecap="round"/></svg>
                <?php endif; ?>
                Pickup
            </a>
            <a class="einspection-btn<?= $inspection_return ? ' done' : '' ?>" href="inspection_add.php?booking_id=<?= $booking_id ?>&type=return">
                <?php if ($inspection_return): ?>
                    <svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="10" fill="#17C964"/><path d="M14.3 8.3a1 1 0 1 0-1.6-1.2l-3 4-1.4-1.3A1 1 0 1 0 6.3 11.2l2.1 1.8a1 1 0 0 0 1.4-.2l3.5-4.5Z" fill="#fff"/></svg>
                <?php else: ?>
                    <svg width="16" height="16" fill="none"><rect width="16" height="16" rx="8" fill="#b5bee5"/><path d="M8 4v8M4 8h8" stroke="#222" stroke-width="1.5" stroke-linecap="round"/></svg>
                <?php endif; ?>
                Return
            </a>
        </div>
        <hr>
    <?php endif; ?>

    <!-- Agreement Form Download Link -->
    <?php if ($agreement_download_link): ?>
    <div style="margin-bottom:20px;text-align:right;">
        <a href="<?= htmlspecialchars($agreement_download_link) ?>" target="_blank" class="agreement-link">
            Download Agreement Form
        </a>
    </div>
    <?php endif; ?>

    <div class="section-label">Car & Booking Details</div>
    <table class="review-table">
        <tr>
            <th>Car</th>
            <td>
                <?php if (!empty($car_image)): ?>
                    <img class="car-img-thumb" src="data:image/jpeg;base64,<?= base64_encode($car_image) ?>" alt="Car">
                <?php else: ?>
                    <img class="car-img-thumb" src="/assets/images/no-car.png" alt="No Car Image">
                <?php endif; ?>
                <?= htmlspecialchars($booking['car_brand'].' '.$booking['car_model']) ?>
            </td>
        </tr>
        <tr>
            <th>Plate No</th>
            <td><?= htmlspecialchars($booking['plate_no']) ?></td>
        </tr>
        <tr>
            <th>Rental Type</th>
            <td>
                <?php
                if ($day_count > 0 && $hour_count > 0) echo "Daily + Hourly";
                elseif ($day_count > 0) echo "Daily";
                else echo "Hourly";
                ?>
            </td>
        </tr>
        <?php if ($day_count > 0): ?>
        <tr><th>Daily Rate</th><td>RM <?= number_format($daily_rate,2) ?></td></tr>
        <tr><th>Daily Count</th><td><?= $day_count ?> day(s)</td></tr>
        <?php endif; ?>
        <?php if ($hour_count > 0): ?>
        <tr><th>Hourly Rate</th><td>RM <?= number_format($hourly_rate,2) ?></td></tr>
        <tr><th>Hourly Count</th><td><?= $hour_count ?> hour(s)</td></tr>
        <?php endif; ?>
        <tr><th>Pickup</th><td><?= htmlspecialchars($booking['pickup_datetime']) ?></td></tr>
        <tr><th>Return</th><td><?= htmlspecialchars($booking['return_datetime']) ?></td></tr>
        <tr><th>Delivery Type</th><td><?= htmlspecialchars($delivery_type_display) ?></td></tr>
        <tr><th>Delivery Fee</th><td>RM <?= number_format($delivery_fee,2) ?></td></tr>
        <?php if ($delivery_location): ?>
        <tr><th>Delivery Location</th><td><?= htmlspecialchars($delivery_location) ?></td></tr>
        <?php endif; ?>
        <tr><th>Subtotal</th><td>RM <?= number_format($subtotal,2) ?></td></tr>
        <tr>
            <th>Security Deposit</th>
            <td>RM <?= number_format($security_deposit,2) ?></td>
        </tr>
        <tr>
            <th class="total">Total Amount</th>
            <td class="total">RM <?= number_format($total_price,2) ?></td>
        </tr>
        <tr>
            <th>Status</th>
            <td>
                <?php
                    $status_class = 'status-upcoming';
                    if ($status == 'pending') $status_class = 'status-pending';
                    else if ($status == 'confirmed') $status_class = 'status-confirmed';
                    else if ($status == 'completed') $status_class = 'status-completed';
                    else if ($status == 'cancelled') $status_class = 'status-cancelled';
                    else if ($status == 'waiting_verification') $status_class = 'status-waiting_verification';
                    else if ($status == 'rejected') $status_class = 'status-rejected';
                    else if ($status == 'approved') $status_class = 'status-pending';
                ?>
                <span class="status-label <?= $status_class ?>">
                    <?= ucwords(str_replace('_', ' ', $status)) ?>
                </span>
            </td>
        </tr>
        <?php if ($status == 'rejected' && !empty($booking['rejection_reason'])): ?>
        <tr>
            <th>Rejection Reason</th>
            <td style="color:#b82f2f;"><?= htmlspecialchars($booking['rejection_reason']) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <div class="section-label">Customer</div>
    <table class="review-table">
        <tr><th>Name</th><td><?= htmlspecialchars($booking['customer_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($booking['customer_phone']) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($booking['customer_email']) ?></td></tr>
    </table>

    <?php if ($driver): ?>
    <div class="section-label">Driver</div>
    <table class="review-table">
        <tr><th>Name</th><td><?= htmlspecialchars($driver['full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($driver['phone_no']) ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($driver['id_no']) ?></td></tr>
        <tr><th>License No</th><td><?= htmlspecialchars($driver['license_no']) ?></td></tr>
        <tr><th>Address</th><td><?= htmlspecialchars($driver['address']) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($driver['age']) ?></td></tr>
    </table>
    <?php endif; ?>

    <?php if ($guarantor): ?>
    <div class="section-label">Guarantor</div>
    <table class="review-table">
        <tr><th>Name</th><td><?= htmlspecialchars($guarantor['full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($guarantor['phone_no']) ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($guarantor['id_no']) ?></td></tr>
        <tr><th>Relationship</th><td><?= htmlspecialchars($guarantor['relationship']) ?></td></tr>
    </table>
    <?php endif; ?>

    <div class="btn-row">
        <button class="back-btn" onclick="window.history.back()">Back</button>
    </div>
</div>
<?php include '../includes/footer.php'; ?>