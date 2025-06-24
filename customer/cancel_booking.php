<?php
session_start();

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
require '../vendor/autoload.php'; // For PHPMailer

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);
    $cust_id = $_SESSION['cust_id'];

    // Get booking/customer/car info for email
    $stmt = $conn->prepare("SELECT c.username, c.email, b.pickup_datetime, b.return_datetime, b.booking_id, b.total_price, car.car_model, b.status
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
        $status = strtolower($data['status']);

        // Only allow cancelling for pending, approved, waiting_verification, or confirmed (if rules allow)
        if ($status == 'pending' || $status == 'approved' || $status == 'waiting_verification') {
            // Cancel without refund
            $stmt2 = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE booking_id = ?");
            $stmt2->bind_param("i", $booking_id);
            if ($stmt2->execute()) {
                sendCancellationEmail($customer_email, $customer_name, $booking_id, $car_model, $pickup_datetime, $return_datetime, false, 0);
                $_SESSION['cancel_success'] = "Booking cancelled successfully.";
            } else {
                $_SESSION['cancel_error'] = "Failed to cancel booking. Please try again.";
            }
            $stmt2->close();
            header("Location: bookings.php");
            exit;

        } elseif ($status == 'confirmed') {
            // Check if pickup is at least 24 hours from now
            date_default_timezone_set('Asia/Kuala_Lumpur');
            $now = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
            $pickup_dt = new DateTime($pickup_datetime, new DateTimeZone('Asia/Kuala_Lumpur'));
            $interval = $now->diff($pickup_dt);
            $hours_to_pickup = ($pickup_dt > $now) ? ($interval->days * 24 + $interval->h + $interval->i/60) : 0;

            if ($hours_to_pickup <= 24) {
                $_SESSION['cancel_error'] = "Confirmed bookings cannot be cancelled less than 1 day before pickup.";
                header("Location: bookings.php");
                exit;
            }

            // Cancel and process refund (minus RM100 deposit)
            $conn->begin_transaction();
            try {
                // 1. Update booking status
                $stmt2 = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE booking_id = ?");
                $stmt2->bind_param("i", $booking_id);
                if (!$stmt2->execute()) {
                    throw new Exception("Failed to update booking status");
                }
                $stmt2->close();

                // 2. Insert refund record (minus deposit)
                $refund_amount = max(0, floatval($total_price) - 100);
                $stmt3 = $conn->prepare("INSERT INTO refunds (booking_id, cust_id, amount, refund_status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt3->bind_param("iid", $booking_id, $cust_id, $refund_amount);
                if (!$stmt3->execute()) {
                    throw new Exception("Failed to insert refund record");
                }
                $stmt3->close();

                $conn->commit();

                sendCancellationEmail($customer_email, $customer_name, $booking_id, $car_model, $pickup_datetime, $return_datetime, true, $refund_amount);

                $_SESSION['cancel_success'] = "Booking cancelled successfully. Refund will be processed.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['cancel_error'] = "Failed to process refund. Please contact support.";
            }
            header("Location: bookings.php");
            exit;

        } else {
            $_SESSION['cancel_error'] = "You can only cancel pending, approved, waiting verification, or confirmed bookings (if more than 1 day before pickup).";
            header("Location: bookings.php");
            exit;
        }
    } else {
        $_SESSION['cancel_error'] = "Booking not found or you do not have permission to cancel this booking.";
        header("Location: bookings.php");
        exit;
    }

} else {
    $_SESSION['cancel_error'] = "Invalid request.";
    header("Location: bookings.php");
    exit;
}

// PHPMailer email function
function sendCancellationEmail($to, $username, $booking_id, $car_model, $pickup_datetime, $return_datetime, $isRefund, $refundAmount)
{
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'fathehaharis69@gmail.com'; // Your Gmail email
        $mail->Password   = 'cuel ijeu lzqv vsgv';      // Your Gmail app password (never your real Gmail password)
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        //Recipients
        $mail->setFrom('no-reply@timelesscarrental.com', 'TimeLess Car Rental');
        $mail->addAddress($to, $username);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Booking Cancellation Notification';

        $pickup = date('d M Y, H:i', strtotime($pickup_datetime));
        $return = date('d M Y, H:i', strtotime($return_datetime));

        if ($isRefund) {
            $mail->Body = "
                <p>Dear <b>" . htmlspecialchars($username) . "</b>,</p>
                <p>Your booking (ID: <b>{$booking_id}</b>) for <b>{$car_model}</b> has been cancelled.</p>
                <ul>
                    <li><b>Pickup:</b> {$pickup}</li>
                    <li><b>Return:</b> {$return}</li>
                    <li><b>Refund Amount:</b> RM " . number_format($refundAmount, 2) . "</li>
                </ul>
                <p>Your refund (minus RM100 deposit) will be credited to your account within <b>3 - 5 days</b> after cancellation.</p>
                <br>
                <p>Thank you for using TimeLess Car Rental.</p>
                <p>Best regards,<br>TimeLess Car Rental Team</p>
            ";
            $mail->AltBody = "Dear {$username},\n\n"
                . "Your booking (ID: {$booking_id}) for {$car_model} has been cancelled.\n"
                . "Pickup: {$pickup}\n"
                . "Return: {$return}\n"
                . "Refund Amount: RM " . number_format($refundAmount, 2) . "\n\n"
                . "Your refund (minus RM100 deposit) will be credited to your account within 3 - 5 days after cancellation.\n\n"
                . "Thank you for using TimeLess Car Rental.\n\nBest regards,\nTimeLess Car Rental Team";
        } else {
            $mail->Body = "
                <p>Dear <b>" . htmlspecialchars($username) . "</b>,</p>
                <p>Your booking (ID: <b>{$booking_id}</b>) for <b>{$car_model}</b> has been cancelled.</p>
                <ul>
                    <li><b>Pickup:</b> {$pickup}</li>
                    <li><b>Return:</b> {$return}</li>
                </ul>
                <p>Thank you for using TimeLess Car Rental.</p>
                <p>Best regards,<br>TimeLess Car Rental Team</p>
            ";
            $mail->AltBody = "Dear {$username},\n\n"
                . "Your booking (ID: {$booking_id}) for {$car_model} has been cancelled.\n"
                . "Pickup: {$pickup}\n"
                . "Return: {$return}\n\n"
                . "Thank you for using TimeLess Car Rental.\n\nBest regards,\nTimeLess Car Rental Team";
        }
        $mail->send();
    } catch (Exception $e) {
        // Optionally log or handle email error
    }
}
?>