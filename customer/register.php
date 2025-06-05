<?php

include '../connect.php';

session_start();
$errors = $_SESSION['registration_errors'] ?? [];
unset($_SESSION['registration_errors']);
?>
<link rel="stylesheet" href="/assets/css/style.css">

<div class="register-container">
  <div class="register-box">
    <h2>Create Your Customer Account</h2>
    <?php if ($errors): ?>
      <div class="error-messages">
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <form action="registerprocess.php" method="post" class="register-form" enctype="multipart/form-data">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required>
      <label for="phone_no">Phone Number</label>
      <input type="text" id="phone_no" name="phone_no" required>
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required>
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
      <label for="confirm_password">Confirm Password</label>
      <input type="password" id="confirm_password" name="confirm_password" required>
      <label for="age">Age</label>
      <input type="number" id="age" name="age" min="18">
      <button type="submit" class="register-btn">Register</button>
    </form>
    <div class="login-link">
      Already have an account? <a href="/index.php">Login here</a>.
    </div>
  </div>
</div>

<?php include '../includes/footer.php'; ?>
