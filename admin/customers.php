<?php
include '../connect.php';

session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Handle search filter
$search = '';
$where = '';
if (isset($_GET['search']) && trim($_GET['search']) !== '') {
    $search = trim($_GET['search']);
    $safe_search = $conn->real_escape_string($search);
    $where = "WHERE 
        full_name     LIKE '%$safe_search%' OR 
        username      LIKE '%$safe_search%' OR 
        phone_no      LIKE '%$safe_search%' OR 
        age           LIKE '%$safe_search%' OR
        email         LIKE '%$safe_search%' OR
        address       LIKE '%$safe_search%'";
}

// Fetch customers
$customers = [];
$sql = "SELECT * FROM customer $where ORDER BY cust_id DESC";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $customers[] = $row;
}
?>

<?php include 'admin_header.php'; ?>

<style>
.customer-search-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    align-items: center;
}
.customer-search-bar input[type=text] {
    padding: 9px 14px;
    border-radius: 7px;
    border: 1.5px solid #b5bee5;
    font-size: 1.05em;
    background: #f7fafd;
    width: 280px;
    max-width: 50vw;
}
.customer-search-bar button {
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
.customer-search-bar button:hover {
    background: #243570;
}
.customer-table {
    width: 100%;
    border-collapse: collapse;
    margin: 18px 0 40px 0;
    background: #fff;
    box-shadow: 0 2px 12px #e0e7ef55;
    border-radius: 12px;
    overflow: hidden;
}
.customer-table th, .customer-table td {
    padding: 13px 14px;
    border-bottom: 1px solid #eef2fa;
    text-align: left;
}
.customer-table th {
    background: #f8fafd;
    font-weight: 700;
    color: #2b5cbc;
    letter-spacing: 0.5px;
}
.customer-table tr:last-child td {
    border-bottom: none;
}
.edit-btn {
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
.edit-btn:hover {
    background: #d2ebfd;
    color: #18447c;
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
@media (max-width: 900px) {
    .customer-table th, .customer-table td { font-size: 0.97em; padding: 8px 6px; }
    .customer-search-bar input[type=text] { width: 120px; }
}
.customers-breadcrumb {
    font-size: 1em;
    color: #92a2b3;
    margin-bottom: 10px;
}
.customers-breadcrumb a {
    color: #6d87be;
    text-decoration: none;
    font-weight: 600;
}
</style>

<div style="max-width:1120px;margin:38px auto 25px auto;">
    <div class="customers-breadcrumb" style="font-size:1em;color:#92a2b3;margin-bottom:10px;">
        <a href="admin_dashboard.php" style="color:#6d87be;text-decoration:none;font-weight:600;">Dashboard</a> / Customers
    </div>
    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Customers</h2>
    <form class="customer-search-bar" method="get" action="customers.php" autocomplete="off">
        <input type="text" name="search" placeholder="Search name, username, phone, email, address or age..." value="<?= htmlspecialchars($search) ?>">
        <button type="submit">Search</button>
        <?php if ($search): ?>
            <a href="customers.php" style="margin-left:15px;color:#888;font-size:0.98em;">Clear</a>
        <?php endif; ?>
    </form>
    <div style="overflow-x:auto;">
    <table class="customer-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Username</th>
                <th>Address</th>
                <th>Age</th>
                <th>Edit</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($customers)): ?>
                <tr><td colspan="8" style="text-align:center;color:#888;">No customers found.</td></tr>
            <?php else: ?>
                <?php foreach ($customers as $i => $cust): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><?= htmlspecialchars($cust['full_name']) ?></td>
                        <td><?= htmlspecialchars($cust['phone_no']) ?></td>
                        <td><?= htmlspecialchars($cust['email']) ?></td>
                        <td><?= htmlspecialchars($cust['username']) ?></td>
                        <td><?= htmlspecialchars($cust['address']) ?></td>
                        <td><?= htmlspecialchars($cust['age']) ?></td>
                        <td>
                            <a href="edit_customer.php?id=<?= $cust['cust_id'] ?>" class="edit-btn">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>