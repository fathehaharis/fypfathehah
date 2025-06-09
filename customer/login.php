<?php
include '../connect.php';
session_start();

$errors = [];

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username_or_email = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username_or_email || !$password) {
        $errors[] = "Please enter both username/email and password.";
    } else {
        // Check if user exists by username or email
        $stmt = $conn->prepare("SELECT cust_id, username, password FROM customer WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username_or_email, $username_or_email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                // Password correct, log in user
                $_SESSION['cust_id'] = $user['cust_id'];
                $_SESSION['username'] = $user['username'];
                header("Location: dashboard.php"); // Adjust path if needed
                exit;
            } else {
                $errors[] = "Incorrect password.";
            }
        } else {
            $errors[] = "No user found with that username or email.";
        }
        $stmt->close();
    }
}

// Show error messages if any
if (!empty($errors)) {
    $_SESSION['login_errors'] = $errors;
    header("Location: /index.php");
    exit;
}
?>