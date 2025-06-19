<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$staff_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($staff_id < 1) {
    header("Location: services.php");
    exit;
}

// Fetch staff details
$stmt = $conn->prepare("SELECT * FROM delivery_staff WHERE staff_id = ?");
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$result = $stmt->get_result();
$staff = $result->fetch_assoc();
$stmt->close();

if (!$staff) {
    header("Location: services.php");
    exit;
}

$error = $success = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $status = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
    $password = $_POST['password'];

    if ($username == "" || $full_name == "") {
        $error = "Username and Full Name are required.";
    } else {
        // Check for duplicate username
        $stmt = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE username = ? AND staff_id != ?");
        $stmt->bind_param("si", $username, $staff_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = "Username already exists.";
        } else {
            if (!empty($password)) {
                // Update all including password
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE delivery_staff SET username=?, full_name=?, password=?, status=? WHERE staff_id=?");
                $update->bind_param("ssssi", $username, $full_name, $hashed_pw, $status, $staff_id);
            } else {
                // Update except password
                $update = $conn->prepare("UPDATE delivery_staff SET username=?, full_name=?, status=? WHERE staff_id=?");
                $update->bind_param("sssi", $username, $full_name, $status, $staff_id);
            }
            if ($update->execute()) {
                $success = "Staff updated successfully.";
                // Refresh staff details
                $stmt = $conn->prepare("SELECT * FROM delivery_staff WHERE staff_id = ?");
                $stmt->bind_param("i", $staff_id);
                $stmt->execute();
                $staff = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            } else {
                $error = "Error updating staff: " . $conn->error;
            }
            $update->close();
        }
        $stmt->close();
    }
}
?>
<?php include 'admin_header.php'; ?>
<style>
.staff-form {
    border: 1.5px solid #d6d6f3;
    background: #f8f8fc;
    border-radius: 10px;
    max-width: 420px;
    margin: 0 auto 36px auto;
    padding: 24px 28px;
}
.staff-form h3 { margin-top: 0; color: #234c96;}
.msg-success { color: #219150; margin-bottom: 16px;}
.msg-error { color: #d42d2d; margin-bottom: 16px;}
.form-row { margin-bottom: 15px; }
.form-label { display:inline-block; width: 100px; }
.form-input { width: 220px; }
.btn { padding: 7px 24px; background: #3c4cb8; color: #fff; border: none; border-radius: 4px; cursor: pointer;}
.btn:hover { background: #234c96; }
</style>
<div style="max-width:600px;margin:38px auto 25px auto;">
    <div class="staff-form">
        <h3>Edit Delivery Staff</h3>
        <?php if ($success): ?>
            <div class="msg-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="msg-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <div class="form-row">
                <label class="form-label" for="username">Username<span style="color:#d42d2d;">*</span></label>
                <input class="form-input" type="text" name="username" id="username" required maxlength="50" value="<?= htmlspecialchars($staff['username']) ?>">
            </div>
            <div class="form-row">
                <label class="form-label" for="full_name">Full Name<span style="color:#d42d2d;">*</span></label>
                <input class="form-input" type="text" name="full_name" id="full_name" required maxlength="100" value="<?= htmlspecialchars($staff['full_name']) ?>">
            </div>
            <div class="form-row">
                <label class="form-label" for="password">Password</label>
                <input class="form-input" type="password" name="password" id="password" minlength="6" placeholder="Leave blank to keep current">
            </div>
            <div class="form-row">
                <label class="form-label" for="status">Status</label>
                <select class="form-input" name="status" id="status">
                    <option value="active" <?= $staff['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $staff['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="form-row">
                <button type="submit" class="btn">Save Changes</button>
                <a href="services.php" class="btn" style="background:#bbb; color:#222; margin-left:10px;">Back</a>
            </div>
        </form>
    </div>
</div>
<?php include '../includes/footer.php'; ?>