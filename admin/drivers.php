<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Pagination
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$page = max($page, 1);
$offset = ($page - 1) * $per_page;

// Filtering
$where = 'WHERE 1=1';
$filter_blacklist = (isset($_GET['blacklist']) && in_array($_GET['blacklist'], ['Yes', 'No'])) ? $_GET['blacklist'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($filter_blacklist) {
    $where .= " AND d.blacklist = '" . $conn->real_escape_string($filter_blacklist) . "'";
}
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where .= " AND (
        d.full_name LIKE '%$safe_search%' OR 
        d.phone_no  LIKE '%$safe_search%' OR 
        d.license_no LIKE '%$safe_search%' OR 
        d.id_no      LIKE '%$safe_search%' OR
        d.address    LIKE '%$safe_search%' OR
        d.age        LIKE '%$safe_search%' OR
        d.blacklist_reason LIKE '%$safe_search%' OR
        g.full_name LIKE '%$safe_search%' OR
        g.phone_no LIKE '%$safe_search%' OR
        g.id_no LIKE '%$safe_search%' OR
        g.relationship LIKE '%$safe_search%'
    )";
}

// Get total count for pagination
$count_sql = "SELECT COUNT(*) AS total FROM driver d LEFT JOIN guarantor g ON d.driver_id = g.driver_id $where";
$count_result = $conn->query($count_sql);
$total = 0;
if ($row = $count_result->fetch_assoc()) {
    $total = intval($row['total']);
}
$total_pages = ceil($total / $per_page);

// Fetch driver+guarantor
$sql = "SELECT d.*, 
        g.guarantor_id,
        g.full_name AS guarantor_name, 
        g.phone_no AS guarantor_phone, 
        g.id_no AS guarantor_id_no,
        g.relationship AS guarantor_relationship,
        g.id_front_image AS guarantor_id_front_image,
        g.id_back_image AS guarantor_id_back_image
        FROM driver d
        LEFT JOIN guarantor g ON d.driver_id = g.driver_id
        $where
        ORDER BY d.driver_id DESC
        LIMIT $per_page OFFSET $offset";
$result = $conn->query($sql);
$drivers = [];
while ($row = $result->fetch_assoc()) {
    $drivers[] = $row;
}
?>
<?php include 'admin_header.php'; ?>

