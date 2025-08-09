<?php
session_start();
include '../connect.php';

if (empty($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

date_default_timezone_set('Asia/Kuala_Lumpur');

$search  = trim($_GET['search'] ?? '');
$page    = isset($_GET['page']) && ctype_digit($_GET['page']) && (int)$_GET['page']>0 ? (int)$_GET['page'] : 1;
$perPage = 25;
$offset  = ($page-1)*$perPage;

function esc($v){ return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$where = '';
$params = [];
$types  = '';
if ($search !== '') {
    $like = "%$search%";
    $where = "WHERE (full_name LIKE ? OR username LIKE ? OR phone_no LIKE ? OR email LIKE ? OR address LIKE ? OR CAST(age AS CHAR) LIKE ?)";
    $params = [$like,$like,$like,$like,$like,$like];
    $types  = 'ssssss';
}

$totalAll = (int)$conn->query("SELECT COUNT(*) c FROM customer")->fetch_assoc()['c'];
if ($where) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM customer $where");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->bind_result($filtered);
    $stmt->fetch();
    $stmt->close();
    $totalFiltered = (int)$filtered;
} else {
    $totalFiltered = $totalAll;
}

$totalPages = max(1,(int)ceil($totalFiltered/$perPage));

$listSql = "SELECT cust_id, full_name, phone_no, email, username, profile_status, profile_status_updated_at
            FROM customer
            ".($where?:'')."
            ORDER BY cust_id DESC
            LIMIT ? OFFSET ?";
$stmt = $conn->prepare($where
    ? "$listSql"
    : "SELECT cust_id, full_name, phone_no, email, username, profile_status, profile_status_updated_at
       FROM customer ORDER BY cust_id DESC LIMIT ? OFFSET ?");
if ($where) {
    $typesList = $types.'ii';
    $paramsList = array_merge($params,[$perPage,$offset]);
    $stmt->bind_param($typesList, ...$paramsList);
} else {
    $stmt->bind_param('ii',$perPage,$offset);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r=$res->fetch_assoc()) $rows[]=$r;
$stmt->close();

$statusLabels = [
    'unsubmitted'=>'Not Submitted',
    'pending'=>'Pending',
    'verified'=>'Verified',
    'rejected'=>'Rejected',
    'pending_reverification'=>'Pending Re-Verification'
];
function badgeClass($s){
    return match($s){
        'verified'=>'badge-ok',
        'pending','pending_reverification'=>'badge-warn',
        'rejected'=>'badge-error',
        default=>'badge-neutral'
    };
}

include 'admin_header.php';
?>
<style>
.customers-wrapper{max-width:1200px;margin:38px auto 60px;padding:0 16px;}
.customers-breadcrumb{font-size:.9rem;color:#92a2b3;margin-bottom:10px;}
.customers-breadcrumb a{color:#6d87be;text-decoration:none;font-weight:600;}
.customer-search-bar{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 10px;align-items:center;}
.customer-search-bar input{padding:9px 14px;border:1.5px solid #b5bee5;border-radius:7px;background:#f7fafd;font-size:.95rem;width:320px;max-width:70vw;}
.customer-search-bar button{padding:9px 18px;background:#2b5cbc;color:#fff;font-weight:600;border:none;border-radius:7px;cursor:pointer;font-size:.9rem;}
.customer-search-bar button:hover{background:#243570;}
.clear-link{color:#888;font-size:.75rem;text-decoration:none;}
.clear-link:hover{text-decoration:underline;}
.customer-table{width:100%;border-collapse:collapse;margin:18px 0 30px;background:#fff;box-shadow:0 2px 12px #e0e7ef55;border-radius:14px;overflow:hidden;font-size:.8rem;}
.customer-table th,.customer-table td{padding:12px 14px;border-bottom:1px solid #eef2fa;text-align:left;}
.customer-table th{background:#f8fafd;font-weight:700;color:#2b5cbc;letter-spacing:.5px;font-size:.68rem;text-transform:uppercase;}
.customer-table tr:last-child td{border-bottom:none;}
.view-btn{background:#eaf1fa;color:#2b5cbc;padding:6px 12px;border-radius:7px;text-decoration:none;font-weight:600;font-size:.65rem;display:inline-block;}
.view-btn:hover{background:#d2ebfd;color:#1a4d7d;}
.badge{display:inline-block;padding:4px 10px;font-size:.6rem;border-radius:14px;font-weight:600;letter-spacing:.4px;}
.badge-ok{background:#e4f8ea;color:#1c6a34;}
.badge-warn{background:#fff7db;color:#7a5d00;}
.badge-error{background:#ffe2e2;color:#902121;}
.badge-neutral{background:#e7ebf2;color:#33415c;}
.count-summary{font-size:.7rem;color:#607086;margin-top:4px;}
.pagination{display:flex;flex-wrap:wrap;gap:6px;padding:6px 0 12px;}
.pagination a,.pagination span{display:inline-block;padding:6px 10px;border-radius:7px;text-decoration:none;font-size:.64rem;font-weight:600;background:#fff;border:1px solid #d5dceb;color:#2b5cbc;}
.pagination a:hover{background:#2b5cbc;color:#fff;}
.pagination .active{background:#2b5cbc;color:#fff;border-color:#2b5cbc;}
.pagination .disabled{opacity:.4;cursor:not-allowed;background:#f4f6fa;color:#9aa9c5;}
@media (max-width:760px){
  .customer-table th:nth-child(n+6),.customer-table td:nth-child(n+6){display:none;}
  .customer-search-bar input{width:200px;}
}
</style>

<div class="customers-wrapper">
  <div class="customers-breadcrumb">
    <a href="admin_dashboard.php">Dashboard</a> / Customers
  </div>
  <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;margin:0 0 8px;">Customers</h2>

  <form class="customer-search-bar" method="get" action="customers.php">
    <input type="text" name="search" placeholder="Search name, username, phone, email, address or age..." value="<?= esc($search) ?>">
    <button type="submit">Search</button>
    <?php if($search!==''): ?><a href="customers.php" class="clear-link">Clear</a><?php endif; ?>
  </form>

  <div class="count-summary">
    <?php if ($search!==''): ?>
      Showing <?= count($rows) ?> of <?= $totalFiltered ?> match(es). Total customers: <?= $totalAll ?>.
    <?php else: ?>
      Showing <?= count($rows) ?> (page <?= $page ?> of <?= $totalPages ?>). Total customers: <?= $totalAll ?>.
    <?php endif; ?>
  </div>

  <div style="overflow-x:auto;">
    <table class="customer-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Full Name</th>
          <th>Username</th>
          <th>Email</th>
          <th>Phone</th>
          <th>Status</th>
          <th>Updated</th>
          <th>View</th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="8" style="text-align:center;color:#888;">No customers found.</td></tr>
      <?php else: foreach ($rows as $i=>$c):
          $status = $c['profile_status'] ?? 'unsubmitted';
      ?>
        <tr>
          <td><?= esc($offset+$i+1) ?></td>
            <td><?= esc($c['full_name']) ?></td>
            <td><?= esc($c['username']) ?></td>
            <td><?= esc($c['email']) ?></td>
            <td><?= esc($c['phone_no']) ?></td>
            <td><span class="badge <?= badgeClass($status) ?>"><?= esc($statusLabels[$status] ?? $status) ?></span></td>
            <td style="font-size:.6rem;"><?= esc($c['profile_status_updated_at'] ?? '') ?></td>
            <td><a class="view-btn" href="admin_customer_view.php?cust_id=<?= (int)$c['cust_id'] ?>">View</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages>1): ?>
    <div class="pagination">
      <?php
      $qs = $search!=='' ? '&search='.urlencode($search) : '';
      $prev = max(1,$page-1);
      $next = min($totalPages,$page+1);
      ?>
      <?php if($page>1): ?>
        <a href="customers.php?page=<?= $prev . $qs ?>">&laquo;</a>
      <?php else: ?><span class="disabled">&laquo;</span><?php endif; ?>
      <?php
        $window=3;
        $start=max(1,$page-$window);
        $end=min($totalPages,$page+$window);
        if($start>1){
            echo '<a href="customers.php?page=1'.$qs.'">1</a>';
            if($start>2) echo '<span class="disabled">…</span>';
        }
        for($p=$start;$p<=$end;$p++){
            if($p==$page) echo '<span class="active">'.$p.'</span>';
            else echo '<a href="customers.php?page='.$p.$qs.'">'.$p.'</a>';
        }
        if($end<$totalPages){
            if($end<$totalPages-1) echo '<span class="disabled">…</span>';
            echo '<a href="customers.php?page='.$totalPages.$qs.'">'.$totalPages.'</a>';
        }
      ?>
      <?php if($page<$totalPages): ?>
        <a href="customers.php?page=<?= $next . $qs ?>">&raquo;</a>
      <?php else: ?><span class="disabled">&raquo;</span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>