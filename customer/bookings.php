<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

$cust_id = $_SESSION['cust_id'];

// Status mapping for display sections (add any new statuses here)
$status_map = [
    'pending'              => 'Pending',
    'waiting_verification' => 'Pending',
    'approved'             => 'Pending',   // Only show "Pay" if approved
    'confirmed'            => 'Upcoming',
    'completed'            => 'Completed',
    'cancelled'            => 'Cancelled',
    'rejected'             => 'Cancelled'
];

// Prepare empty arrays for each section
$bookings = [
    'Pending'   => [],
    'Upcoming'  => [],
    'Completed' => [],
    'Cancelled' => []
];

// Fetch bookings for this customer with 1 car image if any
$stmt = $conn->prepare("
    SELECT 
        b.*,
        c.car_brand,
        c.car_model,
        c.plate_no,
        ci.image_path AS car_image
    FROM booking b
    JOIN car c ON b.car_id = c.car_id
    LEFT JOIN (
        SELECT car_id, image_path
        FROM car_image
        WHERE car_image_id IN (
            SELECT MIN(car_image_id) FROM car_image GROUP BY car_id
        )
    ) ci ON c.car_id = ci.car_id
    WHERE b.cust_id = ?
    ORDER BY b.pickup_datetime DESC
");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $db_status = strtolower($row['status']);
    $section = isset($status_map[$db_status]) ? $status_map[$db_status] : 'Upcoming';
    $bookings[$section][] = $row;
}
$stmt->close();
include '../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/style.css">
<style>
.bookings-main-layout {
    display: flex;
    gap: 36px;
    max-width: 1200px;
    margin: 44px auto 60px auto;
}
.bookings-sidebar {
    width: 210px;
    min-width: 180px;
    background: #f7faff;
    border-radius: 13px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.07);
    padding: 32px 0 32px 0;
    height: fit-content;
}
.bookings-sidebar ul {
    list-style: none;
    margin: 0;
    padding: 0;
}
.bookings-sidebar li {
    margin: 0;
    padding: 0;
}
.bookings-sidebar-btn {
    display: block;
    padding: 14px 26px;
    margin: 0;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
    font-size: 1.04em;
    font-weight: 600;
    color: #3c4cb8;
    border-radius: 0 22px 22px 0;
    transition: background 0.15s, color 0.15s;
    cursor: pointer;
    outline: none;
    border-left: 4px solid transparent;
}
.bookings-sidebar-btn.active,
.bookings-sidebar-btn:hover {
    background: #e7edfa;
    color: #234c96;
    border-left: 4px solid #3c4cb8;
}
.bookings-content {
    flex: 1;
    background: #fff;
    padding: 36px 44px 36px 44px;
    border-radius: 13px;
    box-shadow: 0 4px 18px rgba(44,60,102,0.09);
    min-width: 0;
}
.bookings-title {
    font-size: 1.4em;
    font-weight: 700;
    color: #2f377d;
    margin-bottom: 26px;
    text-align: center;
}
.bookings-section-title {
    font-size: 1.15em;
    color: #3c4cb8;
    font-weight: 600;
    margin: 32px 0 12px 0;
    border-bottom: 1px solid #dbe0f0;
    padding-bottom: 2px;
}
.booking-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 8px;
}
.booking-table th, .booking-table td {
    border-bottom: 1px solid #f0f1f4;
    padding: 9px 8px 9px 0;
    text-align: left;
    font-size: 1.01em;
}
.booking-table th {
    color: #3c4cb8;
    font-weight: 600;
}
.booking-table tr:last-child td {
    border-bottom: none;
}
.booking-status {
    padding: 4px 12px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.98em;
    display: inline-block;
}
.status-pending { background: #fffbe7; color: #bfa800; }
.status-upcoming { background: #f7faff; color: #2f377d; }
.status-completed { background: #e3fbe6; color: #219150; }
.status-cancelled { background: #fde9e9; color: #d42d2d; }
.no-bookings {
    color: #868fba;
    text-align: center;
    margin: 16px 0 32px 0;
}
.booking-actions {
    display: flex;
    gap: 10px;
}
.action-btn {
    background: #3c4cb8;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 7px 16px;
    font-size: 1em;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.17s;
    display: inline-block;
}
.action-btn.cancel {
    background: #d42d2d;
}
.action-btn.cancel:hover {
    background: #b82323;
}
.action-btn.view:hover {
    background: #234c96;
}
.booking-car-img-thumb {
    width: 88px;
    height: 56px;
    object-fit: cover;
    border-radius: 7px;
    border: 1px solid #dadada;
    background: #f2f3f8;
    margin-right: 7px;
    vertical-align: middle;
}
.back-btn {
    width: 180px;
    margin: 22px auto 0 auto;
    display: block;
    background: #c2c7d6;
    color: #2f377d;
    border: none;
    padding: 13px 0;
    border-radius: 8px;
    font-size: 1.08em;
    font-weight: 600;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    transition: background 0.18s, color 0.18s;
}
.back-btn:hover {
    background: #b4bac9;
    color: #162040;
}
@media (max-width: 900px) {
    .bookings-main-layout {
        flex-direction: column;
        gap: 0;
    }
    .bookings-sidebar {
        width: 100%;
        border-radius: 13px 13px 0 0;
        display: flex;
        flex-direction: row;
        justify-content: space-around;
        padding: 0;
    }
    .bookings-sidebar ul {
        display: flex;
        width: 100%;
    }
    .bookings-sidebar li {
        flex: 1;
    }
    .bookings-sidebar-btn {
        border-radius: 0;
        border-left: none;
        border-bottom: 4px solid transparent;
        text-align: center;
        padding: 14px 0;
        font-size: 1em;
    }
    .bookings-sidebar-btn.active,
    .bookings-sidebar-btn:hover {
        border-left: none;
        border-bottom: 4px solid #3c4cb8;
        background: #e7edfa;
    }
    .bookings-content {
        padding: 22px 4vw 22px 4vw;
    }
}
</style>
<script>
function showSection(section) {
    document.querySelectorAll('.bookings-content-section').forEach(function(div) {
        div.style.display = 'none';
    });
    document.getElementById('section-' + section).style.display = '';
    document.querySelectorAll('.bookings-sidebar-btn').forEach(function(btn) {
        btn.classList.remove('active');
    });
    document.getElementById('sidebar-btn-' + section).classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    // Default: show Pending if any, else Upcoming
    let defaultSection = 'Pending';
    <?php if (!empty($bookings['Pending'])): ?>
        defaultSection = 'Pending';
    <?php elseif (!empty($bookings['Upcoming'])): ?>
        defaultSection = 'Upcoming';
    <?php elseif (!empty($bookings['Completed'])): ?>
        defaultSection = 'Completed';
    <?php else: ?>
        defaultSection = 'Cancelled';
    <?php endif; ?>
    showSection(defaultSection);

    // Sidebar click events
    document.getElementById('sidebar-btn-Pending').onclick = function() { showSection('Pending'); };
    document.getElementById('sidebar-btn-Upcoming').onclick = function() { showSection('Upcoming'); };
    document.getElementById('sidebar-btn-Completed').onclick = function() { showSection('Completed'); };
    document.getElementById('sidebar-btn-Cancelled').onclick = function() { showSection('Cancelled'); };
});
</script>

<div class="bookings-main-layout">
    <nav class="bookings-sidebar">
        <ul>
            <li><button class="bookings-sidebar-btn active" id="sidebar-btn-Pending" type="button">Pending</button></li>
            <li><button class="bookings-sidebar-btn" id="sidebar-btn-Upcoming" type="button">Upcoming</button></li>
            <li><button class="bookings-sidebar-btn" id="sidebar-btn-Completed" type="button">Completed</button></li>
            <li><button class="bookings-sidebar-btn" id="sidebar-btn-Cancelled" type="button">Cancelled</button></li>
        </ul>
    </nav>
    <div class="bookings-content">
        <div class="bookings-title">My Bookings</div>
        <?php foreach (['Pending', 'Upcoming', 'Completed', 'Cancelled'] as $section): ?>
            <div id="section-<?= $section ?>" class="bookings-content-section" style="display:none;">
                <div class="bookings-section-title"><?= $section ?> Bookings</div>
                <?php if (empty($bookings[$section])): ?>
                    <div class="no-bookings">No <?= strtolower($section) ?> bookings found.</div>
                <?php else: ?>
                    <table class="booking-table">
                        <tr>
                            <th>Car</th>
                            <th>Plate No</th>
                            <th>Pickup Date</th>
                            <th>Return Date</th>
                            <th>Duration</th>
                            <th>Total (RM)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                        <?php foreach ($bookings[$section] as $b): ?>
                            <?php
                                // Calculate if pickup is at least 24 hours from now
                                $now = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
                                $pickup_dt = new DateTime($b['pickup_datetime'], new DateTimeZone('Asia/Kuala_Lumpur'));
                                $interval = $now->diff($pickup_dt);
                                $hours_to_pickup = ($pickup_dt > $now) ? ($interval->days * 24 + $interval->h + $interval->i/60) : 0;
                                $can_cancel = $hours_to_pickup > 24;
                            ?>
                            <tr>
                                <td>
                                    <?php if (!empty($b['car_image'])): ?>
                                        <img class="booking-car-img-thumb" src="data:image/jpeg;base64,<?= base64_encode($b['car_image']) ?>" alt="Car">
                                    <?php else: ?>
                                        <img class="booking-car-img-thumb" src="/assets/images/no-car.png" alt="No Car Image">
                                    <?php endif; ?>
                                    <?= htmlspecialchars($b['car_brand'] . ' ' . $b['car_model']) ?>
                                </td>
                                <td><?= htmlspecialchars($b['plate_no']) ?></td>
                                <td><?= date('d M Y, H:i', strtotime($b['pickup_datetime'])) ?></td>
                                <td><?= date('d M Y, H:i', strtotime($b['return_datetime'])) ?></td>
                                <td>
                                    <?php
                                        $duration_str = '';
                                        if ((int)$b['day_count'] > 0) $duration_str .= (int)$b['day_count'] . ' day(s)';
                                        if ((int)$b['hour_count'] > 0) {
                                            if ($duration_str) $duration_str .= ' ';
                                            $duration_str .= (int)$b['hour_count'] . ' hour(s)';
                                        }
                                        if (!$duration_str) $duration_str = htmlspecialchars($b['booking_duration'] ?? '-');
                                        echo $duration_str;
                                    ?>
                                </td>
                                <td><?= number_format($b['total_price'], 2) ?></td>
                                <td>
                                    <?php
                                        $status_class = 'status-upcoming';
                                        if ($section == 'Pending') $status_class = 'status-pending';
                                        else if ($section == 'Completed') $status_class = 'status-completed';
                                        else if ($section == 'Cancelled') $status_class = 'status-cancelled';
                                    ?>
                                    <span class="booking-status <?= $status_class ?>">
                                        <?= ucfirst($b['status']) ?>
                                    </span>
                                </td>
                                <td class="booking-actions">
                                    <?php if ($section == 'Pending'): ?>
                                        <a class="action-btn view" href="view_booking.php?booking_id=<?= $b['booking_id'] ?>">View</a>
                                        <?php if (strtolower($b['status']) === 'approved'): ?>
                                            <a class="action-btn" style="background:#f8a100;" href="payment.php?booking_id=<?= $b['booking_id'] ?>">Pay</a>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:0.98em;">Awaiting admin approval</span>
                                        <?php endif; ?>
                                        <?php if ($can_cancel): ?>
                                            <form action="cancel_booking.php" method="post" style="display:inline;">
                                                <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                                                <button type="submit" class="action-btn cancel" onclick="return confirm('Are you sure you want to cancel this booking?');">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:0.98em;">Cannot cancel less than 1 day before pickup</span>
                                        <?php endif; ?>
                                    <?php elseif ($section == 'Upcoming'): ?>
                                        <a class="action-btn view" href="view_booking.php?booking_id=<?= $b['booking_id'] ?>">View</a>
                                        <?php if ($can_cancel): ?>
                                            <form action="cancel_booking.php" method="post" style="display:inline;">
                                                <input type="hidden" name="booking_id" value="<?= $b['booking_id'] ?>">
                                                <button type="submit" class="action-btn cancel" onclick="return confirm('Are you sure you want to cancel? You will NOT get your deposit back. Any eligible refund will be credited to your account within 3 - 5 days after cancellation.');">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#999;font-size:0.98em;">Cannot cancel less than 1 day before pickup</span>
                                        <?php endif; ?>
                                    <?php elseif ($section == 'Completed'): ?>
                                        <a class="action-btn view" href="view_booking.php?booking_id=<?= $b['booking_id'] ?>">View</a>
                                        <a class="action-btn view" href="download_agreement.php?booking_id=<?= $b['booking_id'] ?>">Agreement</a>
                                    <?php elseif ($section == 'Cancelled'): ?>
                                        <a class="action-btn view" href="view_booking.php?booking_id=<?= $b['booking_id'] ?>">View</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <button class="back-btn" onclick="window.location.href='dashboard.php'">Back</button>
    </div>
</div>

<?php include '../includes/footer.php'; ?>