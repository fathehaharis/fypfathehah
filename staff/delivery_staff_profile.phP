<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../connect.php';
session_start();

if (!isset($_SESSION['staff_id'])) {
    header("Location: delivery_staff_login.php");
    exit;
}

$staff_id = $_SESSION['staff_id'];

// Malaysian phone validation helper
function isValidMYPhone($phone) {
    $clean = preg_replace('/\D/', '', $phone);
    return preg_match('/^01[0-46-9][0-9]{7,8}$/', $clean);
}

$success_msg_profile = $error_msg_profile = "";
$success_msg_pwd = $error_msg_pwd = "";

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name']);
    $username = trim($_POST['username']);
    $phone_number = trim($_POST['phone_number']);
    $status = ($_POST['status'] === 'inactive') ? 'inactive' : 'active';

    if ($full_name == "" || $username == "") {
        $error_msg_profile = "Full name and username cannot be empty.";
    } elseif ($phone_number != "" && !isValidMYPhone($phone_number)) {
        $error_msg_profile = "Phone number must be a valid Malaysian mobile number (10 or 11 digits, starts with 01).";
    } else {
        // Check if username is taken by someone else
        $stmt = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE username = ? AND staff_id != ?");
        $stmt->bind_param("si", $username, $staff_id);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error_msg_profile = "Username is already taken by another user.";
        } else {
            // Update profile (include phone_number)
            $stmt2 = $conn->prepare("UPDATE delivery_staff SET full_name = ?, username = ?, phone_number = ?, status = ? WHERE staff_id = ?");
            $stmt2->bind_param("ssssi", $full_name, $username, $phone_number, $status, $staff_id);
            if ($stmt2->execute()) {
                $success_msg_profile = "Profile updated successfully.";
                $_SESSION['staff_name'] = $full_name;
            } else {
                $error_msg_profile = "Failed to update profile.";
            }
            $stmt2->close();
        }
        $stmt->close();
    }
}

// Handle password update (with fix for double close)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password != $confirm_password) {
        $error_msg_pwd = "New passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT password FROM delivery_staff WHERE staff_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $staff_id);
            $stmt->execute();
            $stmt->bind_result($hash);
            $found = $stmt->fetch();
            $stmt->close(); // Only close ONCE

            if ($found) {
                if (password_verify($current_password, $hash)) {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt2 = $conn->prepare("UPDATE delivery_staff SET password = ? WHERE staff_id = ?");
                    if ($stmt2) {
                        $stmt2->bind_param("si", $new_hash, $staff_id);
                        if ($stmt2->execute()) {
                            $success_msg_pwd = "Password updated successfully.";
                        } else {
                            $error_msg_pwd = "Failed to update password.";
                        }
                        $stmt2->close();
                    } else {
                        $error_msg_pwd = "Failed to prepare update statement.";
                    }
                } else {
                    $error_msg_pwd = "Current password is incorrect.";
                }
            } else {
                $error_msg_pwd = "Could not fetch hash from database.";
            }
        } else {
            $error_msg_pwd = "Failed to prepare select statement.";
        }
    }
}

// Fetch staff profile (include phone_number)
$stmt = $conn->prepare("SELECT username, full_name, phone_number, status FROM delivery_staff WHERE staff_id = ?");
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$stmt->bind_result($username, $full_name, $phone_number, $status);
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
        .form-row { margin-bottom: 20px; position:relative; }
        label { display: block; margin-bottom: 5px; color: #355a99; }
        input[type=text], input[type=password], select {
            width: 100%; padding: 9px 12px; border-radius: 7px; border: 1.5px solid #b5bee5;
            font-size: 1.05em; background: #f7fafd;
        }
        .eye-icon {
            position: absolute;
            right: 14px;
            top: 38px;
            width: 22px;
            height: 22px;
            cursor: pointer;
            color: #b5bee5;
            transition: color .17s;
            z-index: 3;
        }
        .eye-icon:hover { color: #2b5cbc; }
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
    <!-- Profile update messages -->
    <?php if ($success_msg_profile): ?>
        <div class="msg-success"><?= htmlspecialchars($success_msg_profile) ?></div>
    <?php elseif ($error_msg_profile): ?>
        <div class="msg-error"><?= htmlspecialchars($error_msg_profile) ?></div>
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
            <label for="phone_number">Phone Number</label>
            <input type="text" name="phone_number" value="<?= htmlspecialchars($phone_number) ?>"
                   maxlength="11" minlength="10"
                   pattern="^01[0-46-9][0-9]{7,8}$"
                   placeholder="e.g. 0123456789"
                   title="Enter a valid Malaysian mobile number (10 or 11 digits, starts with 01)">
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

    <!-- Password update messages -->
    <?php if ($success_msg_pwd): ?>
        <div class="msg-success"><?= htmlspecialchars($success_msg_pwd) ?></div>
    <?php elseif ($error_msg_pwd): ?>
        <div class="msg-error"><?= htmlspecialchars($error_msg_pwd) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <div class="section-title">Change Password</div>
        <div class="form-row">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" required minlength="6" autocomplete="current-password">
            <span class="eye-icon" onclick="togglePassword('current_password', this)">
                <!-- Eye SVG -->
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="M1.5 12C3.5 6.5 8 3.5 12 3.5C16 3.5 20.5 6.5 22.5 12C20.5 17.5 16 20.5 12 20.5C8 20.5 3.5 17.5 1.5 12Z" stroke="currentColor" stroke-width="1.5"/>
                  <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.5"/>
                  <circle cx="12" cy="12" r="2" fill="currentColor"/>
                </svg>
            </span>
        </div>
        <div class="form-row">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" required minlength="6" autocomplete="new-password">
            <span class="eye-icon" onclick="togglePassword('new_password', this)">
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="M1.5 12C3.5 6.5 8 3.5 12 3.5C16 3.5 20.5 6.5 22.5 12C20.5 17.5 16 20.5 12 20.5C8 20.5 3.5 17.5 1.5 12Z" stroke="currentColor" stroke-width="1.5"/>
                  <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.5"/>
                  <circle cx="12" cy="12" r="2" fill="currentColor"/>
                </svg>
            </span>
        </div>
        <div class="form-row">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
            <span class="eye-icon" onclick="togglePassword('confirm_password', this)">
                <svg viewBox="0 0 24 24" fill="none">
                  <path d="M1.5 12C3.5 6.5 8 3.5 12 3.5C16 3.5 20.5 6.5 22.5 12C20.5 17.5 16 20.5 12 20.5C8 20.5 3.5 17.5 1.5 12Z" stroke="currentColor" stroke-width="1.5"/>
                  <circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.5"/>
                  <circle cx="12" cy="12" r="2" fill="currentColor"/>
                </svg>
            </span>
        </div>
        <button type="submit" name="update_password">Update Password</button>
    </form>
</div>
<script>
function togglePassword(inputId, iconSpan) {
    const input = document.getElementById(inputId);
    if (input.type === "password") {
        input.type = "text";
        iconSpan.style.color = "#2b5cbc";
    } else {
        input.type = "password";
        iconSpan.style.color = "#b5bee5";
    }
}
</script>
</body>
</html>