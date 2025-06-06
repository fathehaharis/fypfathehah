<?php
require_once('connect.php'); // adjust as necessary
session_start();

// Get booking info (should be from session or DB)
$booking_id = $_SESSION['booking_id'] ?? null;
if (!$booking_id) {
    die('No booking found.');
}

// Fetch booking details (including car and customer)
$stmt = $conn->prepare(
    "SELECT b.*, c.car_brand, c.car_model, c.daily_rate, cu.full_name 
     FROM booking b
     JOIN car c ON b.car_id = c.car_id
     JOIN customer cu ON b.cust_id = cu.cust_id
     WHERE b.booking_id = ?"
);
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

$delivery_fee = 0;
$delivery_type = $_SESSION['booking_data']['delivery_type'] ?? 'self_pickup'; // or from booking table if stored
if ($delivery_type == 'delivery') {
    $delivery_fee = 10.00;
} elseif ($delivery_type == 'pickup_and_return') {
    $delivery_fee = 30.00;
}

// Calculate total (if not already done on insert, or for display)
$car_total = $booking['daily_rate'] * $booking['booking_duration'];
$total_price = $car_total + $delivery_fee;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Booking Receipt & Payment</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2em;}
        table { border-collapse: collapse; }
        td, th { padding: 8px 16px; }
        th { text-align: left; background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>Booking Receipt</h1>
    <table>
        <tr>
            <th>Customer</th><td><?= htmlspecialchars($booking['full_name']) ?></td>
        </tr>
        <tr>
            <th>Car</th><td><?= htmlspecialchars($booking['car_brand'] . ' ' . $booking['car_model']) ?></td>
        </tr>
        <tr>
            <th>Car Daily Rate</th><td>RM <?= number_format($booking['daily_rate'], 2) ?></td>
        </tr>
        <tr>
            <th>Duration</th><td><?= $booking['booking_duration'] ?> day(s)</td>
        </tr>
        <tr>
            <th>Delivery Type</th><td><?= htmlspecialchars(ucwords(str_replace('_',' ', $delivery_type))) ?></td>
        </tr>
        <tr>
            <th>Delivery Fee</th><td>RM <?= number_format($delivery_fee, 2) ?></td>
        </tr>
        <tr>
            <th>Subtotal</th><td>RM <?= number_format($car_total, 2) ?></td>
        </tr>
        <tr>
            <th style="font-size:1.1em;">Total Amount</th>
            <td style="font-size:1.1em;"><b>RM <?= number_format($total_price, 2) ?></b></td>
        </tr>
    </table>
    <br>
    <form action="process_payment.php" method="POST">
        <input type="hidden" name="booking_id" value="<?= $booking_id ?>">
        <input type="hidden" name="amount" value="<?= $total_price ?>">
        <button type="submit" style="padding:1em 2em; font-size:1em;">Proceed to Payment</button>
    </form>
</body>
</html>