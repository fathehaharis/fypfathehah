<?php
include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Handle delete delivery staff
if (isset($_POST['delete_staff']) && isset($_POST['staff_id'])) {
    $staff_id = intval($_POST['staff_id']);
    $conn->query("DELETE FROM delivery_staff WHERE staff_id=$staff_id");
    header("Location: services.php");
    exit;
}

// Handle add delivery staff
$add_error = $add_success = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
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

// Handle staff assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_service'])) {
    $service_id = intval($_POST['service_id']);
    $staff_id = !empty($_POST['staff_id']) ? intval($_POST['staff_id']) : null;
    $sql = "UPDATE service SET staff_id = ? WHERE service_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $staff_id, $service_id);
    $stmt->execute();
    $stmt->close();
    header("Location: services.php");
    exit;
}

// Pagination
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$page = max($page, 1);
$offset = ($page - 1) * $per_page;

// Filtering
$where = 'WHERE 1=1';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where .= " AND (
        s.service_type LIKE '%$safe_search%' OR 
        s.notes LIKE '%$safe_search%' OR 
        cu.username LIKE '%$safe_search%' OR
        c.car_brand LIKE '%$safe_search%' OR
        c.car_model LIKE '%$safe_search%' OR
        b.booking_id LIKE '%$safe_search%'
    )";
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) AS total 
    FROM service s 
    JOIN booking b ON s.booking_id = b.booking_id
    JOIN car c ON b.car_id = c.car_id
    JOIN customer cu ON b.cust_id = cu.cust_id
    $where";
$count_result = $conn->query($count_sql);
$total = 0;
if ($row = $count_result->fetch_assoc()) {
    $total = intval($row['total']);
}
$total_pages = ceil($total / $per_page);

// Fetch all delivery staff (for assignment dropdown)
$staff_res = $conn->query("SELECT staff_id, full_name FROM delivery_staff WHERE status = 'active'");

// Fetch all services -- Use pickup_datetime and return_datetime
$query = "SELECT s.*, b.cust_id, c.car_brand, c.car_model, cu.username AS customer_name,
                 b.pickup_datetime, b.return_datetime
          FROM service s
          JOIN booking b ON s.booking_id = b.booking_id
          JOIN car c ON b.car_id = c.car_id
          JOIN customer cu ON b.cust_id = cu.cust_id
          $where
          ORDER BY s.service_id DESC
          LIMIT $per_page OFFSET $offset";
$result = $conn->query($query);

$services = [];
while ($row = $result->fetch_assoc()) {
    $services[] = $row;
}
// fetch all staff for display in table
$all_staff = [];
$sres = $conn->query("SELECT staff_id, full_name FROM delivery_staff");
while($r = $sres->fetch_assoc()) {
    $all_staff[$r['staff_id']] = $r['full_name'];
}
?>
<?php include 'admin_header.php'; ?>

<style>
body {
    background: #f7f9fa;
}
.services-table {
    table-layout: fixed;
    width: 100%;
    border-collapse: collapse;
    margin: 24px 0 40px 0;
    background: #fff;
    box-shadow: 0 2px 12px #e0e7ef55;
    border-radius: 12px;
    overflow: hidden;
}
.services-table th, .services-table td {
    padding: 12px 13px;
    border-bottom: 1px solid #eef2fa;
    text-align: left;
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.services-table th {
    background: #f8fafd;
    font-weight: 700;
    color: #2b5cbc;
    letter-spacing: 0.5px;
}
.services-table tr:last-child td {
    border-bottom: none;
}
.edit-btn, .assign-btn, .delete-btn {
    background: #eaf1fa;
    color: #2b5cbc;
    padding: 6px 14px;
    border-radius: 7px;
    text-decoration: none;
    font-size: 1em;
    font-weight: 600;
    transition: background 0.13s, color 0.13s;
    border: none;
    cursor: pointer;
    display: inline-block;
}
.edit-btn:hover, .assign-btn:hover {
    background: #d2ebfd;
    color: #183c7c;
}
.pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin: 10px 0 18px 0;
    align-items: center;
    justify-content: center;
}
.pagination a, .pagination span {
    padding: 7px 13px;
    border-radius: 6px;
    background: #f5f5fd;
    color: #2b5cbc;
    text-decoration: none;
    font-weight: 600;
    border: 1.5px solid #e4e8f3;
    min-width: 31px;
    text-align: center;
    transition: background 0.12s, color 0.12s;
}
.pagination a:hover {
    background: #2b5cbc;
    color: #fff;
}
.pagination .current {
    background: #2b5cbc;
    color: #fff;
    border-color: #2b5cbc;
    pointer-events: none;
}
.services-search-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    align-items: center;
    flex-wrap: wrap;
}
.services-search-bar input[type=text] {
    padding: 9px 14px;
    border-radius: 7px;
    border: 1.5px solid #b5bee5;
    font-size: 1.05em;
    background: #f7fafd;
    width: 200px;
    max-width: 50vw;
}
.services-search-bar button {
    padding: 9px 19px;
    background: #2b5cbc;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-weight: 600;
    font-size: 1.03em;
    cursor: pointer;
    transition: background 0.14s;
}
.services-search-bar button:hover {
    background: #243570;
}
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
@media (max-width: 1200px) {
    .services-table th, .services-table td { font-size: 0.96em; padding: 7px 4px;}
    .services-search-bar input[type=text] { width: 120px;}
}
.services-breadcrumb {
    font-size: 1em;
    color: #92a2b3;
    margin-bottom: 10px;
}
.services-breadcrumb a {
    color: #6d87be;
    text-decoration: none;
    font-weight: 600;
}
.delete-btn {
    background: #d92222;
    color: #fff;
    padding: 6px 14px;
    border-radius: 7px;
    text-decoration: none;
    font-size: 1em;
    font-weight: 600;
    border: none;
    cursor: pointer;
    display: inline-block;
    transition: background 0.13s;
}
.delete-btn:hover {
    background: #a51111;
}
</style>

