<?php
session_start();
include '../connect.php';
include '../includes/header.php';

// 1. Ensure all session data is present
$booking = $_SESSION['booking_data'] ?? [];
$driver = $_SESSION['driver_data'] ?? [];
$guarantor = $_SESSION['guarantor_data'] ?? [];

if (!$booking || !$driver || !$guarantor) {
    header("Location: book_car.php");
    exit;
}

// 2. Get car details from DB, including hourly_rate
$car_id = $booking['car_id'];
$stmt = $conn->prepare("SELECT car_brand, car_model, daily_rate, hourly_rate FROM car WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 3. Get customer email from DB (if needed)
$email = "-";
if (isset($_SESSION['cust_id'])) {
    $stmt = $conn->prepare("SELECT email FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $_SESSION['cust_id']);
    $stmt->execute();
    $stmt->bind_result($email);
    $stmt->fetch();
    $stmt->close();
}

// 4. Calculate duration and determine mixed daily+hourly
$pickup = new DateTime($booking['pickup_datetime']);
$return = new DateTime($booking['return_datetime']);
$interval = $pickup->diff($return);
$total_hours = ($interval->days * 24) + $interval->h + ($interval->i > 0 ? 1 : 0);

$full_days = floor($total_hours / 24);
$leftover_hours = $total_hours % 24;

$daily_rate = $car['daily_rate'];
$hourly_rate = $car['hourly_rate'];

$subtotal = ($full_days * $daily_rate) + ($leftover_hours * $hourly_rate);

$booking_duration = $full_days;
$booking_leftover_hours = $leftover_hours;
$_SESSION['booking_data']['booking_duration'] = $booking_duration;
$_SESSION['booking_data']['booking_leftover_hours'] = $booking_leftover_hours;

// 5. Delivery fee and total
$delivery_type = $booking['delivery_type'];
$delivery_fee = 0;
if ($delivery_type === 'delivery') $delivery_fee = 10.00;
elseif ($delivery_type === 'pickup_and_return') $delivery_fee = 30.00;

$total_price = $subtotal + $delivery_fee;
$_SESSION['booking_data']['total_price'] = $total_price;

// 6. Delivery location/notes (from booking_data['notes'])
$delivery_location = '';
if (
    ($delivery_type === 'delivery' || $delivery_type === 'pickup_and_return') 
    && !empty($booking['notes'])
) {
    $delivery_location = $booking['notes'];
}
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background: #eceef4; }
.review-section {
    max-width: 680px;
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
.btn-row {
    margin-top: 28px;
    text-align: right;
}
.next-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    margin-left: 8px;
}
.next-btn:hover {background: #234c96;}
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
}
.back-btn:hover {background: #bbb;}
</style>

<div class="review-section">
    <div class="review-title">Review & Confirm Your Booking</div>
    <div class="section-label">Car & Booking Details</div>
    <table class="review-table">
        <tr><th>Car</th><td><?= htmlspecialchars($car['car_brand'].' '.$car['car_model']) ?></td></tr>
        <tr>
            <th>Rental Type</th>
            <td>
                <?php
                if ($full_days > 0 && $leftover_hours > 0) echo "Daily + Hourly";
                elseif ($full_days > 0) echo "Daily";
                else echo "Hourly";
                ?>
            </td>
        </tr>
        <?php if ($full_days > 0): ?>
        <tr><th>Daily Rate</th><td>RM <?= number_format($daily_rate,2) ?></td></tr>
        <tr><th>Daily Count</th><td><?= $full_days ?> daily(s)</td></tr>
        <?php endif; ?>
        <?php if ($leftover_hours > 0): ?>
        <tr><th>Hourly Rate</th><td>RM <?= number_format($hourly_rate,2) ?></td></tr>
        <tr><th>Hourly Count</th><td><?= $leftover_hours ?> hour(s)</td></tr>
        <?php endif; ?>
        <tr><th>Pickup</th><td><?= htmlspecialchars($booking['pickup_datetime']) ?></td></tr>
        <tr><th>Return</th><td><?= htmlspecialchars($booking['return_datetime']) ?></td></tr>
        <tr><th>Delivery Type</th><td><?= htmlspecialchars(ucwords(str_replace('_',' ', $delivery_type))) ?></td></tr>
        <tr><th>Delivery Fee</th><td>RM <?= number_format($delivery_fee,2) ?></td></tr>
        <?php if ($delivery_location): ?>
        <tr><th>Delivery Location</th><td><?= htmlspecialchars($delivery_location) ?></td></tr>
        <?php endif; ?>
        <tr><th>Subtotal</th><td>RM <?= number_format($subtotal,2) ?></td></tr>
        <tr>
            <th class="total">Total Amount</th>
            <td class="total">RM <?= number_format($total_price,2) ?></td>
        </tr>
    </table>
    <div class="section-label">Driver (Customer)</div>
    <table class="review-table">
        <tr><th>Name</th><td><?= htmlspecialchars($driver['full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($driver['phone_no']) ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($driver['id_no']) ?></td></tr>
        <tr><th>License No</th><td><?= htmlspecialchars($driver['license_no']) ?></td></tr>
        <tr><th>Address</th><td><?= htmlspecialchars($driver['address']) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($driver['age']) ?></td></tr>
    </table>
    <div class="section-label">Guarantor</div>
    <table class="review-table">
        <tr><th>Name</th><td><?= htmlspecialchars($guarantor['guarantor_full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($guarantor['guarantor_phone_no']) ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($guarantor['guarantor_id_no']) ?></td></tr>
        <tr><th>Relationship</th><td><?= htmlspecialchars($guarantor['guarantor_relationship']) ?></td></tr>
    </table>
    <div class="btn-row">
        <form action="booking_guarantor.php" method="get" style="display:inline;">
            <button type="submit" class="back-btn">Back</button>
        </form>
        <form action="booking_agreement.php" method="post" style="display:inline;">
            <button type="submit" class="next-btn">Next</button>
        </form>
    </div>
</div>
<?php include '../includes/footer.php'; ?>