<?php
include '../connect.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    $stmt = $conn->prepare("SELECT * FROM delivery_staff WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();

    if ($staff && password_verify($password, $staff['password'])) {
        if ($staff['status'] !== 'active') {
            $_SESSION['login_errors'][] = "Your account is not active. Please contact admin.";
        } else {
            session_regenerate_id(true);
            $_SESSION['staff_id'] = $staff['staff_id'];
            $_SESSION['staff_username'] = $staff['username'];
            $_SESSION['staff_name'] = $staff['full_name'];

            // Handle Remember Me (30 days)
            if ($remember) {
                setcookie('staff_login', $staff['staff_id'], time() + (86400 * 30), "/");
            } else {
                setcookie('staff_login', '', time() - 3600, "/");
            }

            header("Location: delivery_staff_dashboard.php");
            exit;
        }
    } else {
        $_SESSION['login_errors'][] = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<title>Delivery Staff Dashboard</title>
</head>
<body>
<div class="landing-container">
  <div class="landing-banner">
    <div class="banner-content">
      <h1>Delivery Staff Portal</h1>
      <div class="banner-logo-below-title">
        <img src="/assets/images/TimeLess_logo.png" alt="Timeless Car Rental Logo">
      </div>
      <p class="banner-desc">Access your assigned deliveries</p>
    </div>
  </div>
  <div class="landing-login-box">
    <h2>Staff Login</h2>
    <p class="login-desc">Enter your username and password to login.</p>
    <?php
    if (!empty($_SESSION['login_errors'])) {
        echo '<div class="error-messages">';
        foreach ($_SESSION['login_errors'] as $error) {
            echo '<p>' . htmlspecialchars($error) . '</p>';
        }
        echo '</div>';
        unset($_SESSION['login_errors']);
    }
    ?>
    <form action="delivery_staff_login.php" method="post" class="login-form">
      <input type="text" name="username" placeholder="Username" required>
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
      <label class="remember-me" style="margin: 8px 0 16px 0; display:block;">
        <input type="checkbox" name="remember"> Remember me
      </label>
      <button type="submit" class="login-btn">Log In</button>
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
<?php include 'C:\xampp\htdocs\fypfathehah\includes\footer.php'; ?>
</body>
</html>