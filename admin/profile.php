<?php
include '../connect.php';
session_start();

// Check if user is logged in (customer or admin)
$user_type = '';
$user_id = '';
if (isset($_SESSION['cust_id'])) {
    $user_type = 'customer';
    $user_id = $_SESSION['cust_id'];
} elseif (isset($_SESSION['admin_id'])) {
    $user_type = 'admin';
    $user_id = $_SESSION['admin_id'];
} else {
    header("Location: ../login.php");
    exit;
}

// Fetch user info
if ($user_type == 'customer') {
    $sql = "SELECT * FROM customer WHERE cust_id = ?";
} else {
    $sql = "SELECT * FROM admin WHERE admin_id = ?";
}
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();
?>

<?php include 'header.php'; // Or 'admin_header.php', depending on your layout ?>

<style>
.profile-container {
    max-width: 520px;
    margin: 40px auto 30px auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 14px #e0e7ef55;
    padding: 34px 38px 28px 38px;
}
.profile-title {
    font-size: 2em;
    color: #2b5cbc;
    font-weight: 800;
    letter-spacing: 1px;
    margin-bottom: 22px;
    text-align: center;
}
.profile-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 17px;
    font-size: 1.08em;
}
.profile-label {
    color: #888;
    font-weight: 600;
}
.profile-value {
    color: #232d3b;
    font-weight: 600;
    text-align: right;
}
.profile-edit-btn {
    display: block;
    margin: 30px auto 0 auto;
    background: #2b5cbc;
    color: #fff;
    padding: 10px 32px;
    border-radius: 7px;
    border: none;
    font-size: 1.04em;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.14s;
    text-decoration: none;
    text-align: center;
}
.profile-edit-btn:hover {
    background: #1a3976;
}
</style>

<div class="profile-container">
    <div class="profile-title">My Profile</div>
    <?php if ($user_type == 'customer'): ?>
        <div class="profile-row"><span class="profile-label">Full Name:</span> <span class="profile-value"><?= htmlspecialchars($user['full_name']) ?></span></div>
        <div class="profile-row"><span class="profile-label">Username:</span> <span class="profile-value"><?= htmlspecialchars($user['username']) ?></span></div>
        <div class="profile-row"><span class="profile-label">Email:</span> <span class="profile-value"><?= htmlspecialchars($user['email']) ?></span></div>
        <div class="profile-row"><span class="profile-label">Phone No:</span> <span class="profile-value"><?= htmlspecialchars($user['phone_no']) ?></span></div>
        <div class="profile-row"><span class="profile-label">Address:</span> <span class="profile-value"><?= htmlspecialchars($user['address']) ?></span></div>
        <div class="profile-row"><span class="profile-label">Age:</span> <span class="profile-value"><?= htmlspecialchars($user['age']) ?></span></div>
        <a href="edit_profile.php" class="profile-edit-btn">Edit Profile</a>
    <?php elseif ($user_type == 'admin'): ?>
        <div class="profile-row"><span class="profile-label">Username:</span> <span class="profile-value"><?= htmlspecialchars($user['username']) ?></span></div>
        <div class="profile-row"><span class="profile-label">Email:</span> <span class="profile-value"><?= htmlspecialchars($user['email']) ?></span></div>
        <div class="profile-row"><span class="profile-label">Full Name:</span> <span class="profile-value"><?= htmlspecialchars($user['full_name']) ?></span></div>
        <a href="edit_profile.php" class="profile-edit-btn">Edit Profile</a>
    <?php else: ?>
        <div style="text-align:center;color:#c00;">User not found.</div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>