<?php
include 'connect.php';
include 'includes/header.php';
// No navbar for a clean landing page
?>
<link rel="stylesheet" href="/assets/css/style.css">

<div class="landing-container">
  <!-- Left Banner -->
  <div class="landing-banner">
    <div class="banner-content">
      <h1>Your Car,<br>Everywhere and Everytime</h1>
      <div class="banner-logo-below-title">
        <img src="/assets/images/Timeless_logo.png" alt="Timeless Car Rental Logo">
      </div>
      <p class="banner-desc">Book your car with ease!</p>
      <a href="/customer/register.php" class="register-link">Register Now &rarr;</a>
    </div>
  </div>

  <!-- Right Login Box -->
  <div class="landing-login-box">
    <h2>Welcome back.</h2>
    <p class="login-desc">Enter your email and password to login.</p>
    <form action="customer/login.php" method="post" class="login-form">
      <input type="text" name="username" placeholder="Email or Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <label class="remember-me"><input type="checkbox" name="remember"> Remember me</label>
      <button type="submit" class="login-btn">Log In</button>
      <div class="forgot-link"><a href="#">Forgot your password?</a></div>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>