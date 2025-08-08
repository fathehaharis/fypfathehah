<?php
session_start();

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

if (empty($_SESSION['booking_data'])) {
    header("Location: book_car.php");
    exit;
}

include '../connect.php';

// Fetch customer (driver) data
$cust_id = (int)$_SESSION['cust_id'];
$stmt = $conn->prepare("
    SELECT full_name, phone_no, email, id_no, address, age,
           id_front_image, id_back_image,
           license_front_image, license_back_image
    FROM customer
    WHERE cust_id = ?
");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($full_name,$phone_no,$email,$id_no,$address,$age,
                   $id_front,$id_back,$lic_front,$lic_back);
$stmt->fetch();
$stmt->close();

// Proceed handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // No editing here (read-only). If editing needed redirect to profile.
    header("Location: booking_guarantor.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Driver Details | Timeless Car Rental</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background:#eceef4; }
.driver-wrapper {
    max-width: 900px;
    margin: 40px auto 60px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 34px;
}
@media (max-width: 960px){
    .driver-wrapper { grid-template-columns: 1fr; }
}
.card {
    background:#fff;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(40,55,95,0.10);
    padding:28px 32px 30px;
    position:relative;
}
.section-title {
    font-size:1.3em;
    font-weight:700;
    letter-spacing:.5px;
    color:#2f377d;
    margin:0 0 18px;
}
.info-row {
    margin-bottom:12px;
    font-size:.95em;
}
.info-label {
    display:block;
    font-weight:600;
    color:#2d3d66;
    margin-bottom:2px;
    font-size:.82em;
    letter-spacing:.5px;
    text-transform:uppercase;
}
.info-value {
    background:#f7f9fd;
    border:1px solid #e1e6f0;
    border-radius:7px;
    padding:8px 10px;
    color:#313d55;
    min-height:36px;
    display:flex;
    align-items:center;
    font-size:.95em;
}
.img-slot {
    background:#f0f3f9;
    border:1px solid #d5dce7;
    border-radius:10px;
    padding:10px;
    text-align:center;
    margin-bottom:14px;
}
.img-slot img {
    max-width:100%;
    max-height:160px;
    object-fit:cover;
    border-radius:8px;
}
.note-box {
    background:#f8f4e7;
    border:1px solid #e3d7b8;
    padding:10px 14px;
    border-radius:8px;
    font-size:.8em;
    color:#7a6432;
    margin:0 0 18px;
}
.btn-row {
    margin-top:12px;
    display:flex;
    justify-content:flex-end;
    gap:10px;
    flex-wrap:wrap;
}
.next-btn, .back-btn, .edit-btn {
    border:none;
    cursor:pointer;
    font-weight:600;
    font-size:.95em;
    border-radius:8px;
    padding:12px 26px;
    transition:.18s;
    text-decoration:none;
    display:inline-block;
}
.next-btn { background:#3c4cb8; color:#fff; }
.next-btn:hover { background:#234c96; }
.back-btn { background:#d1d5de; color:#222; }
.back-btn:hover { background:#bfc5ce; }
.edit-btn {
    background:#ffffff;
    color:#2c4299;
    border:2px solid #3c4cb8;
}
.edit-btn:hover { background:#3c4cb8; color:#fff; }
.no-img {
    display:inline-block;
    background:#eee;
    color:#666;
    font-size:.75em;
    padding:6px 10px;
    border-radius:10px;
    margin-top:14px;
}
</style>
</head>
<body>
<?php include '../includes/header.php'; ?>

<div class="driver-wrapper">
    <div class="card">
        <div class="section-title">Driver (Customer) Details</div>
        <div class="note-box">
            Review your stored details. If something is wrong, click "Edit Profile" before proceeding.
        </div>
        <div class="info-row">
            <span class="info-label">Full Name</span>
            <div class="info-value"><?= htmlspecialchars($full_name) ?></div>
        </div>
        <div class="info-row">
            <span class="info-label">Email</span>
            <div class="info-value"><?= htmlspecialchars($email) ?></div>
        </div>
        <div class="info-row">
            <span class="info-label">Phone Number</span>
            <div class="info-value"><?= htmlspecialchars($phone_no) ?></div>
        </div>
        <div class="info-row">
            <span class="info-label">ID Number</span>
            <div class="info-value"><?= htmlspecialchars($id_no) ?></div>
        </div>
        <div class="info-row">
            <span class="info-label">Address</span>
            <div class="info-value" style="line-height:1.25em;"><?= nl2br(htmlspecialchars($address)) ?></div>
        </div>
        <div class="info-row">
            <span class="info-label">Age</span>
            <div class="info-value"><?= htmlspecialchars($age) ?></div>
        </div>
        <div class="btn-row">
            <a href="book_car.php?car_id=<?= (int)($_SESSION['booking_data']['car_id'] ?? 0) ?>" class="back-btn">Back</a>
            <a href="profile.php" class="edit-btn">Edit Profile</a>
        </div>
    </div>

    <div class="card">
        <div class="section-title">Stored ID & License Images</div>
        <div class="info-row" style="margin-bottom:4px;">
            <span class="info-label">Identification</span>
        </div>
        <div class="img-slot">
            <div style="font-size:.8em;font-weight:600;margin-bottom:6px;">ID Front</div>
            <?php if (!empty($id_front)): ?>
                <img src="get_id_image.php?type=front&cust_id=<?= $cust_id ?>" alt="ID Front">
            <?php else: ?><div class="no-img">Not Uploaded</div><?php endif; ?>
        </div>
        <div class="img-slot">
            <div style="font-size:.8em;font-weight:600;margin-bottom:6px;">ID Back</div>
            <?php if (!empty($id_back)): ?>
                <img src="get_id_image.php?type=back&cust_id=<?= $cust_id ?>" alt="ID Back">
            <?php else: ?><div class="no-img">Not Uploaded</div><?php endif; ?>
        </div>

        <div class="info-row" style="margin-top:20px;margin-bottom:4px;">
            <span class="info-label">Driving License</span>
        </div>
        <div class="img-slot">
            <div style="font-size:.8em;font-weight:600;margin-bottom:6px;">License Front</div>
            <?php if (!empty($lic_front)): ?>
                <img src="get_id_image.php?type=license_front&cust_id=<?= $cust_id ?>" alt="License Front">
            <?php else: ?><div class="no-img">Not Uploaded</div><?php endif; ?>
        </div>
        <div class="img-slot">
            <div style="font-size:.8em;font-weight:600;margin-bottom:6px;">License Back</div>
            <?php if (!empty($lic_back)): ?>
                <img src="get_id_image.php?type=license_back&cust_id=<?= $cust_id ?>" alt="License Back">
            <?php else: ?><div class="no-img">Not Uploaded</div><?php endif; ?>
        </div>

        <form method="POST" class="btn-row">
            <button type="submit" class="next-btn">Proceed (Guarantor)</button>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>