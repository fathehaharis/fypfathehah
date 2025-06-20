<?php
session_start();
if (isset($_SESSION['cust_id'])) {
    header("Location: /customer/dashboard.php");
    exit;
}
include 'connect.php';
?>
<link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<title>TimeLess Car Rental System</title>

<div class="landing-container">
  <!-- Left Banner -->
  <div class="landing-banner">
    <div class="banner-content">
      <h1>Your Car,<br>Everywhere and Everytime</h1>
      <div class="banner-logo-below-title">
        <img src="/assets/images/TimeLess_logo.png" alt="Timeless Car Rental Logo">
      </div>
      <p class="banner-desc">Book your car with ease!</p>
      <a href="/customer/register.php" class="register-link">Register Now &rarr;</a>
    </div>
  </div>

  <!-- Right Login Box -->
  <div class="landing-login-box">
    <h2>Welcome back.</h2>
    <p class="login-desc">Enter your email and password to login.</p>
    <?php
    // Display login errors if any
    if (!empty($_SESSION['login_errors'])) {
        echo '<div class="error-messages">';
        foreach ($_SESSION['login_errors'] as $error) {
            echo '<p>' . htmlspecialchars($error) . '</p>';
        }
        echo '</div>';
        unset($_SESSION['login_errors']);
    }
    ?>
    <form action="customer/login.php" method="post" class="login-form">
      <input type="text" name="username" placeholder="Email or Username" required>
      <div class="form-group">
        <div class="password-input-wrapper">
          <input type="password" name="password" id="password-input" placeholder="Password" required>
          <span id="togglePassword" class="eye-icon" tabindex="0">
            <!-- SVG Eye Icon (open and closed) -->
            <svg id="eyeOpen" xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="#888" stroke-width="2">
              <ellipse cx="12" cy="12" rx="8" ry="5"/>
              <circle cx="12" cy="12" r="2"/>
            </svg>
            <svg id="eyeClosed" xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="#888" stroke-width="2" style="display:none;">
              <ellipse cx="12" cy="12" rx="8" ry="5"/>
              <circle cx="12" cy="12" r="2"/>
              <line x1="5" y1="19" x2="19" y2="5" stroke="#888" stroke-width="2"/>
            </svg>
          </span>
        </div>
      </div>
      <label class="remember-me"><input type="checkbox" name="remember"> Remember me</label>
      <button type="submit" class="login-btn">Log In</button>
      <div class="forgot-link"><a href="customer/forgot_password.php">Forgot your password?</a></div>
    </form>
  </div>
</div>

<style>
.form-group {
  margin-bottom: 20px;
}
.password-input-wrapper {
  position: relative;
  background: #fafafa;
  border: 1.5px solid #e1e1e1;
  border-radius: 8px;
  padding: 0;
  display: flex;
  align-items: center;
  height: 44px;
}
.password-input-wrapper input[type="password"],
.password-input-wrapper input[type="text"] {
  border: none;
  background: transparent;
  outline: none;
  padding: 10px 40px 10px 14px;
  font-size: 16px;
  width: 100%;
  height: 100%;
  box-sizing: border-box;
}
.eye-icon {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  cursor: pointer;
  display: flex;
  align-items: center;
  user-select: none;
}
.eye-icon svg {
  display: block;
}
.password-input-wrapper input:focus {
  outline: none;
  box-shadow: none;
}
</style>

<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const pwd = document.getElementById('password-input');
    const eyeOpen = document.getElementById('eyeOpen');
    const eyeClosed = document.getElementById('eyeClosed');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        eyeOpen.style.display = 'none';
        eyeClosed.style.display = 'block';
    } else {
        pwd.type = 'password';
        eyeOpen.style.display = 'block';
        eyeClosed.style.display = 'none';
    }
});
</script>

<?php include 'includes/footer.php'; ?>