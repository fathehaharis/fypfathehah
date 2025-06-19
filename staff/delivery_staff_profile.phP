<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: delivery_staff_login.php");
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Handle profile update
$success_msg = $error_msg = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $status = ($_POST['status'] === 'inactive') ? 'inactive' : 'active';

    if ($full_name == "" || $username == "") {
        $error_msg = "Full name and username cannot be empty.";
    } else {
        // Optional: check if username is taken by someone else
        $stmt = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE username = ? AND staff_id != ?");
        $stmt->bind_param("si", $username, $staff_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error_msg = "Username is already taken by another user.";
        } else {
            // Update profile
            $stmt2 = $conn->prepare("UPDATE delivery_staff SET full_name = ?, username = ?, status = ? WHERE staff_id = ?");
            $stmt2->bind_param("sssi", $full_name, $username, $status, $staff_id);
            if ($stmt2->execute()) {
                $success_msg = "Profile updated successfully.";
                $_SESSION['staff_name'] = $full_name;
            } else {
                $error_msg = "Failed to update profile.";
            }
            $stmt2->close();
        }
        $stmt->close();
    }
}

// Handle password update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password != $confirm_password) {
        $error_msg = "New passwords do not match.";
    } else {
        // Get current hash
        $stmt = $conn->prepare("SELECT password FROM delivery_staff WHERE staff_id = ?");
        $stmt->bind_param("i", $staff_id);
        $stmt->execute();
        $stmt->bind_result($hash);
        if ($stmt->fetch()) {
            if (password_verify($current_password, $hash)) {
                $stmt->close();
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt2 = $conn->prepare("UPDATE delivery_staff SET password = ? WHERE staff_id = ?");
                $stmt2->bind_param("si", $new_hash, $staff_id);
                if ($stmt2->execute()) {
                    $success_msg = "Password updated successfully.";
                } else {
                    $error_msg = "Failed to update password.";
                }
                $stmt2->close();
            } else {
                $error_msg = "Current password is incorrect.";
            }
        }
        $stmt->close();
    }
}

// Fetch staff profile
$stmt = $conn->prepare("SELECT username, full_name, status FROM delivery_staff WHERE staff_id = ?");
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$stmt->bind_result($username, $full_name, $status);
$stmt->fetch();
$stmt->close();
include 'staff_header.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>My Profile</title>
    <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: #f7f9fa; }
        .profile-container {
            max-width: 500px; margin: 55px auto 0 auto;
            background: #fff; border-radius: 12px; box-shadow: 0 2px 12px #e0e7ef55;
            padding: 30px 38px 38px 38px;
        }
        .profile-container h2 {
            color: #2b5cbc; letter-spacing: 1px; margin-bottom: 22px;
            font-size: 1.6em; font-weight: 800;
        }
        .form-row { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; color: #355a99; }
        input[type=text], input[type=password], select {
            width: 100%; padding: 9px 12px; border-radius: 7px; border: 1.5px solid #b5bee5;
            font-size: 1.05em; background: #f7fafd;
        }
        button {
            padding: 10px 27px;
            background: #2b5cbc; color: #fff; border: none; border-radius: 7px;
            font-weight: 600; font-size: 1.07em; cursor: pointer; transition: background 0.14s;
            margin-top: 8px;
        }
        button:hover { background: #243570; }
        .msg-success { color: #219150; margin-bottom: 16px; }
        .msg-error { color: #d42d2d; margin-bottom: 16px; }
        .section-title { color: #24478c; font-size: 1.13em; font-weight: 700; margin-bottom: 8px; }
        hr { border: none; border-top: 1.5px solid #e6e6f6; margin: 30px 0; }
        @media (max-width: 700px) {
            .profile-container { padding: 16px 5vw; }
        }
    </style>
</head>
<body>
<div class="profile-container">
    <h2>My Profile</h2>
    <?php if ($success_msg): ?>
        <div class="msg-success"><?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="msg-error"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <div class="section-title">Profile Details</div>
        <div class="form-row">
            <label for="username">Username</label>
            <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" maxlength="50" required>
        </div>
        <div class="form-row">
            <label for="full_name">Full Name</label>
            <input type="text" name="full_name" value="<?= htmlspecialchars($full_name) ?>" maxlength="100" required>
        </div>
        <div class="form-row">
            <label for="status">Status</label>
            <select name="status">
                <option value="active" <?= $status == 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $status == 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <button type="submit" name="update_profile">Update Profile</button>
    </form>

    <hr>

    <form method="post" autocomplete="off">
        <div class="section-title">Change Password</div>
        <div class="form-row">
            <label for="current_password">Current Password</label>
            <input type="password" name="current_password" required minlength="6">
        </div>
        <div class="form-row">
            <label for="new_password">New Password</label>
            <input type="password" name="new_password" required minlength="6">
        </div>
        <div class="form-row">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" name="confirm_password" required minlength="6">
        </div>
        <button type="submit" name="update_password">Update Password</button>
    </form>
</div>
</body>
</html>