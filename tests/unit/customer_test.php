<?php
require_once 'test_setup.php';

class CustomerUnitTest {
    // Test customer registration validation
    public function testRegistrationValidation() {
        $db = getTestDB();
        
        // Test valid data
        $valid_data = [
            'full_name' => 'Test User',
            'phone_no' => '0123456789',
            'email' => 'valid_email@example.com',
            'username' => 'valid_user_' . time(),
            'password' => 'ValidPass123'
        ];
        
        // Validate email format
        $valid_email = filter_var($valid_data['email'], FILTER_VALIDATE_EMAIL);
        
        // Validate password strength
        $password_pattern = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/';
        $valid_password = preg_match($password_pattern, $valid_data['password']);
        
        // Validate username uniqueness
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM customer WHERE username = ?");
        $stmt->bind_param("s", $valid_data['username']);
        $stmt->execute();
        $result = $stmt->get_result();
        $username_unique = ($result->fetch_assoc()['count'] == 0);
        
        if (!$valid_email) {
            return "Email validation failed";
        }
        
        if (!$valid_password) {
            return "Password validation failed";
        }
        
        if (!$username_unique) {
            return "Username uniqueness check failed";
        }
        
        return true;
    }
    
    // Test login authentication
    public function testLoginAuthentication() {
        $db = getTestDB();
        $customer = createTestCustomer($db, '_login_test');
        
        // Test valid login
        $correct_login = $this->authenticateUser($db, $customer['username'], $customer['password']);
        
        // Test invalid password
        $wrong_password_login = $this->authenticateUser($db, $customer['username'], 'WrongPassword');
        
        // Test invalid username
        $wrong_username_login = $this->authenticateUser($db, 'nonexistent_user', 'Password123');
        
        // Clean up
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        
        if (!$correct_login) {
            return "Valid login failed";
        }
        
        if ($wrong_password_login) {
            return "Invalid password login should fail";
        }
        
        if ($wrong_username_login) {
            return "Nonexistent user login should fail";
        }
        
        return true;
    }
    
    // Helper function for authentication
    private function authenticateUser($db, $username, $password) {
        $stmt = $db->prepare("SELECT * FROM customer WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            return false;
        }
        
        $user = $result->fetch_assoc();
        return password_verify($password, $user['password']);
    }
    
    // Test password reset functionality
    public function testPasswordReset() {
        $db = getTestDB();
        $customer = createTestCustomer($db, '_reset_test');
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Store reset token
        $stmt = $db->prepare("
            UPDATE customer 
            SET reset_token = ?, reset_token_expiry = ? 
            WHERE cust_id = ?
        ");
        $stmt->bind_param("ssi", $token, $token_expiry, $customer['cust_id']);
        $token_stored = $stmt->execute();
        
        // Verify token is valid
        $stmt = $db->prepare("
            SELECT cust_id FROM customer 
            WHERE reset_token = ? AND reset_token_expiry > NOW()
        ");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $token_valid = ($result->num_rows > 0);
        
        // Update password
        $new_password = 'NewPassword456';
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("
            UPDATE customer 
            SET password = ?, reset_token = NULL, reset_token_expiry = NULL 
            WHERE reset_token = ?
        ");
        $stmt->bind_param("ss", $new_password_hash, $token);
        $password_updated = $stmt->execute();
        
        // Verify new password works
        $stmt = $db->prepare("SELECT password FROM customer WHERE cust_id = ?");
        $stmt->bind_param("i", $customer['cust_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $stored_hash = $result->fetch_assoc()['password'];
        $new_password_works = password_verify($new_password, $stored_hash);
        
        // Clean up
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        
        if (!$token_stored || !$token_valid || !$password_updated || !$new_password_works) {
            return "Password reset functionality failed";
        }
        
        return true;
    }
}

// Run tests
$test = new CustomerUnitTest();
echo "Registration Validation: " . ($test->testRegistrationValidation() === true ? "PASSED" : "FAILED") . "\n";
echo "Login Authentication: " . ($test->testLoginAuthentication() === true ? "PASSED" : "FAILED") . "\n";
echo "Password Reset: " . ($test->testPasswordReset() === true ? "PASSED" : "FAILED") . "\n";
?>