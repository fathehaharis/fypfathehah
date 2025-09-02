<?php
/**
 * Customer Module Tests
 * Note: This file should not be accessed directly. Run through test_runner.php
 */

// Prevent direct access
if (!function_exists('runTestGroup')) {
    die("This file should not be accessed directly. Run tests through test_runner.php");
}

// Customer registration test
function testCustomerRegistration() {
    $db = getTestDB();
    $unique = time();
    $username = 'test_reg_' . $unique;
    $email = $username . '@example.com';
    $password = 'SecurePass123';
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Check if username exists
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM customer WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    
    if ($exists) {
        return "Username or email already exists";
    }
    
    // Insert new customer
    $stmt = $db->prepare("
        INSERT INTO customer 
        (full_name, phone_no, email, username, password, profile_status) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $name = 'Registration Test';
    $phone = '0123456789';
    $status = 'unsubmitted';
    
    $stmt->bind_param("ssssss", $name, $phone, $email, $username, $password_hash, $status);
    $success = $stmt->execute();
    $cust_id = $db->insert_id;
    
    if (!$success) {
        return "Failed to insert customer";
    }
    
    // Verify user exists
    $stmt = $db->prepare("SELECT * FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $cust_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();
    
    if (!$customer) {
        return "Could not find created customer";
    }
    
    // Clean up
    $stmt = $db->prepare("DELETE FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $cust_id);
    $stmt->execute();
    
    return true;
}

// Customer login test
function testCustomerLogin() {
    $db = getTestDB();
    $customer = createTestCustomer($db, '_login');
    
    // Try login with correct password
    $stmt = $db->prepare("SELECT * FROM customer WHERE username = ?");
    $stmt->bind_param("s", $customer['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if (!$user) {
        return "Could not find test user";
    }
    
    $login_success = password_verify($customer['password'], $user['password']);
    
    if (!$login_success) {
        return "Password verification failed";
    }
    
    // Try login with wrong password
    $wrong_login = password_verify('WrongPassword', $user['password']);
    
    if ($wrong_login) {
        return "Invalid password should not work";
    }
    
    // Clean up
    $stmt = $db->prepare("DELETE FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $customer['cust_id']);
    $stmt->execute();
    
    return true;
}

// Profile update test
function testProfileUpdate() {
    $db = getTestDB();
    $customer = createTestCustomer($db, '_profile');
    
    // Update customer profile
    $new_name = "Updated Name";
    $new_phone = "0187654321";
    
    $stmt = $db->prepare("
        UPDATE customer 
        SET full_name = ?, phone_no = ? 
        WHERE cust_id = ?
    ");
    $stmt->bind_param("ssi", $new_name, $new_phone, $customer['cust_id']);
    $stmt->execute();
    
    // Verify update
    $stmt = $db->prepare("SELECT full_name, phone_no FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $customer['cust_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $updated = $result->fetch_assoc();
    
    // Clean up
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    if ($updated['full_name'] !== $new_name || $updated['phone_no'] !== $new_phone) {
        return "Profile did not update correctly";
    }
    
    return true;
}

// Run customer tests
runTestGroup("Customer Module Tests", [
    "Customer Registration" => 'testCustomerRegistration',
    "Customer Login" => 'testCustomerLogin',
    "Profile Update" => 'testProfileUpdate'
]);
?>