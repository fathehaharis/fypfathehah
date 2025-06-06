<?php
session_start();
include '../connect.php';
include '../includes/header.php';

// 1. Handle guarantor POST from previous form (booking_guarantor.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guarantor_full_name'])) {
    $_SESSION['guarantor_data'] = [
        'guarantor_full_name'     => $_POST['guarantor_full_name'] ?? '',
        'guarantor_phone_no'      => $_POST['guarantor_phone_no'] ?? '',
        'guarantor_id_no'         => $_POST['guarantor_id_no'] ?? '',
        'guarantor_relationship'  => $_POST['guarantor_relationship'] ?? '',
    ];
    // Handle uploaded images (store in session as temp file paths)
    if (isset($_FILES['guarantor_id_front']) && $_FILES['guarantor_id_front']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_front']['tmp_name'];
        $name = uniqid('guarfront_') . '_' . basename($_FILES['guarantor_id_front']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_front'] = $dest;
    }
    if (isset($_FILES['guarantor_id_back']) && $_FILES['guarantor_id_back']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['guarantor_id_back']['tmp_name'];
        $name = uniqid('guarback_') . '_' . basename($_FILES['guarantor_id_back']['name']);
        $dest = sys_get_temp_dir() . '/' . $name;
        move_uploaded_file($tmpName, $dest);
        $_SESSION['guarantor_data']['guarantor_id_back'] = $dest;
    }
}

// 2. Ensure all session data is present
$booking = $_SESSION['booking_data'] ?? [];
$driver = $_SESSION['driver_data'] ?? [];
$guarantor = $_SESSION['guarantor_data'] ?? [];

if (!$booking || !$driver || !$guarantor) {
    header("Location: book_car.php");
    exit;
}

// 3. Get car details from DB
$car_id = $booking['car_id'];
$stmt = $conn->prepare("SELECT car_brand, car_model, daily_rate FROM car WHERE car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();

// 4. Price calculation
$booking_duration = $booking['booking_duration'] ?? null;
if (!$booking_duration) {
    // If not previously calculated, calculate from pickup and return datetime
    $pickup = new DateTime($booking['pickup_datetime']);
    $return = new DateTime($booking['return_datetime']);
    $diff = $pickup->diff($return);
    $booking_duration = max(1, (int)$diff->format('%a'));
    $_SESSION['booking_data']['booking_duration'] = $booking_duration;
}
$delivery_type = $booking['delivery_type'];
$delivery_fee = 0;
if ($delivery_type === 'delivery') $delivery_fee = 10.00;
elseif ($delivery_type === 'pickup_and_return') $delivery_fee = 30.00;

$car_total = $car['daily_rate'] * $booking_duration;
$total_price = $car_total + $delivery_fee;
$_SESSION['booking_data']['total_price'] = $total_price;
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background: #eceef4; }
.review-section {
    max-width: 600px;
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
        <tr><th>Daily Rate</th><td>RM <?= number_format($car['daily_rate'],2) ?></td></tr>
        <tr><th>Pickup</th><td><?= htmlspecialchars($booking['pickup_datetime']) ?></td></tr>
        <tr><th>Return</th><td><?= htmlspecialchars($booking['return_datetime']) ?></td></tr>
        <tr><th>Duration</th><td><?= $booking_duration ?> day(s)</td></tr>
        <tr><th>Delivery Type</th><td><?= htmlspecialchars(ucwords(str_replace('_',' ', $delivery_type))) ?></td></tr>
        <tr><th>Delivery Fee</th><td>RM <?= number_format($delivery_fee,2) ?></td></tr>
        <tr><th>Subtotal</th><td>RM <?= number_format($car_total,2) ?></td></tr>
        <tr>
            <th class="total">Total Amount</th>
            <td class="total">RM <?= number_format($total_price,2) ?></td>
        </tr>
    </table>
    <div class="section-label">Driver (Customer)</div>
    <table class="review-table">
        <tr><th>Name</th><td><?= htmlspecialchars($driver['driver_full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($driver['driver_phone_no']) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($driver['driver_email']) ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($driver['driver_id_no']) ?></td></tr>
        <tr><th>License No</th><td><?= htmlspecialchars($driver['driver_license_no']) ?></td></tr>
        <tr><th>Address</th><td><?= htmlspecialchars($driver['driver_address']) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($driver['driver_age']) ?></td></tr>
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