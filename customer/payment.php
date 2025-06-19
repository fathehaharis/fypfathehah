<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include '../connect.php';
require '../vendor/autoload.php'; // PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    $payment_status = 'paid'; // Mark as paid for all methods
    $payment_date = date('Y-m-d');

    // Extra fields
    $bank_choice = isset($_POST['online_bank']) ? $_POST['online_bank'] : '';
    $card_number = isset($_POST['card_number']) ? $_POST['card_number'] : '';
    $card_expiry = isset($_POST['card_expiry']) ? $_POST['card_expiry'] : '';
    $card_cvc = isset($_POST['card_cvc']) ? $_POST['card_cvc'] : '';

    // For display in payment_method column
    if ($payment_method === 'Online Banking' && $bank_choice) {
        $payment_method .= ' - ' . $bank_choice;
    }
    if ($payment_method === 'Credit/Debit Card' && $card_number) {
        $payment_method .= ' - ' . substr($card_number, -4);
    }

    // All methods are treated as instant payment confirmation
    $stmt = $conn->prepare("INSERT INTO payment (booking_id, payment_date, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isdss", $booking_id, $payment_date, $total_amount, $payment_method, $payment_status);
    $stmt->execute();
    $stmt->close();

    // Update booking status to 'confirmed'
    $stmt = $conn->prepare("UPDATE booking SET status='confirmed' WHERE booking_id=? AND cust_id=?");
    $stmt->bind_param("ii", $booking_id, $cust_id);
    $stmt->execute();
    $stmt->close();

    // ----------- Send Payment Confirmation Email -----------
    $stmt = $conn->prepare("SELECT c.username, c.email, b.pickup_datetime, b.return_datetime, b.booking_id, b.total_price, car.car_model
        FROM booking b
        JOIN customer c ON b.cust_id = c.cust_id
        JOIN car ON b.car_id = car.car_id
        WHERE b.booking_id = ? AND b.cust_id = ?");
    $stmt->bind_param("ii", $booking_id, $cust_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($data) {
        $customer_name = $data['username'];
        $customer_email = $data['email'];
        $pickup_datetime = $data['pickup_datetime'];
        $return_datetime = $data['return_datetime'];
        $car_model = $data['car_model'];
        $booking_id = $data['booking_id'];
        $total_price = $data['total_price'];

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'fathehaharis69@gmail.com'; // Your SMTP username
            $mail->Password   = 'cuel ijeu lzqv vsgv';   // Your SMTP app password
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;

            $mail->setFrom('no-reply@timelesscarrental.com', 'TimeLess Car Rental');
            $mail->addAddress($customer_email, $customer_name);

            $mail->isHTML(true);
            $mail->Subject = "Payment Received - TimeLess Car Rental";
            $mail->Body    = "
                <h2>Payment Received</h2>
                <p>Dear <strong>$customer_name</strong>,</p>
                <p>Your payment for the booking <strong>#$booking_id</strong> has been received and confirmed!</p>
                <table style='border-collapse:collapse;'>
                    <tr><td style='padding:4px 8px;font-weight:bold;'>Car Model</td><td style='padding:4px 8px;'>$car_model</td></tr>
                    <tr><td style='padding:4px 8px;font-weight:bold;'>Pickup Date &amp; Time</td><td style='padding:4px 8px;'>$pickup_datetime</td></tr>
                    <tr><td style='padding:4px 8px;font-weight:bold;'>Return Date &amp; Time</td><td style='padding:4px 8px;'>$return_datetime</td></tr>
                    <tr><td style='padding:4px 8px;font-weight:bold;'>Amount Paid</td><td style='padding:4px 8px;'>RM ".number_format($total_price,2)."</td></tr>
                </table>
                <p>Thank you for choosing TimeLess Car Rental. We look forward to serving you!</p>
                <br>
                <p>Best regards,<br>TimeLess Car Rental Team</p>
            ";
            $mail->AltBody = "Dear $customer_name,\n\nYour payment for booking #$booking_id has been received and confirmed!\n\n"
                . "Car Model: $car_model\n"
                . "Pickup Date & Time: $pickup_datetime\n"
                . "Return Date & Time: $return_datetime\n"
                . "Amount Paid: RM ".number_format($total_price,2)."\n\n"
                . "Thank you for choosing TimeLess Car Rental.\n\n"
                . "Best regards,\nTimeLess Car Rental Team";

            $mail->send();
        } catch (Exception $e) {
            // Optionally log the error: $mail->ErrorInfo
        }
    }
    // ------------------------------------------------------

    header("Location: bookings.php?payment=success");
    exit;
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
include '../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
/* ... your existing CSS ... */
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
.payment-method-select, .bank-select, .credit-input {
    margin: 10px 0 20px 0;
    width: 100%;
    padding: 11px 8px;
    border-radius: 6px;
    border: 1px solid #d3d3e7;
    font-size: 1.08em;
    display: block;
}
.credit-input {
    margin: 7px 0 0 0;
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
    <?php if ($payment_row && $payment_row['payment_status'] == 'paid'): ?>
        <div class="payment-info">
            Payment received on <?= htmlspecialchars($payment_row['payment_date']) ?>.<br>
            Amount: RM <?= number_format($payment_row['amount'], 2) ?><br>
            Method: <?= htmlspecialchars($payment_row['payment_method']) ?>
        </div>
        <a class="back-btn" href="bookings.php">Back to Bookings</a>
    <?php else: ?>
        <form action="payment.php" method="post" id="paymentForm" autocomplete="off">
            <input type="hidden" name="booking_id" value="<?= htmlspecialchars($booking_id) ?>">
            <label for="payment_method"><strong>Choose Payment Method:</strong></label>
            <select name="payment_method" id="payment_method" class="payment-method-select" required>
                <option value="Manual">Manual / Counter</option>
                <option value="Online Banking">Online Banking</option>
                <option value="Credit/Debit Card">Credit/Debit Card</option>
            </select>

            <!-- Online Banking Banks (hidden by default) -->
            <select name="online_bank" id="online_bank" class="bank-select" style="display:none;" required>
                <option value="">-- Select Bank --</option>
                <option value="Maybank">Maybank</option>
                <option value="CIMB">CIMB</option>
                <option value="Public Bank">Public Bank</option>
                <option value="RHB Bank">RHB Bank</option>
                <option value="Hong Leong Bank">Hong Leong Bank</option>
                <option value="Bank Islam">Bank Islam</option>
                <option value="Bank Rakyat">Bank Rakyat</option>
                <option value="UOB">UOB</option>
                <option value="OCBC">OCBC</option>
                <option value="HSBC">HSBC</option>
            </select>

            <!-- Credit Card Inputs (hidden by default) -->
            <div id="credit_details" style="display:none;">
                <input type="text" maxlength="19" name="card_number" id="card_number" class="credit-input" placeholder="Card Number (16 digits)" pattern="(?:[0-9]{4} ?){4,5}">
                <input type="text" maxlength="5" name="card_expiry" id="card_expiry" class="credit-input" placeholder="MM/YY" pattern="\d{2}/\d{2}">
                <input type="text" maxlength="4" name="card_cvc" id="card_cvc" class="credit-input" placeholder="CVC" pattern="\d{3,4}">
            </div>

            <button class="pay-btn" type="submit" name="pay_now">Pay Now</button>
        </form>
        <button class="back-btn" onclick="window.history.back()">Back</button>
        <script>
        const paymentMethod = document.getElementById('payment_method');
        const bankSelect = document.getElementById('online_bank');
        const creditDiv = document.getElementById('credit_details');
        const cardInputs = creditDiv.querySelectorAll('input');

        function updatePaymentForm() {
            if (paymentMethod.value === 'Online Banking') {
                bankSelect.style.display = '';
                bankSelect.required = true;
                creditDiv.style.display = 'none';
                cardInputs.forEach(i => { i.required = false; i.value = ''; });
            } else if (paymentMethod.value === 'Credit/Debit Card') {
                bankSelect.style.display = 'none';
                bankSelect.required = false;
                bankSelect.value = '';
                creditDiv.style.display = '';
                cardInputs.forEach(i => i.required = true);
            } else {
                bankSelect.style.display = 'none';
                bankSelect.required = false;
                bankSelect.value = '';
                creditDiv.style.display = 'none';
                cardInputs.forEach(i => { i.required = false; i.value = ''; });
            }
        }
        paymentMethod.addEventListener('change', updatePaymentForm);
        window.addEventListener('DOMContentLoaded', updatePaymentForm);

        // Optional: Format card number input
        document.getElementById('card_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '').slice(0,19);
            value = value.replace(/(.{4})/g, '$1 ').trim();
            e.target.value = value;
        });
        </script>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>