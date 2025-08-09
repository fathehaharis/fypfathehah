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

/* ---------------- CSRF ---------------- */
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    $_SESSION['cancel_error'] = "Security validation failed. Please refresh and try again.";
    header("Location: bookings.php");
    exit;
}

/* ------------- Validate request ------------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_POST['booking_id']) ||
    !ctype_digit($_POST['booking_id'])) {
    $_SESSION['cancel_error'] = "Invalid request.";
    header("Location: bookings.php");
    exit;
}

$booking_id = (int)$_POST['booking_id'];
$cust_id    = (int)$_SESSION['cust_id'];

/* ------------- Refund Policy (hours => fraction) ------------- */
const REFUND_POLICY_STEPS = [
    168 => 1.00, // >= 7 days
    72  => 0.50, // >= 3 days
    24  => 0.25, // >= 24h
    0   => 0.00  // < 24h
];

function determineRefundRate(float $hoursToPickup): float {
    foreach (REFUND_POLICY_STEPS as $threshold => $rate) {
        if ($hoursToPickup >= $threshold) return $rate;
    }
    return 0.0;
}

/* ------------- Logging helper ------------- */
function logBookingAction(mysqli $conn, int $booking_id, string $action): void {
    if ($stmt = $conn->prepare("INSERT INTO booking_log (booking_id, action) VALUES (?, ?)")) {
        $stmt->bind_param("is", $booking_id, $action);
        $stmt->execute();
        $stmt->close();
    }
}

$conn->begin_transaction();

try {
    /* 1. Lock booking row (LIMIT before FOR UPDATE!) */
    $stmt = $conn->prepare("
        SELECT 
            b.booking_id,
            b.cust_id,
            b.car_id,
            b.pickup_datetime,
            b.return_datetime,
            b.total_price,
            b.security_deposit,
            b.status,
            b.day_count,
            b.daily_rate,
            car.car_model,
            c.username,
            c.email
        FROM booking b
        JOIN customer c ON b.cust_id = c.cust_id
        JOIN car ON b.car_id = car.car_id
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
    if (!in_array($status, ['pending','approved','waiting_verification','confirmed'], true)) {
        throw new Exception("This booking cannot be cancelled in its current status.");
    }

    /* 2. Hours to pickup */
    try {
        $now       = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
        $pickup_dt = new DateTime($booking['pickup_datetime'], new DateTimeZone('Asia/Kuala_Lumpur'));
        $interval  = $now->diff($pickup_dt);
        $hours_to_pickup = ($pickup_dt > $now)
            ? ($interval->days * 24 + $interval->h + $interval->i / 60)
            : 0.0;
    } catch (Throwable $e) {
        $hours_to_pickup = 0.0;
    }

    /* 3. Confirmed < 24h rule */
    if ($status === 'confirmed' && $hours_to_pickup <= 24) {
        throw new Exception("Confirmed bookings cannot be cancelled less than 24 hours before pickup.");
    }

    /* 4. Total paid */
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount),0) AS paid_sum
        FROM payment
        WHERE booking_id = ? AND payment_status = 'paid'
        LIMIT 1
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $paid_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total_paid = (float)($paid_row['paid_sum'] ?? 0);

    $security_deposit = (float)$booking['security_deposit'];

    /* 5. Refund computation */
    $refund_rate  = determineRefundRate($hours_to_pickup);
    $base_amount  = max(0.0, $total_paid - $security_deposit); // refundable base (excludes deposit)
    $raw_refund   = round($base_amount * $refund_rate, 2);
    $refund_amount = max(0.0, min($raw_refund, $total_paid)); // clamp safety

    /* 6. Update booking status (guard) */
    $update = $conn->prepare("
        UPDATE booking
        SET status = 'cancelled'
        WHERE booking_id = ? AND status NOT IN ('cancelled','completed','rejected')
    ");
    $update->bind_param("i", $booking_id);
    $update->execute();
    if ($update->affected_rows < 1) {
        $update->close();
        throw new Exception("Booking already cancelled or finalized.");
    }
    $update->close();

    /* 7. Insert refund record (with refund_rate, base_amount) if applicable */
    if ($refund_amount > 0) {
        $rStmt = $conn->prepare("
            INSERT INTO refunds (booking_id, cust_id, amount, refund_status, refund_rate, base_amount)
            VALUES (?, ?, ?, 'pending', ?, ?)
        ");
        // Types: booking_id(i), cust_id(i), amount(d), refund_rate(d), base_amount(d)
        $rStmt->bind_param("iiddd", $booking_id, $cust_id, $refund_amount, $refund_rate, $base_amount);
        $rStmt->execute();
        $rStmt->close();
        logBookingAction(
            $conn,
            $booking_id,
            "Booking cancelled (refund pending: RM ".number_format($refund_amount,2)." at ".($refund_rate*100)."%, base RM ".number_format($base_amount,2).")"
        );
    } else {
        logBookingAction(
            $conn,
            $booking_id,
            "Booking cancelled (no refund; rate ".($refund_rate*100)."%)"
        );
    }

    /* 8. Release car if no other active bookings */
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
        logBookingAction($conn, $booking_id, "Car #{$car_id} released to 'available' (no active bookings).");
    }

    $conn->commit();

    /* 9. Email after commit */
    sendCancellationEmail(
        (string)$booking['email'],
        (string)$booking['username'],
        $booking_id,
        (string)$booking['car_model'],
        (string)$booking['pickup_datetime'],
        (string)$booking['return_datetime'],
        $refund_amount,
        $refund_rate,
        $security_deposit,
        $total_paid
    );

    $_SESSION['cancel_success'] = $refund_amount > 0
        ? "Booking cancelled. Refund (RM ".number_format($refund_amount,2).") will be processed."
        : "Booking cancelled. No refund according to the cancellation policy.";

} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['cancel_error'] = "Cancellation failed: ".$e->getMessage();
}

