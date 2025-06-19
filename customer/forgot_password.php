<?php
session_start();
$success = $_SESSION['forgot_success'] ?? '';
$error = $_SESSION['forgot_error'] ?? '';
unset($_SESSION['forgot_success'], $_SESSION['forgot_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password</title>
  <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    body { background:#f3f6fd; }
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
    .register-form-container input[type="email"] {
      width: 100%;
      padding: 11px 12px;
      border-radius: 6px;
      border: 1px solid #c2cbe0;
      background: #f7faff;
      margin-bottom: 20px;
      font-size: 16px;
      transition: border 0.2s;
    }
    .register-form-container input[type="email"]:focus {
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
    <h2>Forgot Password</h2>
    <?php if ($success): ?>
      <div class="success-message"><?= $success ?></div>
    <?php elseif ($error): ?>
      <div class="error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" action="send_reset.php" autocomplete="off" style="width:100%;">
      <label for="email">Email Address</label>
      <input type="email" name="email" id="email" placeholder="Enter your registered email" required>
      <button type="submit" class="register-btn">Send Code</button>
    </form>
    <a href="/index.php" class="back-link">&larr; Back to Login</a>
  </div>
</body>
</html>