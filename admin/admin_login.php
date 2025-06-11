<?php
include '../connect.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM admin WHERE username = ? OR email = ?");
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['admin_id'];
        $_SESSION['admin_username'] = $admin['username'];
        header("Location: admin_dashboard.php");
        exit;
    } else {
        $_SESSION['login_errors'][] = "Invalid username/email or password.";
    }
}
?>
<link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
<link rel="stylesheet" href="/assets/css/style.css">
<div class="landing-container">
  <div class="landing-banner">
    <div class="banner-content">
      <h1>Admin Panel</h1>
      <div class="banner-logo-below-title">
        <img src="/assets/images/TimeLess_logo.png" alt="Timeless Car Rental Logo">
      </div>
      <p class="banner-desc">Manage your car rental system</p>
    </div>
  </div>
  <div class="landing-login-box">
    <h2>Admin Login</h2>
    <p class="login-desc">Enter your username/email and password to login.</p>
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
    <form action="admin_login.php" method="post" class="login-form">
      <input type="text" name="username" placeholder="Email or Username" required>
      <input type="password" name="password" placeholder="Password" required>
      <button type="submit" class="login-btn">Log In</button>
    </form>
  </div>
</div>
<?php include '../includes/footer.php'; ?>