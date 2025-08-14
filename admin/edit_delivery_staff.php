<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

/* ---------------- CSRF ---------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/* -------------- Helpers -------------- */
function h($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

/* -------------- Phone Validation (Malaysia Mobile) -------------- */
/*
 * Valid Malaysian mobile format (local): 
 *  - Starts with 01
 *  - Next digit NOT 5 (exclude legacy 015 ranges except fixed line services)
 *  - Total digits: 10 or 11 (so remaining 7 or 8 digits)
 * Pattern used: ^01[0-46-9][0-9]{7,8}$
 * Accept only digits (no +6 or country code in this field). Optional field.
 */
function isValidMYPhone($phone) {
    $digits = preg_replace('/\D/', '', $phone); // strip non-digits
    return (bool)preg_match('/^01[0-46-9][0-9]{7,8}$/', $digits);
}

$staff_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($staff_id < 1) {
    header("Location: delivery_staff.php");
    exit;
}

/* Fetch staff (initial) */
$stmtFetch = $conn->prepare("SELECT staff_id, username, full_name, phone_number, status FROM delivery_staff WHERE staff_id = ?");
$stmtFetch->bind_param("i", $staff_id);
$stmtFetch->execute();
$staff = $stmtFetch->get_result()->fetch_assoc();
$stmtFetch->close();

if (!$staff) {
    header("Location: delivery_staff.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* CSRF check */
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $error = "Invalid session token. Please reload the page.";
    } else {

        $username    = trim($_POST['username'] ?? '');
        $full_name   = trim($_POST['full_name'] ?? '');
        $phone_raw   = trim($_POST['phone_number'] ?? '');
        $status_raw  = $_POST['status'] ?? 'active';
        $status      = ($status_raw === 'inactive') ? 'inactive' : 'active';
        $password    = $_POST['password'] ?? '';

        // Basic validations
        if ($username === '' || $full_name === '') {
            $error = "Username and Full Name are required.";
        } elseif (!preg_match('/^[A-Za-z0-9_]{3,50}$/', $username)) {
            $error = "Username must be 3–50 characters, letters, numbers, underscore only.";
        } elseif (mb_strlen($full_name) > 100) {
            $error = "Full Name cannot exceed 100 characters.";
        } elseif ($password !== '' && strlen($password) < 6) {
            $error = "Password must be at least 6 characters (or leave blank to keep existing).";
        } elseif ($phone_raw !== '' && !isValidMYPhone($phone_raw)) {
            $error = "Phone number must be a valid Malaysian mobile (10 or 11 digits, starts with 01).";
        }

        // Normalize phone to digits if provided
        $phone_number = $phone_raw === '' ? '' : preg_replace('/\D/', '', $phone_raw);

        if (!$error) {
            // Only check duplicate if username changed
            if (strcasecmp($username, $staff['username']) !== 0) {
                $stmtDup = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE username = ? LIMIT 1");
                $stmtDup->bind_param("s", $username);
                $stmtDup->execute();
                $stmtDup->store_result();
                if ($stmtDup->num_rows > 0) {
                    $error = "Username already exists.";
                }
                $stmtDup->close();
            }
        }

        if (!$error) {
            if ($password !== '') {
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                $stmtUpdate = $conn->prepare("
                    UPDATE delivery_staff 
                    SET username = ?, full_name = ?, phone_number = ?, password = ?, status = ? 
                    WHERE staff_id = ?
                ");
                $stmtUpdate->bind_param("sssssi", $username, $full_name, $phone_number, $hashed_pw, $status, $staff_id);
            } else {
                $stmtUpdate = $conn->prepare("
                    UPDATE delivery_staff 
                    SET username = ?, full_name = ?, phone_number = ?, status = ? 
                    WHERE staff_id = ?
                ");
                $stmtUpdate->bind_param("ssssi", $username, $full_name, $phone_number, $status, $staff_id);
            }

            if ($stmtUpdate->execute()) {
                $success = "Staff updated successfully.";
                // Refresh staff details
                $stmtRefetch = $conn->prepare("SELECT staff_id, username, full_name, phone_number, status FROM delivery_staff WHERE staff_id = ?");
                $stmtRefetch->bind_param("i", $staff_id);
                $stmtRefetch->execute();
                $staff = $stmtRefetch->get_result()->fetch_assoc();
                $stmtRefetch->close();
            } else {
                $error = "Error updating staff. Please try again.";
            }
            $stmtUpdate->close();
        }
    }
}

?>
<?php include 'admin_header.php'; ?>
<style>
.staff-form {
    border: 1.5px solid #d6d6f3;
    background: #f8f8fc;
    border-radius: 10px;
    max-width: 520px;
    margin: 42px auto 48px;
    padding: 28px 32px 30px;
    box-shadow: 0 4px 14px -6px rgba(40,60,120,.15);
}
.staff-form h3 {
    margin: 0 0 18px;
    color: #234c96;
    font-size: 1.15rem;
    letter-spacing: .5px;
}
.msg {
    padding: 10px 14px;
    border-radius: 8px;
    font-size: .83rem;
    margin-bottom: 14px;
    line-height: 1.3;
}
.msg-success { background:#e6f9ed; border:1px solid #b8e7c7; color:#156a36; }
.msg-error { background:#ffe9e7; border:1px solid #f5c1b8; color:#a23224; }
.form-row { margin-bottom: 16px; display:flex; flex-direction:column; }
.form-row label { font-size:.72rem; font-weight:700; letter-spacing:.6px; color:#2a3f67; margin-bottom:5px; text-transform:uppercase; }
.form-row label span.req { color:#d42d2d; margin-left:3px; }
.form-row input[type=text],
.form-row input[type=password],
.form-row select {
    padding:9px 11px;
    border:1.4px solid #c7cee2;
    border-radius:7px;
    background:#fbfcff;
    font-size:.82rem;
    transition:border .15s, background .15s;
    width:100%;
    box-sizing:border-box;
}
.form-row input:focus,
.form-row select:focus { outline:none; border-color:#365ec9; background:#fff; }
.hint { font-size:.63rem; color:#56627a; margin-top:4px; }
.actions { margin-top:8px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.btn {
    padding:9px 20px;
    background:#3b55c4;
    color:#fff;
    border:none;
    border-radius:7px;
    cursor:pointer;
    font-size:.78rem;
    font-weight:600;
    letter-spacing:.4px;
    transition:background .18s;
    text-decoration:none;
    display:inline-block;
}
.btn:hover { background:#24459e; }
.btn-secondary {
    background:#cfd5e5;
    color:#24395f;
}
.btn-secondary:hover { background:#b9c2d6; }
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
.inline-note { font-size:.63rem; color:#56627a; margin-top:4px; }
</style>

<div class="staff-form">
    <h3>Edit Delivery Staff</h3>

    <?php if ($success): ?>
        <div class="msg msg-success"><?= h($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="msg msg-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">

        <div class="form-row">
            <label for="username">Username<span class="req">*</span></label>
            <input type="text" name="username" id="username" maxlength="50" required
                   pattern="[A-Za-z0-9_]{3,50}" value="<?= h($staff['username']) ?>">
            <div class="hint">3–50 chars, letters/numbers/underscore only.</div>
        </div>

        <div class="form-row">
            <label for="full_name">Full Name<span class="req">*</span></label>
            <input type="text" name="full_name" id="full_name" maxlength="100" required
                   value="<?= h($staff['full_name']) ?>">
        </div>

        <div class="form-row">
            <label for="phone_number">Phone Number (MY)</label>
            <input type="text"
                   name="phone_number"
                   id="phone_number"
                   value="<?= h($staff['phone_number']) ?>"
                   maxlength="11"
                   minlength="10"
                   pattern="^01[0-46-9][0-9]{7,8}$"
                   placeholder="e.g. 0123456789"
                   title="Valid Malaysian mobile (10 or 11 digits, starts with 01)">
            <div class="hint">Optional. Digits only, must start with 01 (mobile), 10 or 11 digits.</div>
        </div>

        <div class="form-row">
            <label for="password">Password (optional)</label>
            <div class="password-wrapper">
                <input type="password" name="password" id="password" minlength="6" placeholder="Leave blank to keep current">
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
            <div class="inline-note">Leave blank to keep existing password.</div>
        </div>

        <div class="form-row">
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="active"   <?= $staff['status']==='active'?'selected':''; ?>>Active</option>
                <option value="inactive" <?= $staff['status']==='inactive'?'selected':''; ?>>Inactive</option>
            </select>
        </div>

        <div class="actions">
            <button type="submit" class="btn">Save Changes</button>
            <a href="delivery_staff.php" class="btn btn-secondary">Back</a>
        </div>
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