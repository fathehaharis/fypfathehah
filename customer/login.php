<?php
include '../connect.php';
session_start();

$errors = [];

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $errors[] = "Please enter both username/email and password.";
    } else {
        // Check if user exists by username or email
        $stmt = $conn->prepare("SELECT cust_id, username, password FROM customer WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 1) {
            $stmt->bind_result($cust_id, $db_username, $db_password);
            $stmt->fetch();

            if (password_verify($password, $db_password)) {
                // Password correct, log in user
                $_SESSION['cust_id'] = $cust_id;
                $_SESSION['username'] = $db_username;
                header("Location: dashboard.php"); // Change to your dashboard page
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