<style>
body {
    background: #f7f9fa;
}
.driver-table {
    table-layout: fixed;
    width: 100%;
    border-collapse: collapse;
    margin: 24px 0 40px 0;
    background: #fff;
    box-shadow: 0 2px 12px #e0e7ef55;
    border-radius: 12px;
    overflow: hidden;
}
.driver-table th, .driver-table td {
    padding: 12px 13px;
    border-bottom: 1px solid #eef2fa;
    text-align: left;
    vertical-align: middle;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.driver-table th {
    background: #f8fafd;
    font-weight: 700;
    color: #2b5cbc;
    letter-spacing: 0.5px;
}
.driver-table tr:last-child td {
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
.driver-search-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    align-items: center;
    flex-wrap: wrap;
}
.driver-search-bar input[type=text] {
    padding: 9px 14px;
    border-radius: 7px;
    border: 1.5px solid #b5bee5;
    font-size: 1.05em;
    background: #f7fafd;
    width: 200px;
    max-width: 50vw;
}
.driver-search-bar select {
    padding: 9px 14px;
    border-radius: 7px;
    border: 1.5px solid #b5bee5;
    font-size: 1.05em;
    background: #f7fafd;
}
.driver-search-bar button {
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
.driver-search-bar button:hover {
    background: #243570;
}
.blacklist-yes {
    color: #fff;
    background: #d92222;
    padding: 3px 10px;
    border-radius: 5px;
    font-weight: bold;
    display: inline-block;
    width: 36px;
    text-align: center;
}
.blacklist-no {
    color: #fff;
    background: #2b9c3c;
    padding: 3px 10px;
    border-radius: 5px;
    font-weight: bold;
    display: inline-block;
    width: 36px;
    text-align: center;
}
.collapse-btn {
    background: none;
    border: none;
    font-size: 1.25em;
    font-weight: bold;
    color: #2b5cbc;
    cursor: pointer;
    margin-right: 8px;
    outline: none;
    vertical-align: middle;
    transition: color 0.15s;
}
.collapse-btn:focus, .collapse-btn:hover {
    color: #183c7c;
}
.guarantor-row {
    display: none;
    background: #f4f9fe;
    border-bottom: 1.5px solid #dae2ff;
}
.guarantor-table {
    width: 100%;
}
.guarantor-table th, .guarantor-table td {
    padding: 4px 10px;
    font-size: 0.96em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.address-col, .blacklist-reason-col {
    white-space: normal !important;
    word-break: break-word;
    min-width: 120px;
    max-width: 220px;
}
@media (max-width: 1200px) {
    .driver-table th, .driver-table td { font-size: 0.96em; padding: 7px 4px;}
    .driver-search-bar input[type=text] { width: 120px;}
    .address-col, .blacklist-reason-col { max-width: 110px;}
}
.drivers-breadcrumb {
    font-size: 1em;
    color: #92a2b3;
    margin-bottom: 10px;
}
.drivers-breadcrumb a {
    color: #6d87be;
    text-decoration: none;
    font-weight: 600;
}
</style>

<script>
function toggleGuarantorRow(driverId) {
    var row = document.getElementById('guarantor-row-' + driverId);
    var btn = document.getElementById('collapse-btn-' + driverId);
    if (row.style.display === 'table-row') {
        row.style.display = 'none';
        btn.textContent = '+';
        btn.setAttribute('aria-expanded', 'false');
    } else {
        row.style.display = 'table-row';
        btn.textContent = '−';
        btn.setAttribute('aria-expanded', 'true');
    }
}
</script>

<div style="max-width:1400px;margin:38px auto 25px auto;">
    <div class="drivers-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Drivers & Guarantors
    </div>
    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Drivers & Guarantors</h2>
    <form class="driver-search-bar" method="get" action="drivers.php" autocomplete="off">
        <input type="text" name="search" placeholder="Search driver or guarantor..." value="<?= htmlspecialchars($search) ?>">
        <select name="blacklist">
            <option value="">All Blacklist Status</option>
            <option value="No" <?= $filter_blacklist === 'No' ? 'selected' : '' ?>>No</option>
            <option value="Yes" <?= $filter_blacklist === 'Yes' ? 'selected' : '' ?>>Yes</option>
        </select>
        <button type="submit">Filter</button>
        <?php if ($search || $filter_blacklist): ?>
            <a href="drivers.php" style="margin-left:15px;color:#888;font-size:0.98em;">Clear</a>
        <?php endif; ?>
    </form>
    <div style="overflow-x:auto;">
    <table class="driver-table">
        <thead>
            <tr>
                <th style="width:32px;"></th>
                <th style="width:44px;">#</th>
                <th style="width:148px;">Full Name</th>
                <th style="width:110px;">Phone No</th>
                <th style="width:110px;">License No</th>
                <th style="width:110px;">ID No</th>
                <th style="width:78px;">ID Front</th>
                <th style="width:78px;">ID Back</th>
                <th class="address-col">Address</th>
                <th style="width:60px;">Age</th>
                <th style="width:80px;">Blacklist</th>
                <th class="blacklist-reason-col">Blacklist Reason</th>
                <th style="width:58px;">Edit</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($drivers)): ?>
            <tr><td colspan="13" style="text-align:center;color:#888;">No drivers found.</td></tr>
        <?php else: ?>
            <?php foreach ($drivers as $i => $driver): ?>
                <tr>
                    <td>
                        <button type="button" class="collapse-btn" id="collapse-btn-<?= $driver['driver_id'] ?>"
                            aria-controls="guarantor-row-<?= $driver['driver_id'] ?>"
                            aria-expanded="false"
                            onclick="toggleGuarantorRow(<?= $driver['driver_id'] ?>)">+</button>
                    </td>
                    <td><?= $offset + $i + 1 ?></td>
                    <td><?= htmlspecialchars($driver['full_name']) ?></td>
                    <td><?= htmlspecialchars($driver['phone_no']) ?></td>
                    <td><?= htmlspecialchars($driver['license_no']) ?></td>
                    <td><?= htmlspecialchars($driver['id_no']) ?></td>
                    <td>
                        <?php if (!empty($driver['id_front_image'])): ?>
                            <a href="view_image.php?type=front&driver_id=<?= $driver['driver_id'] ?>" target="_blank">View</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($driver['id_back_image'])): ?>
                            <a href="view_image.php?type=back&driver_id=<?= $driver['driver_id'] ?>" target="_blank">View</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td class="address-col"><?= htmlspecialchars($driver['address']) ?></td>
                    <td><?= htmlspecialchars($driver['age']) ?></td>
                    <td>
                        <?php if ($driver['blacklist'] == 'Yes'): ?>
                            <span class="blacklist-yes">Yes</span>
                        <?php else: ?>
                            <span class="blacklist-no">No</span>
                        <?php endif; ?>
                    </td>
                    <td class="blacklist-reason-col"><?= htmlspecialchars($driver['blacklist_reason']) ?></td>
                    <td>
                        <a class="edit-btn" href="edit_driver.php?id=<?= $driver['driver_id'] ?>">Edit</a>
                    </td>
                </tr>
                <tr class="guarantor-row" id="guarantor-row-<?= $driver['driver_id'] ?>">
                    <td></td>
                    <td colspan="12">
                        <div style="padding:13px 12px 8px 12px;">
                            <strong style="color:#23477c;">Guarantor Details:</strong>
                            <?php if ($driver['guarantor_id']): ?>
                                <table class="guarantor-table">
                                    <tr>
                                        <th>Name</th>
                                        <th>Phone</th>
                                        <th>ID No</th>
                                        <th>ID Front</th>
                                        <th>ID Back</th>
                                        <th>Relationship</th>
                                    </tr>
                                    <tr>
                                        <td><?= htmlspecialchars($driver['guarantor_name']) ?></td>
                                        <td><?= htmlspecialchars($driver['guarantor_phone']) ?></td>
                                        <td><?= htmlspecialchars($driver['guarantor_id_no']) ?></td>
                                        <td>
                                            <?php if (!empty($driver['guarantor_id_front_image'])): ?>
                                                <a href="view_guarantor_image.php?type=front&driver_id=<?= $driver['driver_id'] ?>" target="_blank">View</a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($driver['guarantor_id_back_image'])): ?>
                                                <a href="view_guarantor_image.php?type=back&driver_id=<?= $driver['driver_id'] ?>" target="_blank">View</a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($driver['guarantor_relationship']) ?></td>
                                    </tr>
                                </table>
                            <?php else: ?>
                                <div style="color:#888;">No guarantor information.</div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_blacklist ? '&blacklist=' . urlencode($filter_blacklist) : '' ?>">&laquo; First</a>
            <a href="?page=<?= $page-1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_blacklist ? '&blacklist=' . urlencode($filter_blacklist) : '' ?>">&lt; Prev</a>
        <?php endif; ?>

        <?php
        $range = 2;
        for ($p = max(1, $page - $range); $p <= min($total_pages, $page + $range); $p++): ?>
            <?php if ($p == $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="?page=<?= $p ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_blacklist ? '&blacklist=' . urlencode($filter_blacklist) : '' ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_blacklist ? '&blacklist=' . urlencode($filter_blacklist) : '' ?>">Next &gt;</a>
            <a href="?page=<?= $total_pages ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_blacklist ? '&blacklist=' . urlencode($filter_blacklist) : '' ?>">Last &raquo;</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>