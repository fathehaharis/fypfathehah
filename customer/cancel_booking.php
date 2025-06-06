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

    // Check booking exists and belongs to this customer and is cancellable
    $stmt = $conn->prepare("SELECT status FROM booking WHERE booking_id = ? AND cust_id = ?");
    $stmt->bind_param("ii", $booking_id, $cust_id);
    $stmt->execute();
    $stmt->bind_result($status);
    if ($stmt->fetch()) {
        $stmt->close();
        $status = strtolower($status);
        if (in_array($status, ['pending', 'confirmed'])) {
            // Update booking status to cancelled
            $stmt2 = $conn->prepare("UPDATE booking SET status = 'cancelled' WHERE booking_id = ?");
            $stmt2->bind_param("i", $booking_id);
            if ($stmt2->execute()) {
                $stmt2->close();
                $_SESSION['cancel_success'] = "Booking cancelled successfully.";
                header("Location: bookings.php");
                exit;
            } else {
                $stmt2->close();
                $_SESSION['cancel_error'] = "Failed to cancel booking. Please try again.";
            }
        } else {
            $_SESSION['cancel_error'] = "You can only cancel pending or confirmed bookings.";
        }
    } else {
        $stmt->close();
        $_SESSION['cancel_error'] = "Booking not found or you do not have permission to cancel this booking.";
    }
    // Redirect back to bookings page
    header("Location: bookings.php");
    exit;
} else {
    // Invalid access
    $_SESSION['cancel_error'] = "Invalid request.";
    header("Location: bookings.php");
    exit;
}
?>