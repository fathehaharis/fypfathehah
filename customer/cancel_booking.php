<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');
require '../connect.php';
require '../vendor/autoload.php'; // PHPMailer

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    empty($_POST['csrf_token']) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? '')) ||
    !isset($_POST['booking_id']) ||
    !ctype_digit($_POST['booking_id'])
) {
    $_SESSION['cancel_error'] = "Invalid or expired request.";
    header("Location: bookings.php");
    exit;
}

$booking_id = (int)$_POST['booking_id'];
$cust_id    = (int)$_SESSION['cust_id'];

/*
 * Rental fee refund policy based on calendar days before pickup.
 * If cancel 7+ days before: 100%
 * 3-6 days: 50%
 * 1-2 days: 25%
 * Same day or after: 0%
 * Security deposit is always non-refundable.
 */
const RENTAL_REFUND_POLICY_DAYS = [
    7 => 1.00,  // 7 or more days before pickup
    3 => 0.50,  // 3-6 days
    1 => 0.25,  // 1-2 days
    0 => 0.00   // Same day or after
];
function determineRentalRefundRateDays(int $daysToPickup): float {
    foreach (RENTAL_REFUND_POLICY_DAYS as $threshold => $rate) {
        if ($daysToPickup >= $threshold) return $rate;
    }
    return 0.0;
}

