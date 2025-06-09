<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'])) {
    $booking_id = intval($_POST['booking_id']);
    $cust_id = $_SESSION['cust_id'];

    // Check booking exists, belongs to this customer, and get its status/price
    $stmt = $conn->prepare("SELECT status, total_price FROM booking WHERE booking_id = ? AND cust_id = ?");
    $stmt->bind_param("ii", $booking_id, $cust_id);
    $stmt->execute();
    $stmt->bind_result($status, $total_price);

    if ($stmt->fetch()) {
        $stmt->close();
        $status = strtolower($status);

        if ($status == 'pending') {
            // Cancel without refund
            $stmt2 = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE booking_id = ?");
            $stmt2->bind_param("i", $booking_id);
            if ($stmt2->execute()) {
                $_SESSION['cancel_success'] = "Booking cancelled successfully.";
            } else {
                $_SESSION['cancel_error'] = "Failed to cancel booking. Please try again.";
            }
            $stmt2->close();
            header("Location: bookings.php");
            exit;

        } elseif ($status == 'confirmed') {
            // Cancel and process refund
            $conn->begin_transaction();
            try {
                // 1. Update booking status
                $stmt2 = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE booking_id = ?");
                $stmt2->bind_param("i", $booking_id);
                if (!$stmt2->execute()) {
                    throw new Exception("Failed to update booking status");
                }
                $stmt2->close();

                // 2. Insert refund record
                $stmt3 = $conn->prepare("INSERT INTO refunds (booking_id, cust_id, amount, refund_status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                $stmt3->bind_param("iid", $booking_id, $cust_id, $total_price);
                if (!$stmt3->execute()) {
                    throw new Exception("Failed to insert refund record");
                }
                $stmt3->close();

                $conn->commit();
                $_SESSION['cancel_success'] = "Booking cancelled successfully. Refund will be processed.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['cancel_error'] = "Failed to process refund. Please contact support.";
            }
            header("Location: bookings.php");
            exit;

        } else {
            $_SESSION['cancel_error'] = "You can only cancel pending or confirmed bookings.";
            header("Location: bookings.php");
            exit;
        }
    } else {
        $stmt->close();
        $_SESSION['cancel_error'] = "Booking not found or you do not have permission to cancel this booking.";
        header("Location: bookings.php");
        exit;
    }

} else {
    $_SESSION['cancel_error'] = "Invalid request.";
    header("Location: bookings.php");
    exit;
}
?>