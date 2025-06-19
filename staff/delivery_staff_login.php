<?php
include '../connect.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Only allow login with username (not email) for delivery staff
    $stmt = $conn->prepare("SELECT * FROM delivery_staff WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();

    if ($staff && password_verify($password, $staff['password'])) {
        if ($staff['status'] !== 'active') {
            $_SESSION['login_errors'][] = "Your account is not active. Please contact admin.";
        } else {
            $_SESSION['staff_id'] = $staff['staff_id'];
            $_SESSION['staff_username'] = $staff['username'];
            $_SESSION['staff_name'] = $staff['full_name'];
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
      <input type="password" name="password" placeholder="Password" required>
      <button type="submit" class="login-btn">Log In</button>
    </form>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
</body>
</html>