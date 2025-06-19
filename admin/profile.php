<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: /admin_login.php");
    exit;
}

include '../connect.php';
include 'admin_header.php';

$admin_id = $_SESSION['admin_id'];

// Fetch admin details
$stmt = $conn->prepare("SELECT full_name, email, username FROM admin WHERE admin_id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<p>Admin not found.</p>";
    include '../includes/footer.php';
    exit;
}
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.profile-container {
    max-width: 520px;
    margin: 40px auto 60px auto;
    background: #fff;
    padding: 36px 38px 32px 38px;
    border-radius: 13px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.09);
}
.profile-title {
    font-size: 1.4em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 20px;
    text-align: center;
}
.profile-table {
    width: 100%;
    border-spacing: 0 10px;
    font-size: 1.05em;
}
.profile-table th, .profile-table td {
    text-align: left;
    padding: 6px 10px 6px 0;
    vertical-align: middle;
}
.profile-table th {
    color: #3c4cb8;
    font-weight: 600;
    width: 140px;
}
.profile-edit-btn {
    margin-top: 24px;
    display: block;
    width: 100%;
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 11px 0;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    text-align: center;
    text-decoration: none;
}
.profile-edit-btn:hover {
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
</style>

<div class="profile-container">
    <div class="profile-title">Admin Profile</div>
    <table class="profile-table">
        <tr><th>Full Name</th><td><?= htmlspecialchars($user['full_name']) ?></td></tr>
        <tr><th>Username</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($user['email']) ?></td></tr>
    </table>
    <a class="profile-edit-btn" href="edit_profile.php">Edit Profile</a>
    <button class="back-btn" onclick="window.location.href='admin_dashboard.php'">Back</button>
</div>

<?php include '../includes/footer.php'; ?>