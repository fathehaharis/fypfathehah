<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

// Pagination setup
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$page = max(1, $page);
$offset = ($page - 1) * $per_page;

// Read search (only used on initial load)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = "WHERE 1=1";
if ($search !== '') {
    $safe_search = $conn->real_escape_string($search);
    $where .= " AND (
        b.booking_id LIKE '%$safe_search%' OR
        car.car_model LIKE '%$safe_search%' OR
        car.car_brand LIKE '%$safe_search%' OR
        car.plate_no LIKE '%$safe_search%' OR
        b.pickup_datetime LIKE '%$safe_search%' OR
        b.return_datetime LIKE '%$safe_search%' OR
        p.payment_status LIKE '%$safe_search%' OR
        b.status LIKE '%$safe_search%'
    )";
}

$count_sql = "SELECT COUNT(DISTINCT b.booking_id) as total
    FROM booking b
    JOIN car ON b.car_id = car.car_id
    LEFT JOIN payment p ON b.booking_id = p.booking_id
    $where";
$count_result = $conn->query($count_sql);
$total = ($count_result && $row = $count_result->fetch_assoc()) ? intval($row['total']) : 0;
$total_pages = max(1, ceil($total / $per_page));

$sql = "SELECT b.*, 
            car.car_model, car.car_brand, car.plate_no,
            p.payment_status, p.amount AS payment_amount
        FROM booking b
        JOIN car ON b.car_id = car.car_id
        LEFT JOIN payment p ON b.booking_id = p.booking_id
        $where
        GROUP BY b.booking_id
        ORDER BY b.booking_id DESC
        LIMIT $per_page OFFSET $offset";
$result = $conn->query($sql);

$bookings = [];
$now = date('Y-m-d H:i:s');
while ($row = $result->fetch_assoc()) {
    // Car image
    $car_image = null;
    $img_sql = "SELECT image_path FROM car_image WHERE car_id = {$row['car_id']} ORDER BY uploaded_at ASC LIMIT 1";
    $img_result = $conn->query($img_sql);
    if ($img_result && $img = $img_result->fetch_assoc()) {
        $car_image = 'data:image/jpeg;base64,' . base64_encode($img['image_path']);
    }
    $row['car_image'] = $car_image ?: 'https://via.placeholder.com/90x60?text=No+Image';

    // Booking status for tab filtering
    if (isset($row['status']) && strtolower($row['status']) === 'cancelled') {
        $tab_status = 'cancelled';
    } elseif ($row['pickup_datetime'] > $now) {
        $tab_status = 'upcoming';
    } elseif ($row['pickup_datetime'] <= $now && $row['return_datetime'] >= $now) {
        $tab_status = 'ongoing';
    } else {
        $tab_status = 'other';
    }
    $row['tab_status'] = $tab_status;
    $bookings[] = $row;
}
?>
<?php include 'admin_header.php'; ?>

