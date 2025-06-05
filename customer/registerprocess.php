<?php
include '../connect.php';
session_start();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $phone_no = trim($_POST['phone_no'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $age = trim($_POST['age'] ?? '');

    $errors = [];

    // Validation
    if (!$username || !$phone_no || !$email || !$password || !$confirm_password) {
        $errors[] = "All fields are required.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    if ($age && $age < 18) {
        $errors[] = "You must be at least 18 years old.";
    }

    // Check for unique username/email
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT cust_id FROM customer WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = "Username or email already exists.";
        }
        $stmt->close();
    }

    // Insert new customer if no errors
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO customer (username, phone_no, email, password, age) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssi", $username, $phone_no, $email, $hashed_password, $age);
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Registration successful! Please log in.";
            header("Location: dashboard.php");
            exit;
        } else {
            $errors[] = "Registration failed, please try again.";
        }
        $stmt->close();
    }

    $_SESSION['registration_errors'] = $errors;
    header("Location: register.php");
    exit;
} else {
    header("Location: register.php");
    exit;
}
?>