<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['reset_email']) || !isset($_SESSION['allow_reset'])) {
    header("Location: forgot_password.php");
    exit;
}

$email = $_SESSION['reset_email'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!$new_password || !$confirm) {
        $error = "Please fill in all fields.";
    } elseif ($new_password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters.";
    } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE customer SET password=?, reset_code=NULL, reset_code_expire=NULL WHERE email=?");
        $update->bind_param("ss", $hash, $email);
        $update->execute();
        session_unset();
        $success = "Your password has been reset. <a href='/index.php'>Login now</a>.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reset Password</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
  <style>
    body { background: #f3f6fd; }
    .register-form-container {
      max-width: 450px;
      margin: 60px auto 0 auto;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      padding: 32px 40px 26px 40px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .register-form-container h2 {
      margin-bottom: 18px;
      color: #233b6c;
      font-weight: 700;
      font-size: 2rem;
    }
    .register-form-container label {
      width: 100%;
      margin-bottom: 7px;
      color: #233b6c;
      font-weight: 500;
      text-align: left;
    }
    .register-form-container input[type="password"] {
      width: 100%;
      padding: 11px 12px;
      border-radius: 6px;
      border: 1px solid #c2cbe0;
      background: #f7faff;
      margin-bottom: 20px;
      font-size: 16px;
      transition: border 0.2s;
    }
    .register-form-container input[type="password"]:focus {
      border-color: #3358d4;
      outline: none;
      background: #fff;
    }
    .register-btn {
      width: 100%;
      background: #3358d4;
      color: #fff;
      border: none;
      border-radius: 6px;
      padding: 12px 0;
      font-size: 17px;
      font-weight: 600;
      cursor: pointer;
      margin-top: 2px;
      transition: background 0.2s;
      letter-spacing: 0.5px;
    }
    .register-btn:hover {
      background: #1f3994;
    }
    .success-message, .error-message {
      padding: 10px 14px;
      border-radius: 5px;
      font-size: 15px;
      margin-bottom: 16px;
      text-align: center;
      width: 100%;
    }
    .success-message { background: #e6ffed; color: #19692c; border: 1px solid #8be8a4;}
    .error-message { background: #ffebea; color: #a32020; border: 1px solid #ffb2b2;}
    .back-link {
      display: block;
      text-align: center;
      margin-top: 18px;
      color: #3358d4;
      text-decoration: none;
      font-size: 15px;
      transition: color 0.2s;
    }
    .back-link:hover { color: #1f3994; text-decoration: underline; }
    @media (max-width: 500px) {
      .register-form-container {
        padding: 18px 4vw 18px 4vw;
        max-width: 98vw;
      }
    }
  </style>
</head>
<body>
  <div class="register-form-container">
    <h2>Set New Password</h2>
    <?php if ($error): ?>
      <div class="error-message"><?= $error ?></div>
    <?php elseif ($success): ?>
      <div class="success-message"><?= $success ?></div>
    <?php endif; ?>
    <?php if (!$success): ?>
      <form method="post" style="width:100%;">
        <label for="password">New Password</label>
        <input type="password" name="password" id="password" placeholder="Enter new password" required>
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" name="confirm_password" id="confirm_password" placeholder="Re-enter new password" required>
        <button type="submit" class="register-btn">Reset Password</button>
      </form>
    <?php endif; ?>
    <a href="/index.php" class="back-link">&larr; Back to Login</a>
  </div>
</body>
</html>