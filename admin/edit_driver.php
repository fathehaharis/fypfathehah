<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: drivers.php");
    exit;
}

$driver_id = intval($_GET['id']);
$error = '';
$success = '';

// Fetch driver data
$stmt = $conn->prepare("SELECT * FROM driver WHERE driver_id = ?");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    $stmt->close();
    header("Location: drivers.php");
    exit;
}
$driver = $result->fetch_assoc();
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $phone_no = trim($_POST['phone_no']);
    $license_no = trim($_POST['license_no']);
    $id_no = trim($_POST['id_no']);
    $address = trim($_POST['address']);
    $age = intval($_POST['age']);
    $blacklist = ($_POST['blacklist'] === 'Yes') ? 'Yes' : 'No';
    $blacklist_reason = trim($_POST['blacklist_reason']);

    // Validate required fields
    if ($full_name === '' || $phone_no === '') {
        $error = 'Full name and phone number are required.';
    } else {
        // Update only editable fields
        $sql = "UPDATE driver SET full_name=?, phone_no=?, license_no=?, id_no=?, address=?, age=?, blacklist=?, blacklist_reason=? WHERE driver_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "ssssssssi",
            $full_name,
            $phone_no,
            $license_no,
            $id_no,
            $address,
            $age,
            $blacklist,
            $blacklist_reason,
            $driver_id
        );
        if ($stmt->execute()) {
            $success = "Driver updated successfully.";
            // Refresh driver data after update
            $stmt->close();
            $stmt = $conn->prepare("SELECT * FROM driver WHERE driver_id = ?");
            $stmt->bind_param("i", $driver_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $driver = $result->fetch_assoc();
            $stmt->close();
        } else {
            $error = "Failed to update driver.";
        }
    }
}
?>

<?php include 'admin_header.php'; ?>

<style>
.edit-form {
    max-width: 520px;
    margin: 40px auto;
    padding: 32px;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 2px 18px #e0e7ef44;
}
.edit-form h2 {
    font-size: 1.45em;
    color: #2b5cbc;
    margin-bottom: 20px;
    font-weight: 800;
    letter-spacing: 1px;
}
.edit-form input[type="text"], .edit-form input[type="number"], .edit-form textarea, .edit-form select {
    width: 100%;
    padding: 10px 13px;
    margin-bottom: 17px;
    border: 1.5px solid #b5bee5;
    border-radius: 7px;
    font-size: 1.04em;
    background: #f7fafd;
}
.edit-form label {
    font-weight: 600;
    color: #183c7c;
    display: block;
    margin-bottom: 7px;
}
.edit-form button {
    padding: 10px 26px;
    background: #2b5cbc;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-weight: 700;
    font-size: 1.07em;
    cursor: pointer;
    margin-top: 4px;
    margin-right: 8px;
    transition: background 0.15s;
}
.edit-form button:hover {
    background: #243570;
}
.edit-form .msg-success {
    background: #d9f8e7;
    color: #227e3b;
    border: 1px solid #b3e5c3;
    border-radius: 7px;
    padding: 9px 15px;
    margin-bottom: 18px;
    font-weight: 600;
}
.edit-form .msg-error {
    background: #ffeaea;
    color: #b30000;
    border: 1px solid #ffc4c4;
    border-radius: 7px;
    padding: 9px 15px;
    margin-bottom: 18px;
    font-weight: 600;
}
.back-btn {
    background: #ccc;
    color: #222;
    border: none;
    padding: 12px 30px;
    border-radius: 7px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    margin-bottom: 20px;
    display: inline-block;
}
.back-btn:hover {background: #bbb;}
</style>

<div class="edit-form">
    <h2>Edit Driver</h2>
    <?php if ($success): ?>
        <div class="msg-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="msg-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <label>Full Name *</label>
        <input type="text" name="full_name" value="<?= htmlspecialchars($driver['full_name']) ?>" required>

        <label>Phone Number *</label>
        <input type="text" name="phone_no" value="<?= htmlspecialchars($driver['phone_no']) ?>" required>

        <label>License No</label>
        <input type="text" name="license_no" value="<?= htmlspecialchars($driver['license_no']) ?>">

        <label>ID No</label>
        <input type="text" name="id_no" value="<?= htmlspecialchars($driver['id_no']) ?>">

        <label>Address</label>
        <textarea name="address"><?= htmlspecialchars($driver['address']) ?></textarea>

        <label>Age</label>
        <input type="number" name="age" min="0" value="<?= htmlspecialchars($driver['age']) ?>">

        <label>Blacklist</label>
        <select name="blacklist">
            <option value="No" <?= $driver['blacklist'] === 'No' ? 'selected' : '' ?>>No</option>
            <option value="Yes" <?= $driver['blacklist'] === 'Yes' ? 'selected' : '' ?>>Yes</option>
        </select>

        <label>Blacklist Reason</label>
        <textarea name="blacklist_reason" rows="3" placeholder="Enter reason for blacklisting (if any)"><?= htmlspecialchars($driver['blacklist_reason'] ?? '') ?></textarea>

        <button type="submit">Update Driver</button>
        <a href="drivers.php" class="back-btn" style="text-decoration:none;">Back</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>