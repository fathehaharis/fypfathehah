<?php
include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Auth check
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

$flash = $error = $add_error = $add_success = "";

// Handle delete staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff'], $_POST['staff_id'], $_POST['csrf_token'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'])) {
        $error = "Invalid session token.";
    } else {
        $staff_id = intval($_POST['staff_id']);
        $stmt = $conn->prepare("DELETE FROM delivery_staff WHERE staff_id=?");
        $stmt->bind_param("i", $staff_id);
        $stmt->execute();
        $stmt->close();
        $flash = "Delivery staff deleted.";
        header("Location: delivery_staff.php?flash=" . urlencode($flash));
        exit;
    }
}

// Handle add staff
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'], $_POST['csrf_token'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'])) {
        $add_error = "Invalid session token.";
    } else {
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']);
        $password = $_POST['password'];
        $status = (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'inactive' : 'active';

        if ($username == "" || $full_name == "" || $password == "") {
            $add_error = "Please fill in all required fields.";
        } else {
            $stmt = $conn->prepare("SELECT staff_id FROM delivery_staff WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $add_error = "Username already exists.";
            } else {
                $hashed_pw = password_hash($password, PASSWORD_DEFAULT);
                $stmt2 = $conn->prepare("INSERT INTO delivery_staff (username, full_name, password, status) VALUES (?, ?, ?, ?)");
                $stmt2->bind_param("ssss", $username, $full_name, $hashed_pw, $status);
                if ($stmt2->execute()) {
                    $add_success = "Delivery staff added successfully.";
                } else {
                    $add_error = "Error adding staff: " . $conn->error;
                }
                $stmt2->close();
            }
            $stmt->close();
        }
    }
}

// Flash from GET
if (isset($_GET['flash']) && $_GET['flash']) {
    $flash = htmlspecialchars($_GET['flash']);
}
?>
<?php include 'admin_header.php'; ?>

<style>
body { background: #f7f9fa; }
.staff-table { table-layout: fixed;width: 100%;border-collapse: collapse;margin:24px 0 40px 0;background: #fff;box-shadow: 0 2px 12px #e0e7ef55;border-radius: 12px;overflow: hidden;}
.staff-table th, .staff-table td { padding:12px 13px;border-bottom:1px solid #eef2fa;text-align:left;vertical-align:middle;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.staff-table th {background:#f8fafd;font-weight:700;color:#2b5cbc;letter-spacing:0.5px;}
.staff-table tr:last-child td { border-bottom: none;}
.edit-btn, .delete-btn {background: #eaf1fa;color: #2b5cbc;padding: 6px 14px;border-radius: 7px;text-decoration: none;font-size: 1em;font-weight: 600;transition: background 0.13s, color 0.13s;border: none;cursor: pointer;}
.edit-btn:hover {background:#d2ebfd;color:#183c7c;}
.delete-btn {background:#d92222;color:#fff;transition: background 0.13s;}
.delete-btn:hover{background:#a51111;}
.staff-form {border:1.5px solid #d6d6f3;background:#f8f8fc;border-radius:10px;max-width:420px;margin:0 auto 36px auto;padding:24px 28px;}
.staff-form h3 {margin-top:0;color:#234c96;}
.msg-success {color:#219150;margin-bottom:16px;}
.msg-error {color:#d42d2d;margin-bottom:16px;}
.form-row {margin-bottom:15px;}
.form-label {display:inline-block;width:100px;}
.form-input {width:220px;}
@media(max-width:1200px){.staff-table th,.staff-table td{font-size:0.96em;padding:7px 4px;}}
.breadcrumb {font-size:1em;color:#92a2b3;margin-bottom:10px;}
.breadcrumb a {color:#6d87be;text-decoration: none;font-weight: 600;}
</style>

<div style="max-width:900px;margin:38px auto 25px auto;">
    <div class="breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Delivery Staff
    </div>
    <?php if ($flash): ?><div class="msg-success"><?= $flash ?></div><?php endif; ?>
    <?php if ($error): ?><div class="msg-error"><?= $error ?></div><?php endif; ?>

    <div class="staff-form">
        <h3>Add Delivery Staff</h3>
        <?php if ($add_success): ?>
            <div class="msg-success"><?= htmlspecialchars($add_success) ?></div>
        <?php endif; ?>
        <?php if ($add_error): ?>
            <div class="msg-error"><?= htmlspecialchars($add_error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <div class="form-row">
                <label class="form-label" for="username">Username<span style="color:#d42d2d;">*</span></label>
                <input class="form-input" type="text" name="username" id="username" required maxlength="50">
            </div>
            <div class="form-row">
                <label class="form-label" for="full_name">Full Name<span style="color:#d42d2d;">*</span></label>
                <input class="form-input" type="text" name="full_name" id="full_name" required maxlength="100">
            </div>
            <div class="form-row">
                <label class="form-label" for="password">Password<span style="color:#d42d2d;">*</span></label>
                <input class="form-input" type="password" name="password" id="password" required minlength="6">
            </div>
            <div class="form-row">
                <label class="form-label" for="status">Status</label>
                <select class="form-input" name="status" id="status">
                    <option value="active" selected>Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div class="form-row">
                <button type="submit" name="add_staff" class="edit-btn">Add Staff</button>
            </div>
        </form>
    </div>

    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Delivery Staff List</h2>
    <div style="overflow-x:auto;">
    <table class="staff-table">
        <thead>
            <tr>
                <th style="width:60px;">#</th>
                <th style="width:140px;">Username</th>
                <th style="width:180px;">Full Name</th>
                <th style="width:100px;">Status</th>
                <th style="width:140px;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $stafflist_res = $conn->query("SELECT * FROM delivery_staff ORDER BY staff_id DESC");
        $i = 1;
        while ($staff = $stafflist_res->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($staff['username']) ?></td>
                <td><?= htmlspecialchars($staff['full_name']) ?></td>
                <td><?= htmlspecialchars(ucfirst($staff['status'])) ?></td>
                <td style="display:flex;gap:7px;">
                    <a class="edit-btn" href="edit_delivery_staff.php?id=<?= $staff['staff_id'] ?>">Edit</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this staff?');">
                        <input type="hidden" name="staff_id" value="<?= $staff['staff_id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <button type="submit" name="delete_staff" class="delete-btn">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    </div>
</div>
<?php include '../includes/footer.php'; ?>