<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '../connect.php';
include '../includes/header.php';

// Get booking_id from POST or GET
$booking_id = null;
if (isset($_POST['booking_id']) && is_numeric($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);
} elseif (isset($_GET['booking_id']) && is_numeric($_GET['booking_id'])) {
    $booking_id = intval($_GET['booking_id']);
} else {
    echo "<p>Invalid booking ID.</p>";
    include '../includes/footer.php';
    exit;
}

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}
$cust_id = $_SESSION['cust_id'];

// Fetch booking info and ensure it belongs to customer and not already paid/cancelled
$stmt = $conn->prepare("
    SELECT b.*, c.car_brand, c.car_model
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    WHERE b.booking_id = ? AND b.cust_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $booking_id, $cust_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo "<p>Booking not found or you do not have permission to view it.</p>";
    include '../includes/footer.php';
    exit;
}

if (in_array($booking['status'], ['completed', 'cancelled'])) {
    echo "<p>This booking is already completed or cancelled.</p>";
    include '../includes/footer.php';
    exit;
}

$stmt = $conn->prepare("SELECT * FROM payment WHERE booking_id = ? ORDER BY payment_id DESC LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$payment_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$show_gateway = false;
$gateway_url = "";
$chosen_method = "";

// If form submitted (simulate payment processing)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_now'])) {
    // Calculate total payment amount
    $daily_rate = (float)($booking['daily_rate'] ?? 0);
    $hourly_rate = (float)($booking['hourly_rate'] ?? 0);
    $day_count = (int)($booking['day_count'] ?? 0);
    $hour_count = (int)($booking['hour_count'] ?? 0);
    $subtotal = ($daily_rate * $day_count) + ($hourly_rate * $hour_count);

    // Fetch delivery/service fee
    $stmt = $conn->prepare("SELECT service_type, fee FROM service WHERE booking_id = ?");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $delivery_fee = 0.00;
    foreach ($services as $s) {
        if ($s['service_type'] === 'delivery' || $s['service_type'] === 'pickup_and_return') {
            $delivery_fee += (float)$s['fee'];
        }
    }

    // Get security deposit from booking (defaults to 100 if not set)
    $security_deposit = isset($booking['security_deposit']) ? (float)$booking['security_deposit'] : 100.00;

    $total_amount = $subtotal + $delivery_fee + $security_deposit;

    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'Manual';
    $payment_status = 'pending'; // pending for gateway, paid for manual/counter/card
    $payment_date = date('Y-m-d');

    // Simulated gateway logic
    if ($payment_method === "Online Banking") {
        $show_gateway = true;
        $chosen_method = "Online Banking";
        $gateway_url = "https://www.maybank2u.com.my/"; // Example: Replace with real gateway
    } elseif ($payment_method === "E-Wallet") {
        $show_gateway = true;
        $chosen_method = "E-Wallet";
        $gateway_url = "https://www.touchngo.com.my/"; // Example: Replace with real gateway
    } else {
        // Manual/Counter or Credit Card (simulate instant payment confirmation)
        $payment_status = "paid";
        $stmt = $conn->prepare("INSERT INTO payment (booking_id, payment_date, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isdss", $booking_id, $payment_date, $total_amount, $payment_method, $payment_status);
        $stmt->execute();
        $stmt->close();

        // Update booking status to 'confirmed'
        $stmt = $conn->prepare("UPDATE booking SET status='confirmed' WHERE booking_id=? AND cust_id=?");
        $stmt->bind_param("ii", $booking_id, $cust_id);
        $stmt->execute();
        $stmt->close();

        header("Location: bookings.php?payment=success");
        exit;
    }
}

// Calculate breakdown
$daily_rate = (float)($booking['daily_rate'] ?? 0);
$hourly_rate = (float)($booking['hourly_rate'] ?? 0);
$day_count = (int)($booking['day_count'] ?? 0);
$hour_count = (int)($booking['hour_count'] ?? 0);
$subtotal = ($daily_rate * $day_count) + ($hourly_rate * $hour_count);

