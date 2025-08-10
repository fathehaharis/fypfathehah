<?php
session_start();
include '../connect.php';

// Only admin can edit
if (!isset($_SESSION['admin_id'])) {
    header("Location: /admin_login.php");
    exit;
}

$admin_id = $_SESSION['admin_id'];
$table = "admin";
$fields = ['full_name', 'username', 'email'];
$back_url = "admin_dashboard.php";

// Handle form submission
$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $update_data = [];
    $params = [];
    $types = "";

    foreach ($fields as $field) {
        $value = trim($_POST[$field]);
        $update_data[] = "$field = ?";
        $params[] = $value;
        $types .= "s";
    }

    // Password logic
    $password = trim($_POST['password'] ?? '');
    if (!empty($password)) {
        if (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
            $update_data[] = "password = ?";
            $params[] = $hashed_pw;
            $types .= "s";
        }
    }

    if (!$error) {
        $params[] = $admin_id;
        $types .= "i";
        $sql = "UPDATE $table SET " . implode(', ', $update_data) . " WHERE admin_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $success = "Profile updated successfully.";
        } else {
            $error = "Failed to update profile: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Fetch current admin data for the form
$stmt = $conn->prepare("SELECT full_name, username, email FROM $table WHERE admin_id = ?");
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

include 'admin_header.php';

function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.edit-profile-container {
    max-width: 540px;
    margin: 40px auto 60px auto;
    background: #fff;
    padding: 36px 38px 32px 38px;
    border-radius: 13px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.09);
}
.edit-profile-title {
    font-size: 1.4em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 20px;
    text-align: center;
}
.edit-profile-form label {
    display: block;
    margin-bottom: 6px;
    margin-top: 15px;
    color: #3c4cb8;
    font-weight: 600;
}
.edit-profile-form input {
    width: 100%;
    padding: 10px 11px;
    border-radius: 8px;
    border: 1.4px solid #b5bee5;
    font-size: 1.07em;
    background: #f7fafd;
    margin-bottom: 2px;
}
.password-wrapper {
    position:relative;
    display:block;
}
.password-wrapper input {
    padding-right:42px;
}
.toggle-eye {
    position:absolute;
    top:50%;
    right:10px;
    transform:translateY(-50%);
    background:none;
    border:none;
    padding:4px;
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#365ec9;
}
.toggle-eye:focus { outline:2px solid #365ec9; outline-offset:2px; border-radius:4px; }
.toggle-eye svg {
    width:22px;
    height:22px;
    stroke:currentColor;
    stroke-width:2;
    fill:none;
}
.toggle-eye .eye-off { display:none; }
.toggle-eye.showing .eye-on { display:none; }
.toggle-eye.showing .eye-off { display:inline; }
.edit-profile-btn {
    margin-top: 22px;
    display: block;
    width: 100%;
    background: #3c4cb8;
    color: #fff;
    border: none;
    padding: 12px 0;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.18s;
    text-align: center;
    text-decoration: none;
}
.edit-profile-btn:hover {
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
.success-msg {
    background:#e0ffe0;
    color:#2b5c2b;
    font-weight:600;
    padding:12px;
    border-radius:7px;
    margin-bottom:15px;
    text-align:center;
}
.error-msg {
    background:#ffe0e0;
    color:#c22b2b;
    font-weight:600;
    padding:12px;
    border-radius:7px;
    margin-bottom:15px;
    text-align:center;
}
.inline-note { font-size:.63rem; color:#56627a; margin-top:4px; }
</style>

<div class="edit-profile-container">
    <div class="edit-profile-title">Edit Profile</div>
    <?php if ($success): ?><div class="success-msg"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="error-msg"><?= h($error) ?></div><?php endif; ?>
    <form class="edit-profile-form" method="post" autocomplete="off">
        <label for="full_name">Full Name</label>
        <input required type="text" name="full_name" id="full_name" maxlength="80" value="<?= h($user['full_name']) ?>">
        
        <label for="username">Username</label>
        <input required type="text" name="username" id="username" maxlength="40" value="<?= h($user['username']) ?>">

        <label for="email">Email</label>
        <input required type="email" name="email" id="email" maxlength="80" value="<?= h($user['email']) ?>">

        <label for="password">Password (leave blank to keep current)</label>
        <div class="password-wrapper">
            <input type="password" name="password" id="password" minlength="6" placeholder="New password">
            <button type="button" id="pwToggle" class="toggle-eye" aria-label="Show password">
                <!-- Open eye -->
                <svg class="eye-on" viewBox="0 0 24 24">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12Z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
                <!-- Closed eye -->
                <svg class="eye-off" viewBox="0 0 24 24">
                    <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.77 21.77 0 0 1 5.06-6.94M9.9 4.24A10.73 10.73 0 0 1 12 4c7 0 11 8 11 8a21.835 21.835 0 0 1-2.69 3.74M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                </svg>
            </button>
        </div>
        <div class="inline-note">Leave blank to keep your current password. Passwords are stored securely (hashed).</div>

        <button type="submit" class="edit-profile-btn">Save Changes</button>
        <button type="button" class="back-btn" onclick="window.location.href='profile.php'">Back</button>
    </form>
</div>

<script>
(function(){
    const pw = document.getElementById('password');
    const btn = document.getElementById('pwToggle');
    if(!pw || !btn) return;
    btn.addEventListener('click', () => {
        const showing = pw.type === 'password';
        pw.type = showing ? 'text' : 'password';
        btn.classList.toggle('showing', showing);
        btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
    });
})();
</script>

<?php include '../includes/footer.php'; ?>