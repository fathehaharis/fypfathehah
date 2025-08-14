<?php
session_start();
require '../connect.php';

$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;

$stmt = $conn->prepare("SELECT * FROM payment WHERE payment_id = ? AND booking_id = ?");
$stmt->bind_param("ii", $payment_id, $booking_id);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT b.*, c.car_brand, c.car_model 
    FROM booking b 
    JOIN car c ON b.car_id = c.car_id 
    WHERE b.booking_id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pay || !$booking) {
    echo "<p>Payment or booking not found.</p>";
    exit;
}
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.payment-section {
    max-width: 520px;
    margin: 60px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 36px 42px 34px 42px;
    text-align: center;
}
.payment-title { font-size: 1.28em; font-weight: 700; color: #2f377d; margin-bottom: 22px; }
.payment-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
.payment-table th, .payment-table td { padding: 8px 10px; text-align: left; }
.payment-table th { background: #f8f8f8; }
.payment-table td:last-child { text-align: right; }
.total-row th, .total-row td { font-weight: bold; font-size: 1.1em; }
.note { font-size: .86em; color: #6a7898; margin-top: 6px; }
.error { color: #c33b3b; font-size: .84em; margin-top: 6px; text-align: left; }
.inline { display: inline-block; }
.pay-btn {
    background: #3c4cb8; color: #fff; border: none; padding: 13px 36px;
    border-radius: 7px; font-size: 1.12em; font-weight: 600; cursor: pointer;
    transition: background 0.18s; margin-top: 22px;
}
.pay-btn[disabled] { opacity: .6; cursor: not-allowed; }
.pay-btn:hover { background: #234c96; }
.back-btn {
    width: 180px; margin: 20px auto 0 auto; display: block; background: #c2c7d6; color: #2f377d;
    border: none; padding: 11px 0; border-radius: 7px; font-size: 1.04em; font-weight: 600; cursor: pointer;
    text-align: center; text-decoration: none; transition: background 0.18s, color 0.18s;
}
.back-btn:hover { background: #b4bac9; color: #162040; }
</style>

<div class="payment-section">
    <div class="payment-title">Payment Successful</div>
    <div class="ok" style="margin-bottom:18px;">Your payment has been received!</div>
    <table class="payment-table">
        <tr>
            <th>Payment Ref</th>
            <td><?= htmlspecialchars($pay['payment_id']) ?></td>
        </tr>
        <tr>
            <th>Booking ID</th>
            <td><?= htmlspecialchars($booking['booking_id']) ?></td>
        </tr>
        <tr>
            <th>Car</th>
            <td><?= htmlspecialchars($booking['car_brand'] . ' ' . $booking['car_model']) ?></td>
        </tr>
        <tr>
            <th>Rental Period</th>
            <td>
                <?= date('d M Y, H:i', strtotime($booking['pickup_datetime'])) ?>
                &rarr;
                <?= date('d M Y, H:i', strtotime($booking['return_datetime'])) ?>
            </td>
        </tr>
        <tr>
            <th>Amount</th>
            <td>RM <?php echo number_format($pay['amount'], 2); ?></td>
        </tr>
        <tr>
            <th>Method</th>
            <td><?= htmlspecialchars($pay['payment_method']) ?></td>
        </tr>
        <tr>
            <th>Date</th>
            <td><?= htmlspecialchars($pay['payment_date']) ?></td>
        </tr>
    </table>
    <div class="note">You may print or save this receipt for your records.</div>
<button onclick="window.print()" class="pay-btn">Print Receipt</button>
<a href="payment_receipt_blob.php?payment_id=<?= htmlspecialchars($pay['payment_id']) ?>" target="_blank" class="pay-btn" style="display:inline-block;margin-top:12px;">
    Download/View PDF Receipt
</a>
<a href="bookings.php" class="back-btn">Back to Bookings</a>
</div>