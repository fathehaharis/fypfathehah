<?php
/**
 * Database Integrity Tests
 * Note: This file should not be accessed directly. Run through test_runner.php
 */

// Prevent direct access
if (!function_exists('runTestGroup')) {
    die("This file should not be accessed directly. Run tests through test_runner.php");
}

// Test foreign key constraints
function testForeignKeyConstraints() {
    $db = getTestDB();
    
    // Create a customer
    $customer = createTestCustomer($db, '_fk');
    
    // Create a car
    $car = createTestCar($db, '_fk');
    
    // Create a booking
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
    
    // Try to delete the customer - should fail due to FK constraint
    $customer_delete_success = true;
    try {
        $stmt = $db->prepare("DELETE FROM customer WHERE cust_id = ?");
        $stmt->bind_param("i", $customer['cust_id']);
        $stmt->execute();
    } catch (Exception $e) {
        $customer_delete_success = false;
    }
    
    // Clean up properly
    $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
    
    // The test should fail if we were able to delete a customer with active bookings
    if ($customer_delete_success) {
        return "Foreign key constraint failed - shouldn't be able to delete customer with active bookings";
    }
    
    return true;
}

// Test unique constraints
function testUniqueConstraints() {
    $db = getTestDB();
    
    // Test unique email constraint
    $customer1 = createTestCustomer($db, '_unique');
    
    // Try to create another customer with the same email
    $stmt = $db->prepare("
        INSERT INTO customer 
        (full_name, phone_no, email, username, password, profile_status) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $name = 'Duplicate Test';
    $phone = '0187654321';
    $email = $customer1['email']; // Same email
    $username = 'unique_test_' . time();
    $password_hash = password_hash('Password123', PASSWORD_DEFAULT);
    $status = 'unsubmitted';
    
    $stmt->bind_param("ssssss", $name, $phone, $email, $username, $password_hash, $status);
    
    $duplicate_success = true;
    try {
        $stmt->execute();
    } catch (Exception $e) {
        $duplicate_success = false;
    }
    
    // Clean up
    $db->query("DELETE FROM customer WHERE cust_id = {$customer1['cust_id']}");
    
    // The test should fail if we could create a customer with duplicate email
    if ($duplicate_success) {
        return "Unique constraint failed - shouldn't be able to create duplicate email";
    }
    
    return true;
}

// Test data integrity with transactions
function testTransactionIntegrity() {
    $db = getTestDB();
    
    // Start a transaction
    $db->begin_transaction();
    
    try {
        // Create customer
        $customer = createTestCustomer($db, '_transaction');
        
        // Create car
        $car = createTestCar($db, '_transaction');
        
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
        
        // Verify booking exists within transaction
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM booking WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists_in_transaction = $result->fetch_assoc()['count'] > 0;
        
        // Rollback transaction
        $db->rollback();
        
        // Verify booking doesn't exist after rollback
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM booking WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists_after_rollback = $result->fetch_assoc()['count'] > 0;
        
        if (!$exists_in_transaction) {
            return "Booking should exist within transaction";
        }
        
        if ($exists_after_rollback) {
            // Clean up if rollback didn't work
            $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
            return "Rollback failed - booking still exists";
        }
        
        return true;
    } catch (Exception $e) {
        $db->rollback();
        return "Transaction test failed: " . $e->getMessage();
    }
}

// Run database tests
runTestGroup("Database Integrity Tests", [
    "Foreign Key Constraints" => 'testForeignKeyConstraints',
    "Unique Constraints" => 'testUniqueConstraints',
    "Transaction Integrity" => 'testTransactionIntegrity'
]);
?>