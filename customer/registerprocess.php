<?php
include '../connect.php';
session_start();

function suggest_username($base, $conn) {
    $suggested = $base;
    $i = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT cust_id FROM customer WHERE username = ?");
        $stmt->bind_param("s", $suggested);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows == 0) {
            $stmt->close();
            return $suggested;
        }
        $stmt->close();
        $suggested = $base . $i;
        $i++;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST['username'] ?? '');
    $phone_no = trim($_POST['phone_no'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];
    $suggested_username = "";

    // Validation
    if (!$username || !$phone_no || !$email || !$password || !$confirm_password) {
        $errors[] = "All fields are required.";
    }
    // Email format validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    // Malaysian phone number format validation (only 10 or 11 digits, starts with 01)
    $phone_sanitized = str_replace(['-', ' '], '', $phone_no);
    if (!preg_match('/^01\d{8,9}$/', $phone_sanitized) || strlen($phone_sanitized) < 10 || strlen($phone_sanitized) > 11) {
        $errors[] = "Invalid Malaysian phone number. Must be only 10 or 11 digits, starting with 01. Example: 0123456789";
    }
    // Password match
    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // Password policy enforcement
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter.";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number.";
    }
    if (!preg_match('/[\W_]/', $password)) {
        $errors[] = "Password must contain at least one special character.";
    }

    // Check for unique username/email
    if (empty($errors)) {
        $username_exists = false;
        $email_exists = false;

        $stmt = $conn->prepare("SELECT username, email FROM customer WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $stmt->bind_result($existing_username, $existing_email);
            while ($stmt->fetch()) {
                if ($existing_username === $username) {
                    $username_exists = true;
                }
                if ($existing_email === $email) {
                    $email_exists = true;
                }
            }
        }
        $stmt->close();

        if ($username_exists) {
            $suggested_username = suggest_username($username, $conn);
            $errors[] = "Username already exists. You can use: <strong>" . htmlspecialchars($suggested_username) . "</strong>";
        }
        if ($email_exists) {
            $errors[] = "An account with this email already exists.";
        }
    }

    // Insert new customer if no errors
    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO customer (username, phone_no, email, password) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $username, $phone_no, $email, $hashed_password);
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
    if (!empty($suggested_username)) {
        $_SESSION['suggested_username'] = $suggested_username;
    }
    header("Location: register.php");
    exit;
} else {
    header("Location: register.php");
    exit;
}
?>