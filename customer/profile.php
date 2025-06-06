<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

$cust_id = $_SESSION['cust_id'];

// Fetch customer details including ID images
$stmt = $conn->prepare("SELECT full_name, phone_no, email, username, license_no, id_no, address, age, id_front_image, id_back_image FROM customer WHERE cust_id = ?");
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
.id-img-container {
    display: flex;
    gap: 18px;
    margin-top: 14px;
    margin-bottom: 12px;
    justify-content: flex-start;
}
.id-img-thumb {
    width: 120px;
    height: 80px;
    object-fit: contain;
    border-radius: 5px;
    border: 1px solid #ddd;
    background: #fafbfd;
}
.id-img-label {
    text-align: center;
    display: block;
    margin-top: 4px;
    font-size: 0.97em;
    color: #555;
}
</style>

<div class="profile-container">
    <div class="profile-title">My Profile</div>
    <table class="profile-table">
        <tr><th>Full Name</th><td><?= htmlspecialchars($user['full_name']) ?></td></tr>
        <tr><th>Username</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
        <tr><th>Email</th><td><?= htmlspecialchars($user['email']) ?></td></tr>
        <tr><th>Phone No</th><td><?= htmlspecialchars($user['phone_no']) ?></td></tr>
        <tr><th>License No</th><td><?= htmlspecialchars($user['license_no']) ?></td></tr>
        <tr><th>ID Number</th><td><?= htmlspecialchars($user['id_no']) ?></td></tr>
        <tr><th>Address</th><td><?= htmlspecialchars($user['address']) ?></td></tr>
        <tr><th>Age</th><td><?= htmlspecialchars($user['age']) ?></td></tr>
    </table>
    <div class="id-img-container">
        <div>
            <?php if (!empty($user['id_front_image'])): ?>
                <img class="id-img-thumb" src="data:image/jpeg;base64,<?= base64_encode($user['id_front_image']) ?>" alt="ID Front">
            <?php else: ?>
                <img class="id-img-thumb" src="/assets/images/id-placeholder.png" alt="No Front ID">
            <?php endif; ?>
            <span class="id-img-label">ID Front</span>
        </div>
        <div>
            <?php if (!empty($user['id_back_image'])): ?>
                <img class="id-img-thumb" src="data:image/jpeg;base64,<?= base64_encode($user['id_back_image']) ?>" alt="ID Back">
            <?php else: ?>
                <img class="id-img-thumb" src="/assets/images/id-placeholder.png" alt="No Back ID">
            <?php endif; ?>
            <span class="id-img-label">ID Back</span>
        </div>
    </div>
    <a class="profile-edit-btn" href="edit_profile.php">Edit Profile</a>
</div>

<?php include '../includes/footer.php'; ?>