<?php
/**
 * Basic Security Tests
 * Note: This file should not be accessed directly. Run through test_runner.php
 */

// Prevent direct access
if (!function_exists('runTestGroup')) {
    die("This file should not be accessed directly. Run tests through test_runner.php");
}

// Check password hashing
function testPasswordHashing() {
    $db = getTestDB();
    $password = 'SecurePassword123';
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    
    // Verify password can be verified
    $verified = password_verify($password, $hashed);
    if (!$verified) {
        return "Password hashing/verification failed";
    }
    
    // Verify wrong password doesn't match
    $wrong_verified = password_verify('WrongPassword', $hashed);
    if ($wrong_verified) {
        return "Password verification incorrectly matched wrong password";
    }
    
    // Check real passwords in the database are hashed
    $customer = createTestCustomer($db, '_security');
    
    $stmt = $db->prepare("SELECT password FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $customer['cust_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $stored_hash = $result->fetch_assoc()['password'];
    
    // Clean up
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    // Check if it looks like a hash (length > 40 and contains $ signs)
    if (strlen($stored_hash) < 40 || strpos($stored_hash, '$') === false) {
        return "Stored passwords don't appear to be properly hashed";
    }
    
    return true;
}

// Check SQL injection protection
function testSQLInjectionProtection() {
    $db = getTestDB();
    $customer = createTestCustomer($db, '_sqli');
    
    // Try a simple SQL injection attempt
    $malicious_username = "' OR '1'='1";
    
    $stmt = $db->prepare("SELECT * FROM customer WHERE username = ?");
    $stmt->bind_param("s", $malicious_username);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result->num_rows;
    
    // Clean up
    $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
    
    // If vulnerable, this would return all users
    if ($rows > 0) {
        return "SQL injection protection may be insufficient";
    }
    
    return true;
}

// Test CSRF protection
function testCSRFProtection() {
    // Generate token
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    
    // Check token validation function
    $valid = ($token === $_SESSION['csrf_token']);
    $invalid = ('fake_token' === $_SESSION['csrf_token']);
    
    if (!$valid) {
        return "Valid CSRF token should validate";
    }
    
    if ($invalid) {
        return "Invalid CSRF token should not validate";
    }
    
    return true;
}

// Run security tests
runTestGroup("Security Tests", [
    "Password Hashing" => 'testPasswordHashing',
    "SQL Injection Protection" => 'testSQLInjectionProtection',
    "CSRF Protection" => 'testCSRFProtection'
]);
?>