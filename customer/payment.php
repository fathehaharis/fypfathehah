<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Kuala_Lumpur');

include '../connect.php';
require_once '../vendor/autoload.php'; // Loads PHPMailer and mPDF
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Mpdf\Mpdf;

// CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Resolve booking_id from POST or GET
$booking_id = null;
if (isset($_POST['booking_id']) && ctype_digit((string)$_POST['booking_id'])) {
    $booking_id = (int)$_POST['booking_id'];
} elseif (isset($_GET['booking_id']) && ctype_digit((string)$_GET['booking_id'])) {
    $booking_id = (int)$_GET['booking_id'];
} else {
    echo "<p>Invalid booking ID.</p>";
    include '../includes/footer.php';
    exit;
}

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

$cust_id = (int)$_SESSION['cust_id'];

// Fetch booking + car (ensure ownership)
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
        c.car_model
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

// Disallow payment for completed/cancelled
if (in_array($booking['status'], ['completed', 'cancelled'], true)) {
    echo "<p>This booking is already " . htmlspecialchars($booking['status']) . ".</p>";
    include '../includes/footer.php';
    exit;
}

// Latest payment (if any) - block if any 'pending' or 'paid' exists
$stmt = $conn->prepare("SELECT * FROM payment WHERE booking_id = ? AND payment_status IN ('pending','paid') ORDER BY payment_id DESC LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$payment_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$already_paid   = ($payment_row && strtolower($payment_row['payment_status']) === 'paid');
$pending_payment = ($payment_row && strtolower($payment_row['payment_status']) === 'pending');

// Agreement must exist (as per your flow)
$stmt = $conn->prepare("SELECT agreement_id FROM agreement_form WHERE booking_id = ? LIMIT 1");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$stmt->bind_result($agreement_id);
$stmt->fetch();
$stmt->close();
$agreement_exists = (bool)$agreement_id;

// Guarantor must exist for customer (as per your rule)
$stmt = $conn->prepare("SELECT guarantor_id FROM guarantor WHERE cust_id = ? ORDER BY guarantor_id DESC LIMIT 1");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($guarantor_id);
$stmt->fetch();
$stmt->close();
$guarantor_exists = (bool)$guarantor_id;

// Get latest delivery fee row (delivery or pickup_and_return)
$stmt = $conn->prepare("
    SELECT service_type, fee 
    FROM service 
    WHERE booking_id = ? 
      AND service_type IN ('delivery','pickup_and_return')
    ORDER BY service_id DESC
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$delivery_row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$delivery_pending = false;
$delivery_fee = 0.0;
$delivery_type_display = 'Self Pickup';
if ($delivery_row) {
    $delivery_type_display = ($delivery_row['service_type'] === 'pickup_and_return') ? 'Pickup & Return' : 'Delivery';
    if ($delivery_row['fee'] === null) {
        $delivery_pending = true; // fee not set yet
        $delivery_fee = 0.0;
    } else {
        $delivery_fee = (float)$delivery_row['fee'];
    }
}

// Compute daily-only base rental
$daily_rate = (float)($booking['daily_rate'] ?? 0);
$day_count  = (int)($booking['day_count'] ?? 0);

// Fallback derive day_count if missing
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
$base_rental = $daily_rate * $day_count;

// Security deposit
$security_deposit = isset($booking['security_deposit']) ? (float)$booking['security_deposit'] : 100.00;

// Stored total (from triggers)
$stored_total = isset($booking['total_price']) ? (float)$booking['total_price'] : null;

// Expected total (mirror your trigger logic)
$expected_total = $base_rental + $security_deposit + $delivery_fee;
$totals_match   = ($stored_total !== null) ? (abs($stored_total - $expected_total) < 0.01) : true;

// Decide amount to charge: prefer stored_total if present, else expected_total
$amount_to_charge = $stored_total !== null ? $stored_total : $expected_total;

// Strictly require 'approved' status only
$can_pay_status = ($booking['status'] === 'approved');

// Gate payment availability
$can_pay = (
    !$already_paid &&
    !$pending_payment &&
    $agreement_exists &&
    $guarantor_exists &&
    !$delivery_pending &&
    $can_pay_status &&
    $amount_to_charge > 0
);

// Payment process
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_now'])) {
    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['pay_error'] = "Invalid session. Please try again.";
        header("Location: payment.php?booking_id=".$booking_id);
        exit;
    }

    $payment_method = isset($_POST['payment_method']) ? trim((string)$_POST['payment_method']) : 'Manual';
    $bank_choice = isset($_POST['online_bank']) ? trim((string)$_POST['online_bank']) : '';

    // Enforce bank selection for Online Banking
    if ($payment_method === 'Online Banking') {
        if ($bank_choice === '') {
            $_SESSION['pay_error'] = "Please select a bank for Online Banking.";
            header("Location: payment.php?booking_id=".$booking_id);
            exit;
        }
    }

    if ($payment_method === 'Credit/Debit Card') {
        $card_number_raw = (string)($_POST['card_number'] ?? '');
        $card_expiry_raw = (string)($_POST['card_expiry'] ?? '');
        $card_cvc_raw    = (string)($_POST['card_cvc'] ?? '');

        $digits = preg_replace('/\D/', '', $card_number_raw);
        // Basic card type and length checks
        $type = 'unknown';
        if (preg_match('/^4\d{12}(\d{3})?(\d{3})?$/', $digits)) $type = 'visa';
        elseif (preg_match('/^(5[1-5]\d{14}|2(2[2-9]\d{12}|[3-6]\d{13}|7[01]\d{12}|720\d{12}))$/', $digits)) $type = 'mastercard';
        elseif (preg_match('/^3[47]\d{13}$/', $digits)) $type = 'amex';
        elseif (preg_match('/^6(?:011|5\d{2}|4[4-9]\d)\d{12}$/', $digits)) $type = 'discover';

        $valid_len = ($type === 'amex') ? 15 : 16;
        $len_ok = (strlen($digits) === $valid_len);

        // Luhn check
        $luhn_ok = false;
        if ($len_ok) {
            $sum = 0; $alt = false;
            for ($i = strlen($digits) - 1; $i >= 0; $i--) {
                $n = (int)$digits[$i];
                if ($alt) {
                    $n *= 2;
                    if ($n > 9) $n -= 9;
                }
                $sum += $n;
                $alt = !$alt;
            }
            $luhn_ok = ($sum % 10 === 0);
        }

        // Expiry MM/YY and not in past (end of month)
        $expiry_ok = false;
        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $card_expiry_raw, $m)) {
            $mm = (int)$m[1];
            $yy = (int)$m[2];
            $currentYY = (int)date('y');
            $currentMM = (int)date('m');
            $expiry_ok = ($yy > $currentYY) || ($yy === $currentYY && $mm >= $currentMM);
        }

        // CVC: 3 for most, 4 for Amex
        $cvc_digits = preg_replace('/\D/', '', $card_cvc_raw);
        $cvc_ok = ($type === 'amex') ? (strlen($cvc_digits) === 4) : (strlen($cvc_digits) === 3);

        if (!($len_ok && $luhn_ok && $expiry_ok && $cvc_ok)) {
            $_SESSION['pay_error'] = "Invalid card details. Please check card number, expiry date, and CVC.";
            header("Location: payment.php?booking_id=".$booking_id);
            exit;
        }
        // Do not store full card details. Only last4 for display.
        $_POST['card_number_last4'] = substr($digits, -4);
    }

    if (!$can_pay) {
        $_SESSION['pay_error'] = "Payment is not available for this booking. Please contact support.";
        header("Location: bookings.php");
        exit;
    }

    $payment_date   = date('Y-m-d H:i:s');
    $method_display = $payment_method;

    // Extra fields (for display only)
    if ($payment_method === 'Online Banking' && $bank_choice) {
        $method_display .= ' - ' . $bank_choice;
    }
    if ($payment_method === 'Credit/Debit Card') {
        $last4 = $_POST['card_number_last4'] ?? '';
        if ($last4) {
            $method_display .= ' - **** **** **** ' . $last4;
        }
    }

    // Transaction for safety & error logging
    try {
        $conn->begin_transaction();
        // Insert payment as paid
        $stmt = $conn->prepare("INSERT INTO payment (booking_id, payment_date, amount, payment_method, payment_status) VALUES (?, ?, ?, ?, 'paid')");
        $stmt->bind_param("isds", $booking_id, $payment_date, $amount_to_charge, $method_display);
        $okPay = $stmt->execute();
        $payment_id = $stmt->insert_id;
        $stmt->close();

        if ($okPay) {
            // --- Generate PDF receipt ---
            $stmt = $conn->prepare("SELECT b.*, c.car_brand, c.car_model, cust.full_name, cust.email 
                FROM booking b
                JOIN car c ON b.car_id = c.car_id
                JOIN customer cust ON b.cust_id = cust.cust_id
                WHERE b.booking_id = ?");
            $stmt->bind_param("i", $booking_id);
            $stmt->execute();
            $booking_info = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $receipt_no     = htmlspecialchars($payment_id);
            $customer_name  = htmlspecialchars($booking_info['full_name']);
            $customer_email = htmlspecialchars($booking_info['email']);
            $car = htmlspecialchars($booking_info['car_brand'] . ' ' . $booking_info['car_model']);
            $pickup = date('d M Y, H:i', strtotime($booking_info['pickup_datetime']));
            $return = date('d M Y, H:i', strtotime($booking_info['return_datetime']));
            $amount_formatted = number_format($amount_to_charge, 2);

            $html = <<<EOD
            <style>
            body { font-family: Arial, sans-serif; }
            .header { text-align:center; font-size:1.18em; font-weight:bold; color:#2f377d; }
            .receipt-table { width:100%; border-collapse:collapse; margin:24px 0; }
            .receipt-table th, .receipt-table td { padding:8px 12px; text-align:left; }
            .receipt-table th { background:#f8f8f8; width:38%; }
            .row-bold { font-weight:600; }
            .note { font-size:.92em; color:#6a7898; margin-top:8px; }
            </style>
            <div class="header">Payment Receipt</div>
            <table class="receipt-table" border="1">
                <tr><th>Receipt No</th><td>{$receipt_no}</td></tr>
                <tr><th>Booking ID</th><td>{$booking_info['booking_id']}</td></tr>
                <tr><th>Customer</th><td>{$customer_name} ({$customer_email})</td></tr>
                <tr><th>Car</th><td>{$car}</td></tr>
                <tr><th>Rental Period</th><td>{$pickup} &rarr; {$return}</td></tr>
                    <tr><th>Amount Paid</th><td class="row-bold">RM {$amount_formatted}</td></tr>
                <tr><th>Payment Method</th><td>{$method_display}</td></tr>
                <tr><th>Payment Date</th><td>{$payment_date}</td></tr>
            </table>
            <div class="note">Thank you for your payment. Please keep this receipt for your records.</div>
            EOD;

            $mpdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
            $mpdf->SetTitle("Receipt #$receipt_no");
            $mpdf->WriteHTML($html);
            $pdf_blob = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

            // --- Store PDF BLOB in payment table ---
            $stmt = $conn->prepare("UPDATE payment SET receipt_pdf = ? WHERE payment_id = ?");
            $null = NULL;
            $stmt->bind_param('bi', $null, $payment_id); // 'b' for blob, 'i' for int
            $stmt->send_long_data(0, $pdf_blob);
            $stmt->execute();
            $stmt->close();

            // Update booking status to confirmed (if not already)
            $stmt = $conn->prepare("UPDATE booking SET status = 'confirmed' WHERE booking_id = ? AND cust_id = ?");
            $stmt->bind_param("ii", $booking_id, $cust_id);
            $stmt->execute();
            $stmt->close();

            // Log to booking_log
            if ($log = $conn->prepare("INSERT INTO booking_log (booking_id, action) VALUES (?, ?)")) {
                $actionText = "Payment received (RM " . number_format($amount_to_charge, 2) . ", $method_display, Payment ID: $payment_id)";
                $log->bind_param("is", $booking_id, $actionText);
                $log->execute();
                $log->close();
            }

            // Send payment confirmation email (add payment_id for ref)
            sendPaymentEmail($conn, $booking_id, $cust_id, $amount_to_charge, $payment_id);

            $conn->commit();

            // Redirect to receipt/confirmation page with payment_id
            header("Location: payment_success.php?booking_id=$booking_id&payment_id=$payment_id");
            exit;
        } else {
            throw new Exception("Failed to record payment.");
        }
    } catch (Throwable $e) {
        $conn->rollback();
        // Log error to payment_error_log table
        $errMsg = $e->getMessage();
        $logStmt = $conn->prepare("INSERT INTO payment_error_log (booking_id, cust_id, error_message, created_at) VALUES (?, ?, ?, NOW())");
        $logStmt->bind_param("iis", $booking_id, $cust_id, $errMsg);
        $logStmt->execute();
        $logStmt->close();

        $_SESSION['pay_error'] = "Failed to record payment. Please try again or contact support. Error: ".$errMsg;
        header("Location: bookings.php");
        exit;
    }
}
include '../includes/header.php';
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
.payment-method-select, .bank-select, .credit-input {
    margin: 10px 0 10px 0; width: 100%; padding: 11px 8px; border-radius: 6px; border: 1px solid #d3d3e7; font-size: 1.02em; display: block;
}
.credit-label { text-align: left; font-weight: 600; color: #2f377d; margin-top: 10px; }
.brand-badge { display: inline-block; font-size: .82em; background: #eef2ff; color: #354aa6; border-radius: 12px; padding: 3px 8px; margin-left: 8px; font-weight: 700; }
.warn { color: #b85600; font-weight: 600; }
.block { color: #c33b3b; font-weight: 600; }
.ok { color: #227a41; font-weight: 700; }
</style>

<div class="payment-section">
    <div class="payment-title">Booking Payment</div>
    <table class="payment-table">
        <tr>
            <th>Car</th>
            <td><?= htmlspecialchars($booking['car_brand'] . ' ' . $booking['car_model']) ?></td>
        </tr>
        <tr>
            <th>Rental</th>
            <td>RM <?= number_format($daily_rate,2) ?> x <?= $day_count ?> day(s)</td>
        </tr>
        <tr>
            <th>Delivery</th>
            <td>
                <?php
                    if ($delivery_row) {
                        if ($delivery_pending) echo 'Pending';
                        else echo htmlspecialchars($delivery_type_display) . ' — RM ' . number_format($delivery_fee, 2);
                    } else {
                        echo 'Self Pickup — RM 0.00';
                    }
                ?>
            </td>
        </tr>
        <tr>
            <th>Security Deposit</th>
            <td>RM <?= number_format($security_deposit,2) ?></td>
        </tr>
        <tr class="total-row">
            <th>Total Amount</th>
            <td>RM <?= number_format($amount_to_charge,2) ?></td>
        </tr>
    </table>
    <?php if (!empty($_SESSION['pay_error'])): ?>
        <div class="note block"><?= htmlspecialchars($_SESSION['pay_error']); unset($_SESSION['pay_error']); ?></div>
    <?php endif; ?>
    <?php if ($stored_total !== null && !$totals_match): ?>
        <div class="note warn">Note: Amount differs from recalculated total. Using stored total from booking (RM <?= number_format($stored_total,2) ?>).</div>
    <?php endif; ?>

    <?php if (!$agreement_exists): ?>
        <div class="note block">Agreement is not available yet. Payment is disabled.</div>
    <?php endif; ?>
    <?php if (!$guarantor_exists): ?>
        <div class="note block">Guarantor record not found. Payment is disabled.</div>
    <?php endif; ?>
    <?php if ($delivery_pending): ?>
        <div class="note block">Delivery fee is pending. Payment is disabled until it is set.</div>
    <?php endif; ?>
    <?php if (!in_array($booking['status'], ['approved','confirmed'], true)): ?>
        <div class="note warn">Current status is "<?= htmlspecialchars($booking['status']) ?>". Payment is usually available only when approved.</div>
    <?php endif; ?>

    <?php if ($payment_row && strtolower($payment_row['payment_status']) === 'paid'): ?>
        <div class="ok">Payment received on <?= htmlspecialchars($payment_row['payment_date']) ?>.</div>
        <div class="note">Amount: RM <?= number_format((float)$payment_row['amount'], 2) ?> · Method: <?= htmlspecialchars($payment_row['payment_method']) ?></div>
        <a class="back-btn" href="bookings.php">Back to Bookings</a>
    <?php elseif ($can_pay): ?>
        <form action="payment.php" method="post" id="paymentForm" autocomplete="off" novalidate>
            <input type="hidden" name="booking_id" value="<?= htmlspecialchars((string)$booking_id) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <label for="payment_method"><strong>Choose Payment Method:</strong></label>
            <select name="payment_method" id="payment_method" class="payment-method-select" required>
                <option value="Online Banking">Online Banking</option>
                <option value="Credit/Debit Card">Credit/Debit Card</option>
            </select>

            <!-- Online Banking Banks (hidden by default) -->
            <select name="online_bank" id="online_bank" class="bank-select" style="display:none;" disabled>
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
            <div id="online_bank_error" class="error" style="display:none; text-align:left;">Please select a bank.</div>

            <!-- Credit Card Inputs (hidden by default) -->
            <div id="credit_details" style="display:none; text-align:left;">
                <div class="credit-label">Card Number <span id="card_brand" class="brand-badge" style="display:none;"></span></div>
                <input
                    type="text"
                    inputmode="numeric"
                    name="card_number"
                    id="card_number"
                    class="credit-input"
                    placeholder="#### #### #### ####"
                    autocomplete="cc-number"
                />
                <div id="card_number_error" class="error" style="display:none;"></div>

                <div style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <div class="credit-label">Expiry (MM/YY)</div>
                        <input
                            type="text"
                            inputmode="numeric"
                            name="card_expiry"
                            id="card_expiry"
                            class="credit-input"
                            placeholder="MM/YY"
                            autocomplete="cc-exp"
                            maxlength="5"
                        />
                        <div id="card_expiry_error" class="error" style="display:none;"></div>
                    </div>
                    <div style="flex:1;">
                        <div class="credit-label">CVC</div>
                        <input
                            type="text"
                            inputmode="numeric"
                            name="card_cvc"
                            id="card_cvc"
                            class="credit-input"
                            placeholder="CVC"
                            autocomplete="cc-csc"
                            maxlength="4"
                        />
                        <div id="card_cvc_error" class="error" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <button class="pay-btn" type="submit" name="pay_now" id="pay_btn">Pay Now</button>
        </form>
        <button class="back-btn" onclick="window.history.back()">Back</button>
        <script>
        const paymentMethod = document.getElementById('payment_method');
        const bankSelect = document.getElementById('online_bank');
        const bankErrorEl = document.getElementById('online_bank_error');
        const creditDiv = document.getElementById('credit_details');

        const cardNumberEl = document.getElementById('card_number');
        const cardExpiryEl = document.getElementById('card_expiry');
        const cardCvcEl    = document.getElementById('card_cvc');
        const cardBrandEl  = document.getElementById('card_brand');

        const errNumEl   = document.getElementById('card_number_error');
        const errExpEl   = document.getElementById('card_expiry_error');
        const errCvcEl   = document.getElementById('card_cvc_error');
        const payBtn     = document.getElementById('pay_btn');

        let currentCardType = 'unknown';

        function onlyDigits(s) { return s.replace(/\D/g, ''); }

        function detectCardType(digits) {
            if (/^4/.test(digits)) return 'visa';
            if (/^(5[1-5]|2(2[2-9]|[3-6]|7[01]|720))/.test(digits)) return 'mastercard';
            if (/^3[47]/.test(digits)) return 'amex';
            if (/^6(?:011|5|4[4-9]|22)/.test(digits)) return 'discover';
            return 'unknown';
        }

        function formatCardNumber(digits, type) {
            let parts = [];
            if (type === 'amex') {
                digits = digits.slice(0, 15);
                parts = [digits.slice(0,4), digits.slice(4,10), digits.slice(10,15)].filter(Boolean);
            } else {
                digits = digits.slice(0, 16);
                for (let i=0; i<digits.length; i+=4) parts.push(digits.slice(i, i+4));
            }
            return parts.join(' ');
        }

        function luhnCheck(numStr) {
            let sum = 0, alt = false;
            for (let i = numStr.length - 1; i >= 0; i--) {
                let n = parseInt(numStr[i], 10);
                if (alt) {
                    n *= 2;
                    if (n > 9) n -= 9;
                }
                sum += n;
                alt = !alt;
            }
            return (sum % 10) === 0;
        }

        function updateBrandUI(type) {
            currentCardType = type;
            if (type === 'amex') {
                cardCvcEl.maxLength = 4;
                cardCvcEl.placeholder = '4 digits';
            } else {
                cardCvcEl.maxLength = 3;
                cardCvcEl.placeholder = '3 digits';
            }
            if (type !== 'unknown') {
                cardBrandEl.style.display = 'inline-block';
                cardBrandEl.textContent = type.toUpperCase();
            } else {
                cardBrandEl.style.display = 'none';
                cardBrandEl.textContent = '';
            }
        }

        function validateCardNumber() {
            const digits = onlyDigits(cardNumberEl.value);
            const type = detectCardType(digits);
            updateBrandUI(type);

            // Format as user types
            const formatted = formatCardNumber(digits, type);
            cardNumberEl.value = formatted;

            const needLen = (type === 'amex') ? 15 : 16;
            let ok = (digits.length === needLen) && luhnCheck(digits);
            if (!ok) {
                errNumEl.style.display = 'block';
                errNumEl.textContent = 'Enter a valid ' + (type !== 'unknown' ? type.toUpperCase() : 'card') + ' number.';
            } else {
                errNumEl.style.display = 'none';
                errNumEl.textContent = '';
            }
            return ok;
        }

        function formatExpiryInput() {
            let v = onlyDigits(cardExpiryEl.value).slice(0,4);
            if (v.length >= 3) v = v.slice(0,2) + '/' + v.slice(2);
            cardExpiryEl.value = v;
        }

        function validateExpiry() {
            const m = cardExpiryEl.value.match(/^(0[1-9]|1[0-2])\/(\d{2})$/);
            let ok = false;
            if (m) {
                const mm = parseInt(m[1], 10);
                const yy = parseInt(m[2], 10);
                const now = new Date();
                const currentYY = parseInt(now.getFullYear().toString().slice(-2), 10);
                const currentMM = now.getMonth() + 1;
                ok = (yy > currentYY) || (yy === currentYY && mm >= currentMM);
            }
            if (!ok) {
                errExpEl.style.display = 'block';
                errExpEl.textContent = 'Enter a valid future date (MM/YY).';
            } else {
                errExpEl.style.display = 'none';
                errExpEl.textContent = '';
            }
            return ok;
        }

        function validateCVC() {
            const digits = onlyDigits(cardCvcEl.value);
            const needLen = (currentCardType === 'amex') ? 4 : 3;
            const ok = (digits.length === needLen);
            cardCvcEl.value = digits.slice(0, needLen);
            if (!ok) {
                errCvcEl.style.display = 'block';
                errCvcEl.textContent = 'Enter a ' + needLen + '-digit CVC.';
            } else {
                errCvcEl.style.display = 'none';
                errCvcEl.textContent = '';
            }
            return ok;
        }

        function validateCardSectionIfVisible() {
            if (creditDiv.style.display === 'none') return true;
            const a = validateCardNumber();
            const b = validateExpiry();
            const c = validateCVC();
            return a && b && c;
        }

        function validateBankIfVisible() {
            if (bankSelect.style.display === 'none') return true;
            const ok = bankSelect.value !== '';
            bankErrorEl.style.display = ok ? 'none' : 'block';
            return ok;
        }

        function updatePaymentForm() {
            if (paymentMethod.value === 'Online Banking') {
                bankSelect.style.display = '';
                bankSelect.disabled = false;
                bankSelect.required = true;
                creditDiv.style.display = 'none';
                // Clear card inputs/errors
                cardNumberEl.value = '';
                cardExpiryEl.value = '';
                cardCvcEl.value = '';
                errNumEl.style.display = 'none';
                errExpEl.style.display = 'none';
                errCvcEl.style.display = 'none';
                cardBrandEl.style.display = 'none';
            } else if (paymentMethod.value === 'Credit/Debit Card') {
                bankSelect.style.display = 'none';
                bankSelect.disabled = true;
                bankSelect.required = false;
                bankSelect.value = '';
                bankErrorEl.style.display = 'none';
                creditDiv.style.display = '';
            } else {
                bankSelect.style.display = 'none';
                bankSelect.disabled = true;
                bankSelect.required = false;
                bankSelect.value = '';
                bankErrorEl.style.display = 'none';
                creditDiv.style.display = 'none';
                // Clear card inputs/errors
                cardNumberEl.value = '';
                cardExpiryEl.value = '';
                cardCvcEl.value = '';
                errNumEl.style.display = 'none';
                errExpEl.style.display = 'none';
                errCvcEl.style.display = 'none';
                cardBrandEl.style.display = 'none';
            }
            // Enable/disable button
            if (paymentMethod.value === 'Credit/Debit Card') {
                payBtn.disabled = !validateCardSectionIfVisible();
            } else if (paymentMethod.value === 'Online Banking') {
                payBtn.disabled = !validateBankIfVisible();
            } else {
                payBtn.disabled = false;
            }
        }

        paymentMethod.addEventListener('change', updatePaymentForm);
        window.addEventListener('DOMContentLoaded', updatePaymentForm);

        if (bankSelect) {
            bankSelect.addEventListener('change', () => {
                if (paymentMethod.value === 'Online Banking') {
                    const ok = validateBankIfVisible();
                    payBtn.disabled = !ok;
                }
            });
        }

        if (cardNumberEl) {
            cardNumberEl.addEventListener('input', () => {
                validateCardNumber();
                payBtn.disabled = (paymentMethod.value === 'Credit/Debit Card') ? !validateCardSectionIfVisible() : false;
            });
            // Prevent non-digit paste characters from breaking formatting
            cardNumberEl.addEventListener('paste', (e) => {
                e.preventDefault();
                const text = (e.clipboardData || window.clipboardData).getData('text');
                const digits = onlyDigits(text);
                const type = detectCardType(digits);
                updateBrandUI(type);
                cardNumberEl.value = formatCardNumber(digits, type);
                validateCardNumber();
                payBtn.disabled = (paymentMethod.value === 'Credit/Debit Card') ? !validateCardSectionIfVisible() : false;
            });
        }

        if (cardExpiryEl) {
            cardExpiryEl.addEventListener('input', () => {
                formatExpiryInput();
                validateExpiry();
                payBtn.disabled = (paymentMethod.value === 'Credit/Debit Card') ? !validateCardSectionIfVisible() : false;
            });
        }

        if (cardCvcEl) {
            cardCvcEl.addEventListener('input', () => {
                // Force digits only
                cardCvcEl.value = onlyDigits(cardCvcEl.value);
                validateCVC();
                payBtn.disabled = (paymentMethod.value === 'Credit/Debit Card') ? !validateCardSectionIfVisible() : false;
            });
        }

        // Final guard before submit
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            if (paymentMethod.value === 'Credit/Debit Card' && !validateCardSectionIfVisible()) {
                e.preventDefault();
                payBtn.disabled = true;
            }
            if (paymentMethod.value === 'Online Banking' && !validateBankIfVisible()) {
                e.preventDefault();
                payBtn.disabled = true;
            }
        });
        </script>
    <?php else: ?>
        <div class="note block">Payment is currently unavailable for this booking.</div>
        <a class="back-btn" href="bookings.php">Back to Bookings</a>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>

<?php
// --------------- Email helper ---------------
function sendPaymentEmail(mysqli $conn, int $booking_id, int $cust_id, float $amount, int $payment_id)
{
    // Fetch details for email
    $stmt = $conn->prepare("
        SELECT c.username, c.email, b.pickup_datetime, b.return_datetime, b.booking_id, car.car_model
        FROM booking b
        JOIN customer c ON b.cust_id = c.cust_id
        JOIN car ON b.car_id = car.car_id
        WHERE b.booking_id = ? AND b.cust_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $booking_id, $cust_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) return;

    $customer_name   = (string)$data['username'];
    $customer_email  = (string)$data['email'];
    $pickup_datetime = (string)$data['pickup_datetime'];
    $return_datetime = (string)$data['return_datetime'];
    $car_model       = (string)$data['car_model'];

    $mail = new PHPMailer(true);
    try {
        // Configure SMTP (move to env/config in production)
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'fathehaharis69@gmail.com'; // Your SMTP username
        $mail->Password   = 'cuel ijeu lzqv vsgv';       // Your SMTP app password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('no-reply@timelesscarrental.com', 'TimeLess Car Rental');
        $mail->addAddress($customer_email, $customer_name);

        $mail->isHTML(true);
        $mail->Subject = "Payment Received - TimeLess Car Rental";

        $pickup = date('d M Y, H:i', strtotime($pickup_datetime));
        $return = date('d M Y, H:i', strtotime($return_datetime));

        $mail->Body    = "
            <h2>Payment Received</h2>
            <p>Dear <strong>" . htmlspecialchars($customer_name) . "</strong>,</p>
            <p>Your payment for the booking <strong>#{$booking_id}</strong> has been received and confirmed!</p>
            <table style='border-collapse:collapse;'>
                <tr><td style='padding:4px 8px;font-weight:bold;'>Car Model</td><td style='padding:4px 8px;'>" . htmlspecialchars($car_model) . "</td></tr>
                <tr><td style='padding:4px 8px;font-weight:bold;'>Pickup Date &amp; Time</td><td style='padding:4px 8px;'>{$pickup}</td></tr>
                <tr><td style='padding:4px 8px;font-weight:bold;'>Return Date &amp; Time</td><td style='padding:4px 8px;'{$return}</td></tr>
                <tr><td style='padding:4px 8px;font-weight:bold;'>Amount Paid</td><td style='padding:4px 8px;'>RM " . number_format($amount,2) . "</td></tr>
                <tr><td style='padding:4px 8px;font-weight:bold;'>Payment Reference</td><td style='padding:4px 8px;'>" . intval($payment_id) . "</td></tr>
            </table>
            <p>Thank you for choosing TimeLess Car Rental. We look forward to serving you!</p>
            <br>
            <p>Best regards,<br>TimeLess Car Rental Team</p>
        ";
        $mail->AltBody =
            "Dear {$customer_name},\n\n" .
            "Your payment for booking #{$booking_id} has been received and confirmed!\n\n" .
            "Car Model: {$car_model}\n" .
            "Pickup Date & Time: {$pickup}\n" .
            "Return Date & Time: {$return}\n" .
            "Amount Paid: RM " . number_format($amount,2) . "\n" .
            "Payment Ref: " . intval($payment_id) . "\n\n" .
            "Thank you for choosing TimeLess Car Rental.\n\n" .
            "Best regards,\nTimeLess Car Rental Team";

        $mail->send();
    } catch (Exception $e) {
        // Optionally log $mail->ErrorInfo
    }
}
?>