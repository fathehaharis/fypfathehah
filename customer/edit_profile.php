<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

$cust_id = $_SESSION['cust_id'];

// Handle form submission
$success = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone_no = trim($_POST['phone_no']);
    $license_no = trim($_POST['license_no']);
    $id_no = trim($_POST['id_no']);
    $address = trim($_POST['address']);
    $age = intval($_POST['age']);

    // ID image uploads (optional)
    $id_front_blob = null;
    $id_back_blob = null;
    $has_id_front = false;
    $has_id_back = false;

    if (isset($_FILES['id_front_image']) && $_FILES['id_front_image']['error'] === UPLOAD_ERR_OK) {
        $id_front_blob = file_get_contents($_FILES['id_front_image']['tmp_name']);
        $has_id_front = true;
    }
    if (isset($_FILES['id_back_image']) && $_FILES['id_back_image']['error'] === UPLOAD_ERR_OK) {
        $id_back_blob = file_get_contents($_FILES['id_back_image']['tmp_name']);
        $has_id_back = true;
    }

    // Prepare SQL
    $sql = "UPDATE customer SET full_name=?, username=?, email=?, phone_no=?, license_no=?, id_no=?, address=?, age=?";
    $types = "sssssssi";
    $params = [$full_name, $username, $email, $phone_no, $license_no, $id_no, $address, $age];

    if ($has_id_front) {
        $sql .= ", id_front_image=?";
        $types .= "s";
        $params[] = $id_front_blob;
    }
    if ($has_id_back) {
        $sql .= ", id_back_image=?";
        $types .= "s";
        $params[] = $id_back_blob;
    }
    $sql .= " WHERE cust_id=?";
    $types .= "i";
    $params[] = $cust_id;

    $stmt = $conn->prepare($sql);

    // Dynamically bind parameters
    $stmt->bind_param($types, ...$params);

    if ($has_id_front) $stmt->send_long_data(array_search($id_front_blob, $params), $id_front_blob);
    if ($has_id_back) $stmt->send_long_data(array_search($id_back_blob, $params), $id_back_blob);

    if ($stmt->execute()) {
        $success = true;
    } else {
        $error = "Update failed: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch current data
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
.edit-container {
    max-width: 540px;
    margin: 40px auto 60px auto;
    background: #fff;
    padding: 36px 38px 32px 38px;
    border-radius: 13px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.09);
}
.edit-title {
    font-size: 1.28em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 18px;
    text-align: center;
}
.edit-form label {
    display: block;
    margin-top: 14px;
    margin-bottom: 6px;
    font-weight: 600;
    color: #3c4cb8;
}
.edit-form input[type="text"],
.edit-form input[type="email"],
.edit-form input[type="number"],
.edit-form textarea {
    width: 100%;
    padding: 9px;
    border: 1px solid #cdd3e3;
    border-radius: 6px;
    font-size: 1em;
    background: #f9fafc;
    margin-bottom: 2px;
}
.edit-form input[type="file"] {
    margin-top: 6px;
}
.edit-form button {
    margin-top: 22px;
    width: 100%;
    padding: 12px 0;
    background: #3c4cb8;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
}
.edit-form button:hover {
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
.success-msg {
    color: #219150;
    padding: 9px 0 0 0;
    font-weight: 600;
    text-align: center;
}
.error-msg {
    color: #d42d2d;
    padding: 9px 0 0 0;
    font-weight: 600;
    text-align: center;
}
.back-btn {
    width: 100%;
    margin-top: 18px;
    background: #c2c7d6;
    color: #2f377d;
    border: none;
    padding: 11px 0;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    display: block;
    text-align: center;
    text-decoration: none;
    transition: background 0.18s, color 0.18s;
}
.back-btn:hover {
    background: #b4bac9;
    color: #162040;
}
</style>

<div class="edit-container">
    <div class="edit-title">Edit My Profile</div>
    <?php if ($success): ?>
        <div class="success-msg">Profile updated successfully!</div>
    <?php elseif ($error): ?>
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form class="edit-form" action="" method="post" enctype="multipart/form-data">
        <label for="full_name">Full Name</label>
        <input type="text" name="full_name" id="full_name" value="<?= htmlspecialchars($user['full_name']) ?>" required>

        <label for="username">Username</label>
        <input type="text" name="username" id="username" value="<?= htmlspecialchars($user['username']) ?>" required>

        <label for="email">Email</label>
        <input type="email" name="email" id="email" value="<?= htmlspecialchars($user['email']) ?>" required>

        <label for="phone_no">Phone No</label>
        <input type="text" name="phone_no" id="phone_no" value="<?= htmlspecialchars($user['phone_no']) ?>" required>

        <label for="license_no">License No</label>
        <input type="text" name="license_no" id="license_no" value="<?= htmlspecialchars($user['license_no']) ?>" required>

        <label for="id_no">ID Number</label>
        <input type="text" name="id_no" id="id_no" value="<?= htmlspecialchars($user['id_no']) ?>" required>

        <label for="address">Address</label>
        <textarea name="address" id="address" required><?= htmlspecialchars($user['address']) ?></textarea>

        <label for="age">Age</label>
        <input type="number" min="18" name="age" id="age" value="<?= htmlspecialchars($user['age']) ?>" required>

        <div class="id-img-container">
            <div>
                <?php if (!empty($user['id_front_image'])): ?>
                    <img class="id-img-thumb" src="data:image/jpeg;base64,<?= base64_encode($user['id_front_image']) ?>" alt="ID Front">
                <?php else: ?>
                    <img class="id-img-thumb" src="/assets/images/id-placeholder.png" alt="No Front ID">
                <?php endif; ?>
                <label class="id-img-label" for="id_front_image">ID Front<br><input type="file" name="id_front_image" id="id_front_image" accept="image/*"></label>
            </div>
            <div>
                <?php if (!empty($user['id_back_image'])): ?>
                    <img class="id-img-thumb" src="data:image/jpeg;base64,<?= base64_encode($user['id_back_image']) ?>" alt="ID Back">
                <?php else: ?>
                    <img class="id-img-thumb" src="/assets/images/id-placeholder.png" alt="No Back ID">
                <?php endif; ?>
                <label class="id-img-label" for="id_back_image">ID Back<br><input type="file" name="id_back_image" id="id_back_image" accept="image/*"></label>
            </div>
        </div>
        <button type="submit">Save Changes</button>
    </form>
    <button class="back-btn" onclick="window.location.href='profile.php'">Back</button>
</div>

<?php include '../includes/footer.php'; ?>