$stmt = $conn->prepare("SELECT service_type, fee FROM service WHERE booking_id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$delivery_fee = 0.00;
foreach ($services as $s) {
    if ($s['service_type'] === 'delivery' || $s['service_type'] === 'pickup_and_return') {
        $delivery_fee += (float)$s['fee'];
    }
}

// Get security deposit from booking (defaults to 100 if not set)
$security_deposit = isset($booking['security_deposit']) ? (float)$booking['security_deposit'] : 100.00;

$total_amount = $subtotal + $delivery_fee + $security_deposit;
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.payment-section {
    max-width: 480px;
    margin: 60px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 36px 42px 34px 42px;
    text-align: center;
}
.payment-title {
    font-size: 1.28em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 22px;
}
.payment-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 24px;
}
.payment-table th, .payment-table td {
    padding: 8px 10px;
    text-align: left;
}
.payment-table th { background: #f8f8f8; }
.payment-table td:last-child { text-align: right; }
.total-row th, .total-row td { font-weight: bold; font-size: 1.1em; }
.pay-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 13px 36px;
    border-radius: 7px;
    font-size: 1.12em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    margin-top: 22px;
}
.pay-btn:hover { background: #234c96; }
.back-btn {
    width: 120px;
    margin: 20px auto 0 auto;
    display: block;
    background: #c2c7d6;
    color: #2f377d;
    border: none;
    padding: 11px 0;
    border-radius: 7px;
    font-size: 1.04em;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    transition: background 0.18s, color 0.18s;
}
.back-btn:hover {
    background: #b4bac9;
    color: #162040;
}
.payment-info {
    margin-top: 10px;
    color: #219150;
    font-weight: 600;
}
.payment-method-select {
    margin: 10px 0 20px 0;
    width: 100%;
    padding: 11px 8px;
    border-radius: 6px;
    border: 1px solid #d3d3e7;
    font-size: 1.08em;
}
.gateway-box {
    padding: 30px 0;
}
.gateway-link {
    display: inline-block;
    background: #f8a100;
    color: #fff;
    font-weight: 600;
    border-radius: 8px;
    padding: 16px 28px;
    font-size: 1.14em;
    text-decoration: none;
    margin-bottom: 18px;
    margin-top: 18px;
    transition: background 0.18s;
}
.gateway-link:hover { background: #e07a00; }
</style>

<div class="payment-section">
    <div class="payment-title">Booking Payment</div>
    <table class="payment-table">
        <tr>
            <th>Car</th>
            <td><?= htmlspecialchars($booking['car_brand'] . ' ' . $booking['car_model']) ?></td>
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
            <tr>
                <th>Daily Rate</th>
                <td>RM <?= number_format($daily_rate,2) ?> x <?= $day_count ?> day(s)</td>
            </tr>
        <?php endif; ?>
        <?php if ($hour_count > 0): ?>
            <tr>
                <th>Hourly Rate</th>
                <td>RM <?= number_format($hourly_rate,2) ?> x <?= $hour_count ?> hour(s)</td>
            </tr>
        <?php endif; ?>
        <tr>
            <th>Subtotal</th>
            <td>RM <?= number_format($subtotal,2) ?></td>
        </tr>
        <tr>
            <th>Delivery Fee</th>
            <td>RM <?= number_format($delivery_fee,2) ?></td>
        </tr>
        <tr>
            <th>Security Deposit</th>
            <td>RM <?= number_format($security_deposit,2) ?></td>
        </tr>
        <tr class="total-row">
            <th>Total Amount</th>
            <td>RM <?= number_format($total_amount,2) ?></td>
        </tr>
    </table>
    <?php if ($show_gateway && $gateway_url): ?>
        <div class="gateway-box">
            <p>
                <strong>You selected: <?= htmlspecialchars($chosen_method) ?></strong>
            </p>
            <p>
                <a class="gateway-link" href="<?= htmlspecialchars($gateway_url) ?>" target="_blank">
                    Proceed to <?= htmlspecialchars($chosen_method) ?> Gateway
                </a>
            </p>
            <p style="margin-top:16px;color:#888;">
                After completing your payment, please contact our admin for confirmation.<br>
                Your booking will be confirmed once payment is verified.
            </p>
            <a class="back-btn" href="bookings.php">Back to Bookings</a>
        </div>
    <?php elseif ($payment_row && $payment_row['payment_status'] == 'paid'): ?>
        <div class="payment-info">
            Payment received on <?= htmlspecialchars($payment_row['payment_date']) ?>.<br>
            Amount: RM <?= number_format($payment_row['amount'], 2) ?><br>
            Method: <?= htmlspecialchars($payment_row['payment_method']) ?>
        </div>
        <a class="back-btn" href="bookings.php">Back to Bookings</a>
    <?php else: ?>
        <form action="payment.php" method="post">
            <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id) ?>">
            <label for="payment_method"><strong>Choose Payment Method:</strong></label>
            <select name="payment_method" id="payment_method" class="payment-method-select" required>
                <option value="Manual">Manual / Counter</option>
                <option value="Online Banking">Online Banking</option>
                <option value="Credit/Debit Card">Credit/Debit Card</option>
                <option value="E-Wallet">E-Wallet</option>
            </select>
            <button class="pay-btn" type="submit" name="pay_now">Pay Now</button>
        </form>
        <button class="back-btn" onclick="window.history.back()">Back</button>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>