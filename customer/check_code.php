<?php
include '../connect.php';
session_start();

$email = $_SESSION['reset_email'] ?? '';
$code = trim($_POST['code'] ?? '');

if (!$email || !$code) {
    $_SESSION['code_error'] = "Invalid attempt.";
    header("Location: verify_code.php");
    exit;
}

$stmt = $conn->prepare("SELECT cust_id, reset_code_expire FROM customer WHERE email=? AND reset_code=?");
$stmt->bind_param("ss", $email, $code);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    if (strtotime($user['reset_code_expire']) < time()) {
        $_SESSION['code_error'] = "Code expired. Please request a new one.";
        header("Location: forgot_password.php");
        exit;
    }
    // Success! Allow password reset
    $_SESSION['allow_reset'] = true;
    header("Location: reset_password.php");
    exit;
} else {
    $_SESSION['code_error'] = "Incorrect code. Please try again.";
    header("Location: verify_code.php");
    exit;
}
?>