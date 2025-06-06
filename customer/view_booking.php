<?php
session_start();
include '../connect.php';
include '../includes/header.php';

if (!isset($_GET['booking_id']) || !is_numeric($_GET['booking_id'])) {
    echo "<p>Invalid booking ID.</p>";
    include '../includes/footer.php';
    exit;
}

$booking_id = intval($_GET['booking_id']);
$cust_id = $_SESSION['cust_id'] ?? null;

// Fetch booking + car info (ensure belongs to logged-in customer)
$stmt = $conn->prepare("
    SELECT
        b.*,
        c.car_brand,
        c.car_model,
        c.daily_rate,
        c.year,
        c.color,
        c.mileage,
        c.plate_no,
        c.transmission,
        c.seat_capacity
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    WHERE b.booking_id = ? AND b.cust_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $booking_id, $cust_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo "<p>Booking not found or you do not have permission to view it.</p>";
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

// Fetch driver (customer) details
$stmt = $conn->prepare("SELECT * FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $booking['cust_id']);
$stmt->execute();
$driver = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch guarantor (if any)
$stmt = $conn->prepare("SELECT * FROM guarantor WHERE cust_id = ? ORDER BY guarantor_id DESC LIMIT 1");
$stmt->bind_param("i", $booking['cust_id']);
$stmt->execute();
$guarantor = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch all services for this booking
$stmt = $conn->prepare("SELECT service_type, fee FROM service WHERE booking_id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Car rental subtotal
$daily_rate = (float)($booking['daily_rate'] ?? 0);
$duration = (int)($booking['booking_duration'] ?? 0);
$car_total = $daily_rate * $duration;

// Delivery info (from service table)
$delivery_type_display = '-';
$delivery_fee = 0.00;
$total_services_fee = 0.00;
foreach ($services as $s) {
    $total_services_fee += (float)$s['fee'];
    if ($s['service_type'] === 'delivery' || $s['service_type'] === 'pickup_and_return') {
        $delivery_type_display = ucwords(str_replace('_', ' ', $s['service_type']));
        $delivery_fee = (float)$s['fee'];
    }
}
$total_price = $car_total + $total_services_fee;
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background: #eceef4; }
.view-section {
    max-width: 600px;
    margin: 40px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 32px 40px 28px 40px;
}
.view-title {
    font-size: 1.35em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 24px;
}
.view-table { width:100%; border-collapse:collapse; margin-bottom: 30px; }
.view-table th, .view-table td { padding: 8px 12px; }
.view-table th { text-align: left; background: #f0f0f0; width: 180px; }
.view-table td:last-child { text-align: right; }
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
.status-pending { background: #fffbe7; color: #bfa800; }
.status-upcoming { background: #f7faff; color: #2f377d; }
.status-completed { background: #e3fbe6; color: #219150; }
.status-cancelled { background: #fde9e9; color: #d42d2d; }
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
</style>

<div class="view-section">
    <div class="view-title">Booking Details</div>

    <div class="section-label">Car & Booking Details</div>
    <table class="view-table">
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
        <tr><th>Daily Rate</th><td>RM <?= number_format($booking['daily_rate'],2) ?></td></tr>
        <tr><th>Pickup</th><td><?= htmlspecialchars($booking['pickup_datetime']) ?></td></tr>
        <tr><th>Return</th><td><?= htmlspecialchars($booking['return_datetime']) ?></td></tr>
        <tr><th>Duration</th><td><?= htmlspecialchars($booking['booking_duration']) ?> day(s)</td></tr>
        <tr><th>Delivery Type</th><td><?= htmlspecialchars($delivery_type_display) ?></td></tr>
        <tr><th>Delivery Fee</th><td>RM <?= number_format($delivery_fee,2) ?></td></tr>
        <tr><th>Subtotal</th><td>RM <?= number_format($car_total,2) ?></td></tr>
        <tr>
            <th class="total">Total Amount</th>
            <td class="total">RM <?= number_format($total_price,2) ?></td>
        </tr>
        <tr>
            <th>Status</th>
            <td>
                <?php
                    $status = strtolower($booking['status']);
                    $status_class = 'status-upcoming';
                    if ($status == 'pending') $status_class = 'status-pending';
                    else if ($status == 'completed') $status_class = 'status-completed';
                    else if ($status == 'cancelled') $status_class = 'status-cancelled';
                ?>
                <span class="status-label <?= $status_class ?>">
                    <?= ucfirst($status) ?>
                </span>
            </td>
        </tr>
    </table>

    <div class="section-label">Driver (Customer)</div>
    <table class="view-table">
        <tr><th>Name</th><td><?= htmlspecialchars($driver['full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= htmlspecialchars($driver['phone_no']) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($driver['email']) ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($driver['id_no']) ?></td></tr>
        <tr><th>License No</th><td><?= htmlspecialchars($driver['license_no']) ?></td></tr>
        <tr><th>Address</th><td><?= htmlspecialchars($driver['address']) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($driver['age']) ?></td></tr>
    </table>

    <?php if ($guarantor): ?>
        <div class="section-label">Guarantor</div>
        <table class="view-table">
            <tr><th>Name</th><td><?= htmlspecialchars($guarantor['full_name']) ?></td></tr>
            <tr><th>Phone</th><td><?= htmlspecialchars($guarantor['phone_no']) ?></td></tr>
            <tr><th>ID No</th><td><?= htmlspecialchars($guarantor['id_no']) ?></td></tr>
            <tr><th>Relationship</th><td><?= htmlspecialchars($guarantor['relationship']) ?></td></tr>
        </table>
    <?php endif; ?>

    <button class="back-btn" onclick="window.history.back()">Back</button>
</div>
<?php include '../includes/footer.php'; ?>