<style>
body {
    background: #f8f9fc;
    font-family: 'Inter', Arial, sans-serif;
}
.bookings-header {
    margin: 30px auto 10px auto;
    max-width: 1000px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.bookings-breadcrumb {
    font-size: 0.98em;
    color: #92a2b3;
    margin-bottom: 8px;
}
.bookings-breadcrumb a {
    color: #6d87be;
    text-decoration: none;
    font-weight: 600;
}
.bookings-title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.bookings-title {
    font-size: 2em;
    font-weight: 800;
    color: #232d3b;
    letter-spacing: 0.5px;
}
.bookings-tabs-row {
    display: flex;
    gap: 17px;
    margin-top: 8px;
    border-bottom: 1.5px solid #e7eaf4;
}
.bookings-tabs-row .bookings-tab {
    padding-bottom: 7px;
    font-size: 1.10em;
    font-weight: 500;
    color: #202a35;
    background: none;
    border: none;
    cursor: pointer;
    outline: none;
    transition: color 0.12s;
    border-bottom: 2.5px solid transparent;
}
.bookings-tabs-row .bookings-tab.active {
    color: #233ca0;
    border-bottom: 2.5px solid #293e9b;
    font-weight: 700;
}
.bookings-total-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 16px 0 12px 0;
}
.bookings-total-badge {
    background: #fff;
    color: #232d3b;
    border-radius: 9px;
    padding: 7px 13px;
    font-size: 1.06em;
    font-weight: 600;
    box-shadow: 0 1.5px 5px #e5e9ef77;
    display: flex;
    align-items: center;
    gap: 7px;
    letter-spacing: 0.7px;
}
.bookings-search-bar {
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 1px 7px #e2e9f6a8;
    padding: 8px 19px;
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 210px;
}
.bookings-search-input {
    padding: 8px 10px;
    border-radius: 8px;
    border: 1.5px solid #d1d9ed;
    background: #f9fbff;
    font-size: 1.05em;
    transition: border .13s;
    width: 170px;
}
.bookings-search-input:focus {
    border-color: #4156c7;
    outline: none;
}
.booking-card-list {
    display: flex;
    flex-direction: column;
    gap: 18px;
    max-width: 1000px;
    margin: 0 auto 38px auto;
}
.booking-card-link {
    text-decoration: none;
    color: inherit;
    display: block;
}
.booking-card {
    display: flex;
    align-items: center;
    background: #fff;
    border: 1.5px solid #e6eef4;
    border-radius: 12px;
    box-shadow: 0 2px 8px #e0e7ef22;
    padding: 20px 28px;
    transition: box-shadow 0.13s, border 0.13s, transform 0.13s;
    cursor: pointer;
}
.booking-card:hover {
    border: 1.5px solid #b0c7e9;
    box-shadow: 0 4px 18px #d0e3fa22;
    transform: translateY(-2px) scale(1.012);
}
.booking-card-img {
    width: 90px;
    height: 60px;
    object-fit: cover;
    border-radius: 7px;
    background: #f5f7fa;
    margin-right: 24px;
    border: 1px solid #e4e8f3;
}
.booking-card-content {
    flex: 2 1 160px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.booking-card-title {
    font-weight: 700;
    font-size: 1.15em;
    letter-spacing: 0.5px;
    color: #222;
}
.booking-card-plate {
    color: #6e7c91;
    font-size: 1.03em;
    font-weight: 500;
    letter-spacing: 0.5px;
}
.booking-card-datetime {
    display: flex;
    flex-direction: column;
    gap: 1.5px;
    font-size: 1.01em;
    color: #334055;
    font-weight: 500;
    margin-left: 24px;
    min-width: 185px;
}
.booking-card-datetime span {
    display: block;
}
.booking-card-payment {
    min-width: 105px;
    margin-left: 30px;
}
.booking-payment-badge {
    background: #e6fcf3;
    color: #2bbf5f;
    font-size: 0.97em;
    padding: 5px 17px;
    border-radius: 15px;
    font-weight: 700;
    letter-spacing: 0.3px;
    display: inline-block;
    margin-bottom: 2px;
}
.booking-payment-badge.pending {
    background: #fff7e6;
    color: #e7a84b;
}
.booking-payment-badge.unpaid, .booking-payment-badge.failed {
    background: #ffeded;
    color: #e54848;
}
.booking-payment-badge.cancelled {
    background: #faf3f3;
    color: #a14d4d;
}
.booking-card-total {
    min-width: 110px;
    text-align: right;
    font-size: 1.13em;
    color: #1e2349;
    font-weight: 800;
    letter-spacing: 1px;
    margin-left: 20px;
}
.pagination {
    display: flex;
    gap: 8px;
    justify-content: center;
    margin: 24px 0 24px 0;
}
.pagination a, .pagination span {
    padding: 7px 15px;
    border-radius: 8px;
    background: #fff;
    color: #2b5cbc;
    text-decoration: none;
    font-weight: 700;
    border: 1.5px solid #e4e8f3;
    font-size: 1.13em;
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
@media (max-width: 700px) {
    .booking-card-list, .bookings-header { max-width: 98vw; }
    .booking-card { flex-direction: column; align-items: flex-start; gap: 10px; padding: 16px 12px; }
    .booking-card-img { margin-bottom: 7px; margin-right: 0;}
    .booking-card-datetime, .booking-card-total, .booking-card-payment { margin-left: 0; }
}
</style>

<div class="bookings-header">
    <div class="bookings-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Bookings
    </div>
    <div class="bookings-title-row">
        <div class="bookings-title">Bookings</div>
    </div>
    <div class="bookings-tabs-row">
        <button class="bookings-tab active" data-tab="all">All</button>
        <button class="bookings-tab" data-tab="upcoming">Upcoming</button>
        <button class="bookings-tab" data-tab="ongoing">Ongoing</button>
        <button class="bookings-tab" data-tab="cancelled">Cancelled</button>
    </div>
    <div class="bookings-total-row">
        <div class="bookings-total-badge">
            Total <b id="total-count"><?= $total ?></b>
        </div>
        <form class="bookings-search-bar" method="get" action="bookings.php" autocomplete="off">
            <input type="text" name="search" id="searchInput" class="bookings-search-input" placeholder="Search booking, car, plate, date..." value="<?= htmlspecialchars($search) ?>">
            
        </form>
    </div>
</div>

<div class="booking-card-list" id="bookingCardsList">
<?php if (empty($bookings)): ?>
    <div style="text-align:center;color:#888;font-size:1.15em;">No bookings found.</div>
<?php else: ?>
    <?php foreach ($bookings as $b): ?>
        <a href="booking_details.php?id=<?= $b['booking_id'] ?>" class="booking-card-link"
            data-status="<?= $b['tab_status'] ?>"
            data-search="<?= strtolower(
                $b['booking_id'].' '.
                $b['car_model'].' '.
                $b['car_brand'].' '.
                $b['plate_no'].' '.
                date('d M Y g:i A', strtotime($b['pickup_datetime'])).' '.
                date('d M Y g:i A', strtotime($b['return_datetime'])).' '.
                $b['payment_status'].' '.
                $b['status']
            ) ?>">
            <div class="booking-card">
                <img class="booking-card-img" src="<?= htmlspecialchars($b['car_image']) ?>" alt="Car image">
                <div class="booking-card-content">
                    <span class="booking-card-title"><?= strtoupper(htmlspecialchars($b['car_brand'])) ?> <?= htmlspecialchars($b['car_model']) ?></span>
                    <span class="booking-card-plate"><?= htmlspecialchars($b['plate_no']) ?></span>
                </div>
                <div class="booking-card-datetime">
                    <span><b>Pickup:</b> <?= $b['pickup_datetime'] ? date('d M Y, g:i A', strtotime($b['pickup_datetime'])) : '-' ?></span>
                    <span><b>Return:</b> <?= $b['return_datetime'] ? date('d M Y, g:i A', strtotime($b['return_datetime'])) : '-' ?></span>
                </div>
                <div class="booking-card-payment">
                    <?php
                    $is_cancelled = isset($b['status']) && strtolower($b['status']) === 'cancelled';
                    $p = strtolower($b['payment_status']);
                    if ($is_cancelled) {
                        echo '<span class="booking-payment-badge cancelled">Cancelled</span>';
                    } elseif ($p == 'paid') {
                        echo '<span class="booking-payment-badge">Fully Paid</span>';
                    } elseif ($p == 'pending') {
                        echo '<span class="booking-payment-badge pending">Pending</span>';
                    } elseif ($p == 'failed') {
                        echo '<span class="booking-payment-badge failed">Failed</span>';
                    } else {
                        echo '<span class="booking-payment-badge unpaid">Unpaid</span>';
                    }
                    ?>
                </div>
                <div class="booking-card-total">
                    MYR <?= number_format($b['total_price'] ?? $b['payment_amount'] ?? 0, 2) ?>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
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
                <span class="current"><?= $p ?></span>
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

<script>
function updateTotalBox() {
    // Count only visible booking cards
    const visibleBookings = Array.from(document.querySelectorAll('.booking-card-link'))
        .filter(card => card.style.display !== 'none');
    document.getElementById('total-count').textContent = visibleBookings.length;
}
function filterBookings() {
    const tab = document.querySelector('.bookings-tabs-row .active').getAttribute('data-tab');
    const search = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('.booking-card-link').forEach(function(cardLink){
        const status = cardLink.getAttribute('data-status');
        const searchText = cardLink.getAttribute('data-search');
        const matchTab = (tab === 'all') ? true : (status === tab);
        const matchSearch = search === '' || (searchText && searchText.includes(search));
        if(matchTab && matchSearch) {
            cardLink.style.display = '';
        } else {
            cardLink.style.display = 'none';
        }
    });
    updateTotalBox();
}
document.querySelectorAll('.bookings-tab').forEach(function(tabBtn) {
  tabBtn.addEventListener('click', function() {
    document.querySelectorAll('.bookings-tab').forEach(function(btn){
      btn.classList.remove('active');
    });
    tabBtn.classList.add('active');
    filterBookings();
  });
});
document.getElementById('searchInput').addEventListener('input', filterBookings);
window.addEventListener('DOMContentLoaded', filterBookings);
</script>
<?php include '../includes/footer.php'; ?>