header("Location: bookings.php");
exit;

/* ------------- Email ------------- */
function sendCancellationEmail(
    string $to,
    string $username,
    int $booking_id,
    string $car_model,
    string $pickup_datetime,
    string $return_datetime,
    float $refundAmount,
    float $refundRate,
    float $security_deposit,
    float $total_paid
): void {
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // TODO: move credentials to environment variables
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your_gmail@example.com';
        $mail->Password   = 'your_app_password';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('no-reply@timelesscarrental.com', 'TimeLess Car Rental');
        $mail->addAddress($to, $username);
        $mail->isHTML(true);
        $mail->Subject = 'Booking Cancellation Confirmation';

        $pickup  = date('d M Y, H:i', strtotime($pickup_datetime));
        $return  = date('d M Y, H:i', strtotime($return_datetime));
        $ratePct = number_format($refundRate * 100, 0);

        $policyLine = "Refund rate applied: {$ratePct}% of paid amount excluding security deposit (RM ".number_format($security_deposit,2).").";
        $paidLine   = "Total paid: RM ".number_format($total_paid,2).".";
        $refundLine = "Refund amount: RM ".number_format($refundAmount,2).".";

        $mail->Body = "
            <p>Dear <b>".htmlspecialchars($username)."</b>,</p>
            <p>Your booking (ID: <b>{$booking_id}</b>) for <b>".htmlspecialchars($car_model)."</b> has been cancelled.</p>
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
            "Pickup: {$pickup}\nReturn: {$return}\n\n".
            "Refund rate applied: {$ratePct}% of paid amount excluding security deposit (RM ".number_format($security_deposit,2).").\n".
            "Total paid: RM ".number_format($total_paid,2).".\n".
            "Refund amount: RM ".number_format($refundAmount,2).".\n\n".
            "Refunds are processed within 3 - 5 business days.\n\n".
            "Thank you for using TimeLess Car Rental.\n\nBest regards,\nTimeLess Car Rental Team";

        $mail->send();
    } catch (Throwable $e) {
        // Optionally log email failure
    }
}