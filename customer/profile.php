<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

$cust_id = $_SESSION['cust_id'];

// Fetch all customer details except reset_code, reset_code_expire
$stmt = $conn->prepare("SELECT full_name, phone_no, email, username, id_no, id_front_image, id_back_image, license_front_image, license_back_image, address, age FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<p>User not found.</p>";
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
    width: 160px;
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
.id-image-preview {
    max-width: 120px;
    max-height: 80px;
    border-radius: 6px;
    background: #f7fafd;
    border: 1px solid #e1e1e1;
}
.code-note {
    color: #b0b0b0;
    font-size: 0.93em;
}
</style>

<div class="profile-container">
    <div class="profile-title">My Profile</div>
    <table class="profile-table">
        <tr><th>Full Name</th><td><?= htmlspecialchars($user['full_name']) ?></td></tr>
        <tr><th>Username</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($user['email']) ?></td></tr>
        <tr><th>Phone No</th><td><?= htmlspecialchars($user['phone_no']) ?></td></tr>
        <tr><th>ID No</th><td><?= htmlspecialchars($user['id_no']) ?></td></tr>
        <tr>
            <th>ID Front Image</th>
            <td>
                <?php if (!empty($user['id_front_image'])): ?>
                    <img class="id-image-preview" src="get_id_image.php?type=front&cust_id=<?= $cust_id ?>" alt="ID Front">
                <?php else: ?>
                    <span class="code-note">No image uploaded</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>ID Back Image</th>
            <td>
                <?php if (!empty($user['id_back_image'])): ?>
                    <img class="id-image-preview" src="get_id_image.php?type=back&cust_id=<?= $cust_id ?>" alt="ID Back">
                <?php else: ?>
                    <span class="code-note">No image uploaded</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>License Front Image</th>
            <td>
                <?php if (!empty($user['license_front_image'])): ?>
                    <img class="id-image-preview" src="get_id_image.php?type=license_front&cust_id=<?= $cust_id ?>" alt="License Front">
                <?php else: ?>
                    <span class="code-note">No image uploaded</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>License Back Image</th>
            <td>
                <?php if (!empty($user['license_back_image'])): ?>
                    <img class="id-image-preview" src="get_id_image.php?type=license_back&cust_id=<?= $cust_id ?>" alt="License Back">
                <?php else: ?>
                    <span class="code-note">No image uploaded</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr><th>Address</th><td><?= htmlspecialchars($user['address']) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($user['age']) ?></td></tr>
    </table>
    <a class="profile-edit-btn" href="edit_profile.php">Edit Profile</a>
    <button class="back-btn" onclick="window.location.href='dashboard.php'">Back</button>
</div>

<?php include '../includes/footer.php'; ?>