function logBookingAction(mysqli $conn, int $booking_id, string $action): void {
    if ($stmt = $conn->prepare("INSERT INTO booking_log (booking_id, action) VALUES (?, ?)")) {
        $stmt->bind_param("is", $booking_id, $action);
        $stmt->execute();
        $stmt->close();
    }
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        SELECT 
            b.booking_id,
            b.cust_id,
            b.car_id,
            b.pickup_datetime,
            b.return_datetime,
            b.status,
            b.total_price,
            b.security_deposit,
            b.deposit_status,
            car.car_model,
            c.username,
            c.email
        FROM booking b
        JOIN customer c ON b.cust_id = c.cust_id
        JOIN car car ON b.car_id = car.car_id
        WHERE b.booking_id = ? AND b.cust_id = ?
        LIMIT 1 FOR UPDATE
    ");
    $stmt->bind_param("ii", $booking_id, $cust_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$booking) {
        throw new Exception("Booking not found or access denied.");
    }

    $status = strtolower($booking['status']);
    $depositStatus = strtolower((string)$booking['deposit_status']);
    $cancellableStatuses = ['pending','approved','waiting_verification','confirmed'];
    if (!in_array($status, $cancellableStatuses, true)) {
        throw new Exception("This booking cannot be cancelled in its current status.");
    }
    if ($status === 'cancelled') {
        throw new Exception("Booking already cancelled.");
    }

    // Calendar days to pickup
    $nowDay = (new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur')))->setTime(0,0,0,0);
    try {
        $pickupDay = (new DateTime($booking['pickup_datetime'], new DateTimeZone('Asia/Kuala_Lumpur')))->setTime(0,0,0,0);
    } catch (Throwable $e) {
        $pickupDay = clone $nowDay;
    }
    $days_to_pickup = (int)$nowDay->diff($pickupDay)->format('%r%a'); // negative means after pickup

    // Prevent cancellation after pickup
    if ($days_to_pickup < 0) {
        throw new Exception("Cannot cancel after the pickup day has passed.");
    }
    // Block confirmed < 1 calendar day before pickup
    if ($status === 'confirmed' && $days_to_pickup < 1) {
        throw new Exception("Confirmed bookings cannot be cancelled less than 1 calendar day before pickup.");
    }

    $security_deposit = (float)$booking['security_deposit'];
    $total_price = (float)$booking['total_price'];
    $rental_fee = max(0.0, $total_price - $security_deposit); // rental portion only

    $rental_refund_rate = determineRentalRefundRateDays($days_to_pickup);
    $rental_refund_amount = round($rental_fee * $rental_refund_rate, 2);

    // Prepare cancellation reason for audit
    $cancellation_reason = "Customer-initiated cancellation: Security deposit is non-refundable as per policy.";

    // Update: mark as cancelled and forfeit deposit
    $update = $conn->prepare("
        UPDATE booking
        SET status = 'cancelled',
            deposit_status = 'forfeited',
            security_deposit_refund = 0,
            security_deposit_deduction = ?,
            cancellation_reason = ?,
            updated_at = NOW()
        WHERE booking_id = ?
          AND status NOT IN ('cancelled','completed','rejected')
    ");
    $update->bind_param("dsi", $security_deposit, $cancellation_reason, $booking_id);
    $update->execute();
    if ($update->affected_rows < 1) {
        $update->close();
        throw new Exception("Booking already cancelled or finalized.");
    }
    $update->close();

    // Insert refund record for rental fee if refund amount > 0
    if ($rental_refund_amount > 0) {
        $insertRefund = $conn->prepare("
            INSERT INTO refunds 
                (booking_id, cust_id, amount, refund_status, reference_code, created_at, notes, user_unread, refund_rate, base_amount)
            VALUES (?, ?, ?, 'pending', ?, NOW(), ?, 1, ?, ?)
        ");
        $reference_code = "RENTAL-" . $booking_id;
        $notes = "Cancellation refund";
        $refund_rate = $rental_refund_rate;
        $base_amount = $rental_fee;
        $insertRefund->bind_param(
            "iidssdd",
            $booking_id,
            $cust_id,
            $rental_refund_amount,
            $reference_code,
            $notes,
            $refund_rate,
            $base_amount
        );
        $insertRefund->execute();
        $insertRefund->close();
    }

    logBookingAction(
        $conn,
        $booking_id,
        "Booking cancelled; rental refund=RM ".number_format($rental_refund_amount,2)." (rate ".($rental_refund_rate*100)."%) | Deposit forfeited"
    );

    // Release car if no other active bookings
    $car_id = (int)$booking['car_id'];
    $chk = $conn->prepare("
        SELECT 1
        FROM booking
        WHERE car_id = ?
          AND booking_id <> ?
          AND status IN ('pending','approved','waiting_verification','confirmed')
        LIMIT 1
    ");
    $chk->bind_param("ii", $car_id, $booking_id);
    $chk->execute();
    $chk->store_result();
    $has_other_active = $chk->num_rows > 0;
    $chk->close();

    if (!$has_other_active) {
        $carUpdate = $conn->prepare("UPDATE car SET status='available' WHERE car_id = ?");
        $carUpdate->bind_param("i", $car_id);
        $carUpdate->execute();
        $carUpdate->close();
        logBookingAction($conn, $booking_id, "Car #{$car_id} marked available after cancellation.");
    }

    $conn->commit();

    // Email after commit
    sendCancellationEmailRentalOnly(
        (string)$booking['email'],
        (string)$booking['username'],
        $booking_id,
        (string)$booking['car_model'],
        (string)$booking['pickup_datetime'],
        (string)$booking['return_datetime'],
        $security_deposit,
        $total_price,
        $rental_refund_amount,
        $rental_refund_rate,
        $days_to_pickup
    );

    $_SESSION['cancel_success'] =
        $rental_refund_amount > 0
            ? "Booking cancelled. Your rental fee refund (RM ".number_format($rental_refund_amount,2).") will be processed. Security deposit is non-refundable and has been forfeited."
            : "Booking cancelled. No rental fee refund according to policy. Security deposit is non-refundable and has been forfeited.";

} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['cancel_error'] = "Cancellation failed: ".$e->getMessage();
}

header("Location: bookings.php");
exit;

/* ------------- Email (Rental Fee Only) ------------- */
function sendCancellationEmailRentalOnly(
    string $to,
    string $username,
    int $booking_id,
    string $car_model,
    string $pickup_datetime,
    string $return_datetime,
    float $depositHeld,
    float $totalPaid,
    float $rentalRefund,
    float $refundRate,
    int $daysToPickup
): void {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'fathehaharis69@gmail.com'; // Your SMTP username
        $mail->Password   = 'cuel ijeu lzqv vsgv';   // Your SMTP password or app password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('no-reply@timelesscarrental.com', 'TimeLess Car Rental');
        $mail->addAddress($to, $username);
        $mail->isHTML(true);
        $mail->Subject = 'Booking Cancellation Confirmation';

        $pickup  = date('d M Y, H:i', strtotime($pickup_datetime));
        $return  = date('d M Y, H:i', strtotime($return_datetime));
        $ratePct = number_format($refundRate * 100, 0);

        if ($rentalRefund > 0) {
            $refundLine = "Refundable portion of your rental fee: <b>RM ".number_format($rentalRefund,2)."</b> (Rate: {$ratePct}%).<br><span style='color:#607080'>Cancellation made {$daysToPickup} calendar day(s) before pickup.</span>";
            $footerLine  = "Refund will be processed by our finance team (typically within 3 - 5 business days).";
        } else {
            $refundLine = "According to our cancellation policy and timing, <b>no rental fee refund</b> will be issued.<br><span style='color:#607080'>Cancellation made {$daysToPickup} calendar day(s) before pickup.</span>";
            $footerLine  = "If you believe this is an error, please contact support.";
        }

        $mail->Body = "
            <p>Dear <b>".htmlspecialchars($username)."</b>,</p>
            <p>Your booking (ID: <b>{$booking_id}</b>) for <b>".htmlspecialchars($car_model)."</b> has been cancelled.</p>
            <ul style='line-height:1.4'>
                <li><b>Pickup:</b> {$pickup}</li>
                <li><b>Return:</b> {$return}</li>
                <li><b>Security Deposit Held:</b> RM ".number_format($depositHeld,2)." <span style='color:#a00'>(non-refundable and forfeited)</span></li>
                <li><b>Total Paid:</b> RM ".number_format($totalPaid,2)."</li>
            </ul>
            <p>{$refundLine}</p>
            <p>{$footerLine}</p>
            <p>Thank you for using TimeLess Car Rental.</p>
            <p style='margin-top:24px'>Best regards,<br>TimeLess Car Rental Team</p>
        ";

        $mail->AltBody =
"Dear {$username},

Your booking (ID: {$booking_id}) for {$car_model} has been cancelled.
Pickup: {$pickup}
Return: {$return}
Security Deposit Held: RM ".number_format($depositHeld,2)." (non-refundable and forfeited)
Total Paid: RM ".number_format($totalPaid,2)."

".($rentalRefund > 0
    ? "Refundable portion of your rental fee: RM ".number_format($rentalRefund,2)." (Rate: {$ratePct}%).\nCancellation made {$daysToPickup} calendar day(s) before pickup.\nRefund will be processed within 3 - 5 business days."
    : "No rental fee refund will be issued under the cancellation policy.\nCancellation made {$daysToPickup} calendar day(s) before pickup.")."

Thank you for using TimeLess Car Rental.

Best regards,
TimeLess Car Rental Team";

        $mail->send();
    } catch (Throwable $e) {
        // Optionally log mail error
    }
}