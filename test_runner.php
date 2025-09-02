<?php
/**
 * Simple Test Runner for TimeLess Car Rental
 * Created by: fathehaharis
 * Date: 2025-09-02
 */

// Start time tracking
$start_time = microtime(true);
require_once 'tests/customer_tests.php';
require_once 'tests/car_tests.php';
require_once 'tests/booking_tests.php';
require_once 'tests/database_tests.php';
require_once 'tests/security_tests.php';
// Database connection for testing
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

// Helper function to run a test
function runTest($name, $callback) {
    if (php_sapi_name() == 'cli') {
        // Command line output
        echo str_pad($name, 40, ".");
    } else {
        // Browser output
        echo "<div style='font-family: monospace; margin: 5px 0;'>";
        echo "<span style='display: inline-block; width: 400px;'>" . htmlspecialchars($name) . "</span>";
    }
    
    try {
        $result = $callback();
        if ($result === true) {
            if (php_sapi_name() == 'cli') {
                echo " PASSED\n";
            } else {
                echo "<span style='color: green; font-weight: bold;'>PASSED</span></div>";
            }
            return true;
        } else {
            if (php_sapi_name() == 'cli') {
                echo " FAILED";
                if (is_string($result)) {
                    echo " ($result)";
                }
                echo "\n";
            } else {
                echo "<span style='color: red; font-weight: bold;'>FAILED</span>";
                if (is_string($result)) {
                    echo " <span style='color: #666;'>(" . htmlspecialchars($result) . ")</span>";
                }
                echo "</div>";
            }
            return false;
        }
    } catch (Exception $e) {
        if (php_sapi_name() == 'cli') {
            echo " ERROR: " . $e->getMessage() . "\n";
        } else {
            echo "<span style='color: red; font-weight: bold;'>ERROR:</span> " . 
                 htmlspecialchars($e->getMessage()) . "</div>";
        }
        return false;
    }
}

// Store test results
$results = [
    'passed' => 0,
    'failed' => 0,
    'total' => 0
];

// Run a specific test group
function runTestGroup($group_name, $tests) {
    global $results;
    
    if (php_sapi_name() == 'cli') {
        echo "\n=== $group_name ===\n";
    } else {
        echo "<h2 style='margin-top: 20px; border-bottom: 1px solid #ccc;'>" . 
             htmlspecialchars($group_name) . "</h2>";
    }
    
    foreach ($tests as $name => $test) {
        $results['total']++;
        if (runTest($name, $test)) {
            $results['passed']++;
        } else {
            $results['failed']++;
        }
    }
}

// Create helper to clean up test data
function cleanupTestData() {
    $db = getTestDB();
    // List tables to clean in reverse order of dependencies
    $tables = ['payment', 'service', 'booking', 'car', 'customer', 'delivery_staff'];
    
    foreach ($tables as $table) {
        $db->query("DELETE FROM $table WHERE 1=1");
    }
}

// Add HTML header if running in browser
if (php_sapi_name() != 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>TimeLess Car Rental - Test Runner</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #333; }
            .summary { background: #f5f5f5; padding: 10px; margin: 20px 0; border-radius: 5px; }
            .passed { color: green; }
            .failed { color: red; }
        </style>
    </head>
    <body>";
}

// Begin tests
if (php_sapi_name() == 'cli') {
    echo "=================================================\n";
    echo "TIMELESS CAR RENTAL SYSTEM - TEST RUNNER\n";
    echo "Date: " . date('Y-m-d H:i:s') . "\n";
    echo "User: fathehaharis\n";
    echo "=================================================\n";
} else {
    echo "<h1>TimeLess Car Rental System - Test Runner</h1>";
    echo "<div>Date: " . date('Y-m-d H:i:s') . "</div>";
    echo "<div>User: fathehaharis</div>";
    echo "<hr>";
}

// Create functions for test car and customer
// Test car creation function
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

// Create a test customer
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

// Include test groups
require_once 'tests/customer_tests.php';
require_once 'tests/car_tests.php';
require_once 'tests/booking_tests.php';

// Clean up any test data
cleanupTestData();

// Show summary
if (php_sapi_name() == 'cli') {
    echo "\n=================================================\n";
    echo "TEST SUMMARY\n";
    echo "Passed: {$results['passed']}\n";
    echo "Failed: {$results['failed']}\n";
    echo "Total: {$results['total']}\n";
    echo "Success rate: " . round(($results['passed'] / $results['total']) * 100, 2) . "%\n";
    echo "Time taken: " . round(microtime(true) - $start_time, 2) . " seconds\n";
    echo "=================================================\n";
} else {
    echo "<div class='summary'>";
    echo "<h2>Test Summary</h2>";
    echo "<p><span class='passed'>Passed: {$results['passed']}</span><br>";
    echo "<span class='failed'>Failed: {$results['failed']}</span><br>";
    echo "Total: {$results['total']}<br>";
    echo "Success rate: " . round(($results['passed'] / max(1, $results['total'])) * 100, 2) . "%<br>";
    echo "Time taken: " . round(microtime(true) - $start_time, 2) . " seconds</p>";
    echo "</div>";
    echo "</body></html>";
}
?>