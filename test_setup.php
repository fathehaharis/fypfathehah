<?php
// Set up test environment
function getTestDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli('localhost', 'root', '', 'timelesscarrental_test');
        if ($conn->connect_error) {
            die("Connection failed: " . $conn->connect_error);
        }
    }
    return $conn;
}

// Helper functions for creating test data
function createTestCustomer($db, $unique = '') {
    $username = 'test_customer' . $unique;
    $email = $username . '@example.com';
    $password_hash = password_hash('Password123', PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("
        INSERT INTO customer 
        (full_name, phone_no, email, username, password, profile_status) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $name = 'Test Customer ' . $unique;
    $phone = '01234567' . rand(10, 99);
    $status = 'verified';
    
    $stmt->bind_param("ssssss", $name, $phone, $email, $username, $password_hash, $status);
    $stmt->execute();
    return [
        'cust_id' => $db->insert_id,
        'username' => $username,
        'email' => $email,
        'password' => 'Password123'
    ];
}

function createTestCar($db, $suffix = '') {
    $plate = 'TEST' . rand(100, 999) . $suffix;
    
    $stmt = $db->prepare("
        INSERT INTO car 
        (car_brand, car_model, year, color, mileage, plate_no, 
         transmission, seat_capacity, status, daily_rate) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $brand = 'Toyota';
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
    
    return [
        'car_id' => $db->insert_id,
        'plate_no' => $plate,
        'daily_rate' => $rate
    ];
}
?>