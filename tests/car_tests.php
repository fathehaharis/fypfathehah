<?php
/**
 * Car Module Tests
 * Note: This file should not be accessed directly. Run through test_runner.php
 */

// Prevent direct access
if (!function_exists('runTestGroup')) {
    die("This file should not be accessed directly. Run through test_runner.php");
}

// Test car search function
function testCarSearch() {
    $db = getTestDB();
    
    // Create a test car with specific brand for searching
    $plate = 'TEST' . rand(100, 999) . '_search';
    
    $stmt = $db->prepare("
        INSERT INTO car 
        (car_brand, car_model, year, color, mileage, plate_no, 
         transmission, seat_capacity, status, daily_rate) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $brand = 'Toyota';  // Explicitly set brand to search for
    $model = 'Camry';
    $year = 2022;
    $color = 'Black';
    $mileage = 5000;
    $transmission = 'Automatic';
    $seats = 5;
    $status = 'available';
    $rate = 150.00;
    
    $stmt->bind_param("ssisssisds", 
        $brand, $model, $year, $color, $mileage, $plate,
        $transmission, $seats, $status, $rate
    );
    $stmt->execute();
    $car_id = $db->insert_id;
    
    if (!$car_id) {
        return "Failed to create test car";
    }
    
    // Verify car was created
    $stmt = $db->prepare("SELECT car_id FROM car WHERE car_id = ?");
    $stmt->bind_param("i", $car_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        return "Test car was not created properly";
    }
    
    // Search for the car by brand with case-insensitive search
    $search_brand = 'perodua';  // Using lowercase to test case insensitivity
    $stmt = $db->prepare("
        SELECT * FROM car 
        WHERE LOWER(car_brand) = LOWER(?) AND status = 'available'
    ");
    $stmt->bind_param("s", $search_brand);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Debug info - count how many results were found
    $result_count = $result->num_rows;
    
    $found_car = false;
    while ($row = $result->fetch_assoc()) {
        if ($row['car_id'] == $car_id) {
            $found_car = true;
            break;
        }
    }
    
    // Clean up
    $db->query("DELETE FROM car WHERE car_id = {$car_id}");
    
    if ($result_count === 0) {
        return "Search query found no cars with brand '$search_brand'";
    }
    
    if (!$found_car) {
        return "Car with ID $car_id was not found in search results (found $result_count other cars)";
    }
    
    return true;
}

// Test car availability
function testCarAvailability() {
    $db = getTestDB();
    $car = createTestCar($db, '_avail');
    
    // Create a booking for this car
    $customer = createTestCustomer($db, '_car_avail');
    
    $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
    $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
    
    $stmt = $db->prepare("
        INSERT INTO booking 
        (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
         daily_rate, total_price, security_deposit, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $day_count = 3;
    $daily_rate = 150.00;
    $total_price = $day_count * $daily_rate + 100.00;
    $security_deposit = 100.00;
    $status = 'confirmed';
    
    $stmt->bind_param("iissiddds", 
        $customer['cust_id'], $car['car_id'], $pickup_date, $return_date,
        $day_count, $daily_rate, $total_price, $security_deposit, $status
    );
    $stmt->execute();
    $booking_id = $db->insert_id;
    
    // Now check if the car is available during that period
    $check_pickup = date('Y-m-d H:i:s', strtotime('+2 days'));
    $check_return = date('Y-m-d H:i:s', strtotime('+3 days'));
    
    $stmt = $db->prepare("
        SELECT c.car_id FROM car c
        WHERE c.car_id = ?
        AND c.car_id NOT IN (
            SELECT b.car_id
            FROM booking b
            WHERE b.status IN ('pending', 'confirmed', 'approved')
            AND (
                (b.pickup_datetime <= ? AND b.return_datetime >= ?) OR
                (b.pickup_datetime <= ? AND b.return_datetime >= ?) OR
                (b.pickup_datetime >= ? AND b.return_datetime <= ?)
            )
        )
    ");
    
    $stmt->bind_param("issssss", 
        $car['car_id'], 
        $check_pickup, $check_pickup,
        $check_return, $check_return,
        $check_pickup, $check_return
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    $is_available = $result->num_rows > 0;
    
    // Clean up
    $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
    $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    if ($is_available) {
        return "Car should NOT be available during booked period";
    }
    
    return true;
}

// Test car data validation
function testCarDataValidation() {
    $db = getTestDB();
    
    // Test duplicate plate number
    $car1 = createTestCar($db, '_dup');
    
    // Try to create another car with same plate
    $stmt = $db->prepare("
        INSERT INTO car 
        (car_brand, car_model, year, color, mileage, plate_no, 
         transmission, seat_capacity, status, daily_rate) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $brand = 'Honda';
    $model = 'Civic';
    $year = 2021;
    $color = 'White';
    $mileage = 8000;
    $plate = $car1['plate_no']; // Same plate!
    $transmission = 'Automatic';
    $seats = 5;
    $status = 'available';
    $rate = 120.00;
    
    $stmt->bind_param("ssisssisds", 
        $brand, $model, $year, $color, $mileage, $plate,
        $transmission, $seats, $status, $rate
    );
    
    $success = true;
    try {
        $stmt->execute();
    } catch (Exception $e) {
        $success = false;
    }
    
    // Clean up
    $db->query("DELETE FROM car WHERE car_id = {$car1['car_id']}");
    
    if ($success) {
        return "Duplicate plate number should not be allowed";
    }
    
    return true;
}

// Run car tests
runTestGroup("Car Module Tests", [
    "Car Search" => 'testCarSearch',
    "Car Availability Check" => 'testCarAvailability',
    "Car Data Validation" => 'testCarDataValidation'
]);
?>