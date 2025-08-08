<?php
session_start();

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

include '../connect.php';
require '../vendor/autoload.php'; // PHPMailer

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['booking_id']) || !is_numeric($_POST['booking_id'])) {
    $_SESSION['cancel_error'] = "Invalid request.";
    header("Location: bookings.php");
    exit;
}

$booking_id = (int)$_POST['booking_id'];
$cust_id    = (int)$_SESSION['cust_id'];

/* Refund policy: hours to pickup -> refund rate (excluding security deposit) */
function getRefundRate(float $hoursToPickup): float {
    if ($hoursToPickup >= 168) { // 7+ days
        return 1.00;
    } elseif ($hoursToPickup >= 72) { // 3-6 days
        return 0.50;
    } elseif ($hoursToPickup >= 24) { // 24-72 hours
        return 0.25;
    }
    return 0.00; // <24 hours
}

/* Helper: log action into booking_log (best-effort) */
function logBookingAction(mysqli $conn, int $booking_id, string $action): void {
    if ($stmt = $conn->prepare("INSERT INTO booking_log (booking_id, action) VALUES (?, ?)")) {
        $stmt->bind_param("is", $booking_id, $action);
        $stmt->execute();
        $stmt->close();
    }
}

/* Fetch booking, customer, car (ensure ownership) */
$stmt = $conn->prepare("
    SELECT 
        c.username, 
        c.email, 
        b.pickup_datetime, 
        b.return_datetime, 
        b.booking_id, 
        b.total_price, 
        b.security_deposit,
        b.status,
        b.day_count,
        b.daily_rate,
        car.car_model
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

if (!$data) {
    $_SESSION['cancel_error'] = "Booking not found or you do not have permission to cancel this booking.";
    header("Location: bookings.php");
    exit;
}

$customer_name    = (string)$data['username'];
$customer_email   = (string)$data['email'];
$pickup_datetime  = (string)$data['pickup_datetime'];
$return_datetime  = (string)$data['return_datetime'];
$car_model        = (string)$data['car_model'];
$booking_id       = (int)$data['booking_id'];
$total_price      = (float)$data['total_price'];
$security_deposit = (float)$data['security_deposit'];
$status           = strtolower((string)$data['status']);

/* How much has actually been paid? */
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS paid_sum FROM payment WHERE booking_id = ? AND payment_status = 'paid'");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$paid_sum_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$total_paid = (float)($paid_sum_row['paid_sum'] ?? 0);

/* Compute hours to pickup (for policy) */
try {
    $now       = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
    $pickup_dt = new DateTime($pickup_datetime, new DateTimeZone('Asia/Kuala_Lumpur'));
    $interval  = $now->diff($pickup_dt);
    $hours_to_pickup = ($pickup_dt > $now) ? ($interval->days * 24 + $interval->h + $interval->i / 60) : 0.0;
} catch (Throwable $e) {
    $hours_to_pickup = 0.0;
}

/* Only allow cancellation for pending, approved, waiting_verification, confirmed (your original rule) */
if (!in_array($status, ['pending','approved','waiting_verification','confirmed'], true)) {
    $_SESSION['cancel_error'] = "You can only cancel pending, approved, waiting verification, or confirmed bookings (if more than 1 day before pickup).";
    header("Location: bookings.php");
    exit;
}

/* Keep your strict 24h block for confirmed bookings */
if ($status === 'confirmed' && $hours_to_pickup <= 24) {
    $_SESSION['cancel_error'] = "Confirmed bookings cannot be cancelled less than 1 day before pickup.";
    header("Location: bookings.php");
    exit;
}

/* Compute refund based on policy (deposit is non-refundable) */
$refund_rate = getRefundRate($hours_to_pickup);

/* Exclude the security deposit from the refundable base.
   Cap by what was actually paid. */
$refundable_base = max(0.0, $total_paid - $security_deposit);
$proposed_refund = round($refundable_base * $refund_rate, 2);

/* If nothing paid yet, refund is naturally 0 */
$refund_amount = max(0.0, min($proposed_refund, $total_paid)); // safety cap

/* Perform cancellation and (optional) refund record */
$conn->begin_transaction();
try {
    // 1) Update booking status
    $stmtU = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE booking_id = ?");
    $stmtU->bind_param("i", $booking_id);
    if (!$stmtU->execute()) {
        throw new Exception("Failed to update booking status.");
    }
    $stmtU->close();

    // 2) Insert refunds record if needed
    if ($refund_amount > 0) {
        $stmtR = $conn->prepare("INSERT INTO refunds (booking_id, cust_id, amount, refund_status) VALUES (?, ?, ?, 'pending')");
        $stmtR->bind_param("iid", $booking_id, $cust_id, $refund_amount);
        if (!$stmtR->execute()) {
            throw new Exception("Failed to insert refund record.");
        }
        $stmtR->close();
        logBookingAction($conn, $booking_id, "Booking cancelled by customer (refund pending, rate ".($refund_rate*100)."%)");
    } else {
        logBookingAction($conn, $booking_id, "Booking cancelled by customer (no refund per policy, rate ".($refund_rate*100)."%)");
    }

    $conn->commit();

    // Email
    sendCancellationEmail(
        $customer_email,
        $customer_name,
        $booking_id,
        $car_model,
        $pickup_datetime,
        $return_datetime,
        $refund_amount,
        $refund_rate,
        $security_deposit,
        $total_paid
    );

    if ($refund_amount > 0) {
        $_SESSION['cancel_success'] = "Booking cancelled. Refund (RM ".number_format($refund_amount,2).") will be processed.";
    } else {
        $_SESSION['cancel_success'] = "Booking cancelled. No refund according to the cancellation policy.";
    }
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['cancel_error'] = "Failed to cancel booking. Please try again or contact support.";
}

header("Location: bookings.php");
exit;

/* PHPMailer email function */
function sendCancellationEmail($to, $username, $booking_id, $car_model, $pickup_datetime, $return_datetime, $refundAmount, $refundRate, $security_deposit, $total_paid)
{
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // SMTP settings (move to env/config in production)
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'fathehaharis69@gmail.com'; // Gmail address
        $mail->Password   = 'cuel ijeu lzqv vsgv';      // App password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('no-reply@timelesscarrental.com', 'TimeLess Car Rental');
        $mail->addAddress($to, $username);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Booking Cancellation Confirmation';

        $pickup = date('d M Y, H:i', strtotime($pickup_datetime));
        $return = date('d M Y, H:i', strtotime($return_datetime));
        $ratePct = number_format($refundRate * 100, 0);

        $policyLine = "Refund rate applied: {$ratePct}% of paid amount excluding security deposit (RM ".number_format($security_deposit,2).").";
        $paidLine   = "Total paid: RM ".number_format($total_paid,2).".";
        $refundLine = "Refund amount: RM ".number_format($refundAmount,2).".";

        $mail->Body = "
            <p>Dear <b>" . htmlspecialchars($username) . "</b>,</p>
            <p>Your booking (ID: <b>{$booking_id}</b>) for <b>" . htmlspecialchars($car_model) . "</b> has been cancelled.</p>
            <ul>
                <li><b>Pickup:</b> {$pickup}</li>
                <li><b>Return:</b> {$return}</li>
            </ul>
            <p>{$policyLine}<br>{$paidLine}<br>{$refundLine}</p>
            <p>Refunds are processed within <b>3 - 5 business days</b>.</p>
            <br>
            <p>Thank you for using TimeLess Car Rental.</p>
            <p>Best regards,<br>TimeLess Car Rental Team</p>
        ";

        $mail->AltBody =
            "Dear {$username},\n\n".
            "Your booking (ID: {$booking_id}) for {$car_model} has been cancelled.\n".
            "Pickup: {$pickup}\n".
            "Return: {$return}\n\n".
            "Refund rate applied: {$ratePct}% of paid amount excluding security deposit (RM ".number_format($security_deposit,2).").\n".
            "Total paid: RM ".number_format($total_paid,2).".\n".
            "Refund amount: RM ".number_format($refundAmount,2).".\n\n".
            "Refunds are processed within 3 - 5 business days.\n\n".
            "Thank you for using TimeLess Car Rental.\n\nBest regards,\nTimeLess Car Rental Team";

        $mail->send();
    } catch (Exception $e) {
        // Optionally log email failure
    }
}