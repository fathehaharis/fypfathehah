<?php
// This script should be run periodically (e.g., every 5 or 10 minutes) via cron.
// It sets all bookings to 'completed' if their return_datetime has passed and they are still 'confirmed' or 'pending'.

// Set timezone if needed
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once __DIR__ . '/../connect.php'; // Adjust path as needed

// Only update bookings whose return_datetime < NOW() and status is 'confirmed' or 'pending'
$sql = "UPDATE booking SET status = 'completed'
        WHERE status IN ('confirmed', 'pending') AND return_datetime < NOW()";

if ($conn->query($sql) === TRUE) {
    // Optional: logging for success
    // echo "Booking statuses updated successfully.\n";
} else {
    // Optional: logging for error
    // error_log('Error updating booking statuses: ' . $conn->error);
}

// Close connection
$conn->close();
?>