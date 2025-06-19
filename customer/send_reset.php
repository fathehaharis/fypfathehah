<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ...rest of your code
require '../vendor/autoload.php'; // Composer autoload for PHPMailer
include '../connect.php';
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$email = trim($_POST['email'] ?? '');
if (!$email) {
    $_SESSION['forgot_error'] = "Please enter your email address.";
    header("Location: forgot_password.php");
    exit;
}

$stmt = $conn->prepare("SELECT cust_id FROM customer WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT); // 6-digit code
    $expires = date("Y-m-d H:i:s", strtotime("+15 minutes"));
    $update = $conn->prepare("UPDATE customer SET reset_code=?, reset_code_expire=? WHERE cust_id=?");
    $update->bind_param("ssi", $code, $expires, $user['cust_id']);
    $update->execute();

    // Send email using PHPMailer
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // Your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'fathehaharis69@gmail.com'; // Your SMTP username
        $mail->Password   = 'cuel ijeu lzqv vsgv';   // Your SMTP password or app password
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('no-reply@timelesscarrental.com', 'Timeless Car Rental');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = 'Your Password Reset Code';
        $mail->Body    = "
            <h2>Password Reset Code</h2>
            <p>Your password reset code is: <strong style='font-size:1.3em;'>$code</strong></p>
            <p>This code will expire in 15 minutes.</p>
        ";
        $mail->AltBody = "Your password reset code is: $code\nThis code will expire in 15 minutes.";

        $mail->send();
        $_SESSION['reset_email'] = $email;
        header("Location: verify_code.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['forgot_error'] = "Could not send email. Mailer Error: {$mail->ErrorInfo}";
        header("Location: forgot_password.php");
        exit;
    }
} else {
    $_SESSION['forgot_error'] = "No account found with that email address.";
    header("Location: forgot_password.php");
    exit;
}
?>