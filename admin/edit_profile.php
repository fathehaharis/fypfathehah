<?php
session_start();
include '../connect.php';

// Determine if customer or admin is editing
$is_customer = isset($_SESSION['cust_id']);
$is_admin = isset($_SESSION['admin_id']);

if (!$is_customer && !$is_admin) {
    header("Location: /index.php");
    exit;
}

// Set variables depending on user type
if ($is_customer) {
    $user_id = $_SESSION['cust_id'];
    $table = "customer";
    $fields = ['full_name', 'username', 'email', 'phone_no', 'address', 'age'];
    $back_url = "dashboard.php";
} else {
    $user_id = $_SESSION['admin_id'];
    $table = "admin";
    $fields = ['full_name', 'username', 'email'];
    $back_url = "admin_dashboard.php";
}

// Handle form submission
$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $update_data = [];
    $params = [];
    $types = "";

    foreach ($fields as $field) {
        $value = trim($_POST[$field]);
        $update_data[] = "$field = ?";
        $params[] = $value;
        $types .= "s";
    }
    $params[] = $user_id;
    $types .= "i";

    $sql = "UPDATE $table SET " . implode(', ', $update_data) . " WHERE " . ($is_customer ? "cust_id" : "admin_id") . " = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        $success = "Profile updated successfully.";
    } else {
        $error = "Failed to update profile: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch current user data for the form
$id_field = $is_customer ? "cust_id" : "admin_id";
$stmt = $conn->prepare("SELECT " . implode(',', $fields) . " FROM $table WHERE $id_field = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<p>User not found.</p>";
    include '../includes/footer.php';
    exit;
}

// Header include
if ($is_customer) {
    include '../includes/header.php';
} else {
    include 'admin_header.php';
}
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.edit-profile-container {
    max-width: 540px;
    margin: 40px auto 60px auto;
    background: #fff;
    padding: 36px 38px 32px 38px;
    border-radius: 13px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.09);
}
.edit-profile-title {
    font-size: 1.4em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 20px;
    text-align: center;
}
.edit-profile-form label {
    display: block;
    margin-bottom: 6px;
    margin-top: 15px;
    color: #3c4cb8;
    font-weight: 600;
}
.edit-profile-form input, .edit-profile-form textarea {
    width: 100%;
    padding: 10px 11px;
    border-radius: 8px;
    border: 1.4px solid #b5bee5;
    font-size: 1.07em;
    background: #f7fafd;
    margin-bottom: 2px;
}
.edit-profile-form input[type="number"] {
    max-width: 160px;
}
.edit-profile-btn {
    margin-top: 22px;
    display: block;
    width: 100%;
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 12px 0;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    text-align: center;
    text-decoration: none;
}
.edit-profile-btn:hover {
    background: #234c96;
}
.back-btn {
    width:100%;
    margin-top:18px;
    background:#c2c7d6;
    color:#2f377d;
    border:none;
    padding:11px 0;
    border-radius:8px;
    font-size:1.08em;
    font-weight:600;
    cursor:pointer;
    text-align:center;
    transition:background 0.18s, color 0.18s;
}
.success-msg {
    background:#e0ffe0;
    color:#2b5c2b;
    font-weight:600;
    padding:12px;
    border-radius:7px;
    margin-bottom:15px;
    text-align:center;
}
.error-msg {
    background:#ffe0e0;
    color:#c22b2b;
    font-weight:600;
    padding:12px;
    border-radius:7px;
    margin-bottom:15px;
    text-align:center;
}
</style>

<div class="edit-profile-container">
    <div class="edit-profile-title">Edit Profile</div>
    <?php if ($success): ?><div class="success-msg"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error-msg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form class="edit-profile-form" method="post" autocomplete="off">
        <label for="full_name">Full Name</label>
        <input required type="text" name="full_name" id="full_name" maxlength="80" value="<?= htmlspecialchars($user['full_name']) ?>">
        
        <label for="username">Username</label>
        <input required type="text" name="username" id="username" maxlength="40" value="<?= htmlspecialchars($user['username']) ?>">

        <label for="email">Email</label>
        <input required type="email" name="email" id="email" maxlength="80" value="<?= htmlspecialchars($user['email']) ?>">

        <?php if ($is_customer): ?>
            <label for="phone_no">Phone No</label>
            <input required type="text" name="phone_no" id="phone_no" maxlength="20" value="<?= htmlspecialchars($user['phone_no']) ?>">

            <label for="address">Address</label>
            <textarea required name="address" id="address" maxlength="255"><?= htmlspecialchars($user['address']) ?></textarea>

            <label for="age">Age</label>
            <input required type="number" min="16" max="120" name="age" id="age" value="<?= htmlspecialchars($user['age']) ?>">
        <?php endif; ?>

        <button type="submit" class="edit-profile-btn">Save Changes</button>
        <button type="button" class="back-btn" onclick="window.location.href='profile.php'">Back</button>
    </form>
</div>

<?php
if ($is_customer) {
    include '../includes/footer.php';
} else {
    include '../includes/footer.php'; // Or another footer if you have one for admin
}
?>