<div style="max-width:1400px;margin:38px auto 25px auto;">
    <div class="services-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Services & Delivery Staff
    </div>

    <div class="staff-form">
        <h3>Add Delivery Staff</h3>
        <?php if ($add_success): ?>
            <div class="msg-success"><?= htmlspecialchars($add_success) ?></div>
        <?php endif; ?>
        <?php if ($add_error): ?>
            <div class="msg-error"><?= htmlspecialchars($add_error) ?></div>
        <?php endif; ?>
        <form method="post" autocomplete="off">
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
                <button type="submit" name="add_staff" class="assign-btn">Add Staff</button>
            </div>
        </form>
    </div>

<!-- DELIVERY STAFF TABLE (Only Staff Info) -->
<h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Delivery Staff</h2>
<div style="overflow-x:auto;">
<table class="services-table">
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
                <form method="post" onsubmit="return confirm('Are you sure you want to delete this staff?');" style="display:inline;">
                    <input type="hidden" name="staff_id" value="<?= $staff['staff_id'] ?>">
                    <button type="submit" name="delete_staff" class="delete-btn" style="background:#d92222;color:#fff;">Delete</button>
                </form>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>
</div>

    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Services & Delivery Assignment</h2>
    <form class="services-search-bar" method="get" action="services.php" autocomplete="off">
        <input type="text" name="search" placeholder="Search service, customer, car..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Filter</button>
        <?php if ($search): ?>
            <a href="services.php" style="margin-left:15px;color:#888;font-size:0.98em;">Clear</a>
        <?php endif; ?>
    </form>
    <div style="overflow-x:auto;">
    <table class="services-table">
        <thead>
            <tr>
                <th style="width:50px;">#</th>
                <th style="width:110px;">Service Type</th>
                <th style="width:90px;">Booking ID</th>
                <th style="width:120px;">Customer</th>
                <th style="width:130px;">Car</th>
                <th style="width:90px;">Fee (RM)</th>
                <th style="width:130px;">Pickup DateTime</th>
                <th style="width:130px;">Return DateTime</th>
                <th style="width:180px;">Location</th>
                <th style="width:120px;">Status</th>
                <th style="width:150px;">Assigned Staff</th>
                <th style="width:100px;">Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($services)): ?>
            <tr><td colspan="12" style="text-align:center;color:#888;">No services found.</td></tr>
        <?php else: ?>
            <?php foreach ($services as $i => $row): ?>
                <tr>
                    <form method="post">
                    <td><?= $offset + $i + 1 ?></td>
                    <td><?= htmlspecialchars($row['service_type']) ?></td>
                    <td><?= htmlspecialchars($row['booking_id']) ?></td>
                    <td><?= htmlspecialchars($row['customer_name']) ?></td>
                    <td><?= htmlspecialchars($row['car_brand'].' '.$row['car_model']) ?></td>
                    <td><?= number_format($row['fee'], 2) ?></td>
                    <td>
                        <?= $row['pickup_datetime'] ? date('d/m/Y H:i', strtotime($row['pickup_datetime'])) : '-' ?>
                    </td>
                    <td>
                        <?= $row['return_datetime'] ? date('d/m/Y H:i', strtotime($row['return_datetime'])) : '-' ?>
                    </td>
                    <td><?= htmlspecialchars($row['notes']) ?></td>
                    <td>
                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['status']))) ?>
                    </td>
                    <td>
                        <select name="staff_id">
                            <option value="">-- Unassigned --</option>
                            <?php
                            mysqli_data_seek($staff_res, 0);
                            while ($staff = $staff_res->fetch_assoc()):
                                $sel = (isset($row['staff_id']) && $row['staff_id'] == $staff['staff_id']) ? 'selected' : '';
                            ?>
                                <option value="<?= $staff['staff_id'] ?>" <?= $sel ?>><?= htmlspecialchars($staff['full_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                        <?php if (isset($row['staff_id']) && $row['staff_id'] && isset($all_staff[$row['staff_id']])): ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="hidden" name="service_id" value="<?= $row['service_id'] ?>">
                        <button type="submit" name="update_service" class="assign-btn">Save</button>
                    </td>
                    </form>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1<?= $search ? '&search=' . urlencode($search) : '' ?>">&laquo; First</a>
            <a href="?page=<?= $page-1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">&lt; Prev</a>
        <?php endif; ?>

        <?php
        $range = 2;
        for ($p = max(1, $page - $range); $p <= min($total_pages, $page + $range); $p++): ?>
            <?php if ($p == $page): ?>
            <?php else: ?>
                <a href="?page=<?= $p ?><?= $search ? '&search=' . urlencode($search) : '' ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">Next &gt;</a>
            <a href="?page=<?= $total_pages ?><?= $search ? '&search=' . urlencode($search) : '' ?>">Last &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>