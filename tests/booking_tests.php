<?php
/**
 * Booking Module Tests
 * Note: This file should not be accessed directly. Run through test_runner.php
 */

// Prevent direct access
if (!function_exists('runTestGroup')) {
    die("This file should not be accessed directly. Run tests through test_runner.php");
}

// Test booking creation
function testBookingCreation() {
    $db = getTestDB();
    $customer = createTestCustomer($db, '_booking');
    $car = createTestCar($db, '_booking');
    
    // Create booking
    $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
    $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
    
    $stmt = $db->prepare("
        INSERT INTO booking 
        (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
         daily_rate, total_price, security_deposit, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $day_count = 3;
    $daily_rate = $car['daily_rate'];
    $total_price = $day_count * $daily_rate + 100.00;
    $security_deposit = 100.00;
    $status = 'pending';
    
    $stmt->bind_param("iissiddds", 
        $customer['cust_id'], $car['car_id'], $pickup_date, $return_date,
        $day_count, $daily_rate, $total_price, $security_deposit, $status
    );
    
    $stmt->execute();
    $booking_id = $db->insert_id;
    
    // Verify booking was created
    $stmt = $db->prepare("
        SELECT * FROM booking WHERE booking_id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking_exists = $result->num_rows > 0;
    
    // Clean up
    $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
    $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    if (!$booking_exists) {
        return "Booking was not created successfully";
    }
    
    return true;
}

// Test booking status update
function testBookingStatusUpdate() {
    $db = getTestDB();
    $customer = createTestCustomer($db, '_status');
    $car = createTestCar($db, '_status');
    
    // Create booking
    $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
    $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
    
    $stmt = $db->prepare("
        INSERT INTO booking 
        (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
         daily_rate, total_price, security_deposit, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $day_count = 3;
    $daily_rate = $car['daily_rate'];
    $total_price = $day_count * $daily_rate + 100.00;
    $security_deposit = 100.00;
    $status = 'pending';
    
    $stmt->bind_param("iissiddds", 
        $customer['cust_id'], $car['car_id'], $pickup_date, $return_date,
        $day_count, $daily_rate, $total_price, $security_deposit, $status
    );
    
    $stmt->execute();
    $booking_id = $db->insert_id;
    
    // Update booking status to approved
    $new_status = 'approved';
    $stmt = $db->prepare("
        UPDATE booking SET status = ? WHERE booking_id = ?
    ");
    $stmt->bind_param("si", $new_status, $booking_id);
    $stmt->execute();
    
    // Verify status update
    $stmt = $db->prepare("
        SELECT status FROM booking WHERE booking_id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    
    // Clean up
    $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
    $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    if ($booking['status'] !== 'approved') {
        return "Booking status was not updated properly";
    }
    
    return true;
}

// Test booking cancellation
function testBookingCancellation() {
    $db = getTestDB();
    $customer = createTestCustomer($db, '_cancel');
    $car = createTestCar($db, '_cancel');
    
    // Create booking
    $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
    $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
    
    $stmt = $db->prepare("
        INSERT INTO booking 
        (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
         daily_rate, total_price, security_deposit, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $day_count = 3;
    $daily_rate = $car['daily_rate'];
    $total_price = $day_count * $daily_rate + 100.00;
    $security_deposit = 100.00;
    $status = 'pending';
    
    $stmt->bind_param("iissiddds", 
        $customer['cust_id'], $car['car_id'], $pickup_date, $return_date,
        $day_count, $daily_rate, $total_price, $security_deposit, $status
    );
    
    $stmt->execute();
    $booking_id = $db->insert_id;
    
    // Cancel booking
    $cancel_reason = 'Test cancellation';
    $stmt = $db->prepare("
        UPDATE booking 
        SET status = 'cancelled', cancellation_reason = ?
        WHERE booking_id = ?
    ");
    $stmt->bind_param("si", $cancel_reason, $booking_id);
    $stmt->execute();
    
    // Verify cancellation
    $stmt = $db->prepare("
        SELECT status, cancellation_reason FROM booking WHERE booking_id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    
    // Clean up
    $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
    $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    if ($booking['status'] !== 'cancelled' || $booking['cancellation_reason'] !== $cancel_reason) {
        return "Booking cancellation failed";
    }
    
    return true;
}

// Test payment processing
function testPaymentProcessing() {
    $db = getTestDB();
    $customer = createTestCustomer($db, '_payment');
    $car = createTestCar($db, '_payment');
    
    // Create booking
    $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
    $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
    
    $stmt = $db->prepare("
        INSERT INTO booking 
        (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
         daily_rate, total_price, security_deposit, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $day_count = 3;
    $daily_rate = $car['daily_rate'];
    $total_price = $day_count * $daily_rate + 100.00;
    $security_deposit = 100.00;
    $status = 'approved';
    
    $stmt->bind_param("iissiddds", 
        $customer['cust_id'], $car['car_id'], $pickup_date, $return_date,
        $day_count, $daily_rate, $total_price, $security_deposit, $status
    );
    
    $stmt->execute();
    $booking_id = $db->insert_id;
    
    // Process payment
    $stmt = $db->prepare("
        INSERT INTO payment
        (booking_id, payment_date, amount, payment_method, payment_status)
        VALUES (?, NOW(), ?, ?, ?)
    ");
    
    $amount = $total_price;
    $payment_method = 'online_banking';
    $payment_status = 'paid';
    
    $stmt->bind_param("idss", $booking_id, $amount, $payment_method, $payment_status);
    $stmt->execute();
    $payment_id = $db->insert_id;
    
    // Update booking status
    $stmt = $db->prepare("
        UPDATE booking
        SET status = 'confirmed', confirmed_at = NOW()
        WHERE booking_id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    
    // Verify payment and booking status
    $stmt = $db->prepare("
        SELECT p.payment_status, b.status 
        FROM payment p
        JOIN booking b ON p.booking_id = b.booking_id
        WHERE p.payment_id = ?
    ");
    $stmt->bind_param("i", $payment_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    // Clean up
    $db->query("DELETE FROM payment WHERE payment_id = $payment_id");
    $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
    $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    if ($data['payment_status'] !== 'paid' || $data['status'] !== 'confirmed') {
        return "Payment processing failed";
    }
    
    return true;
}

// Run booking tests
runTestGroup("Booking Module Tests", [
    "Booking Creation" => 'testBookingCreation',
    "Booking Status Update" => 'testBookingStatusUpdate',
    "Booking Cancellation" => 'testBookingCancellation',
    "Payment Processing" => 'testPaymentProcessing'
]);
?>