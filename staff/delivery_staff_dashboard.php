<?php
include '../connect.php';
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');
if (!isset($_SESSION['staff_id'])) {
    header("Location: delivery_staff_login.php");
    exit;
}

$staff_id = $_SESSION['staff_id'];
$staff_name = $_SESSION['staff_name'];

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'], $_POST['service_id'])) {
    $service_id = intval($_POST['service_id']);
    $new_status = $_POST['status'];
    $allowed_statuses = ['pending', 'out_for_delivery', 'delivered'];
    if (in_array($new_status, $allowed_statuses)) {
        $stmt = $conn->prepare("UPDATE service SET status = ? WHERE service_id = ? AND staff_id = ?");
        $stmt->bind_param("sii", $new_status, $service_id, $staff_id);
        $stmt->execute();
        $stmt->close();
        $success_msg = "Service status updated successfully.";
    } else {
        $error_msg = "Invalid status.";
    }
}

// Fetch assigned services for this staff, including pickup and return datetime
$sql = "SELECT s.*, b.cust_id, c.car_brand, c.car_model, cu.username AS customer_name,
               b.pickup_datetime, b.return_datetime
        FROM service s
        JOIN booking b ON s.booking_id = b.booking_id
        JOIN car c ON b.car_id = c.car_id
        JOIN customer cu ON b.cust_id = cu.cust_id
        WHERE s.staff_id = ?
        ORDER BY s.service_id DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $staff_id);
$stmt->execute();
$result = $stmt->get_result();

$services = [];
while ($row = $result->fetch_assoc()) {
    $services[] = $row;
}
$stmt->close();
include 'staff_header.php'; 

// --- ALERT LOGIC ---
date_default_timezone_set("Asia/Kuala_Lumpur");
$now = new DateTime('now');
$alerts = [];
foreach ($services as $row) {
    // Alert for delivery 1 day before pickup
    if (
        in_array($row['service_type'], ['delivery', 'pickup_and_return']) &&
        !empty($row['pickup_datetime'])
    ) {
        $pickup = new DateTime($row['pickup_datetime']);
        $interval = $now->diff($pickup);
        if ($interval->days === 1 && $pickup > $now && $interval->invert === 0) {
            $alerts[] = "Reminder: Deliver car to customer for Booking ID <b>{$row['booking_id']}</b> (" . 
                htmlspecialchars($row['car_brand'].' '.$row['car_model']) . 
                ") at " . $pickup->format('d/m/Y H:i') . ".";
        }
    }
    // Alert for pickup 1 day before return (only for pickup_and_return)
    if (
        $row['service_type'] === 'pickup_and_return' &&
        !empty($row['return_datetime'])
    ) {
        $return = new DateTime($row['return_datetime']);
        $interval = $now->diff($return);
        if ($interval->days === 1 && $return > $now && $interval->invert === 0) {
            $alerts[] = "Reminder: Pickup car from customer for Booking ID <b>{$row['booking_id']}</b> (" . 
                htmlspecialchars($row['car_brand'].' '.$row['car_model']) . 
                ") at " . $return->format('d/m/Y H:i') . ".";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Delivery Staff Dashboard</title>
    <link rel="icon" type="image/png" href="/assets/images/TimeLess_logo.png">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        body { background: #f7f9fa; }
        .dashboard-container { max-width: 1100px; margin: 48px auto 0 auto; }
        .dashboard-header {
            background: #2b5cbc;
            color: #fff;
            border-radius: 12px 12px 0 0;
            padding: 28px 38px 23px 38px;
            box-shadow: 0 2px 16px #b5baf644;
        }
        .dashboard-header h2 { margin: 0; font-size: 2.3em; letter-spacing: 1px; }
        .dashboard-header .staff-name {font-weight: 700;}
        .assigned-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 12px #e0e7ef55;
            margin-top: 0;
            overflow: hidden;
        }
        .assigned-table th, .assigned-table td {
            padding: 13px 10px;
            border-bottom: 1.2px solid #eef2fa;
            text-align: left;
            vertical-align: middle;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .assigned-table th {
            background: #f8fafd;
            font-weight: 700;
            color: #2b5cbc;
            letter-spacing: 0.5px;
        }
        .assigned-table tr:last-child td {
            border-bottom: none;
        }
        .welcome-msg {
            margin: 30px 0 16px 0;
            font-size: 1.25em;
            color: #234c96;
        }
        .msg-success { color: #219150; margin-bottom: 16px;}
        .msg-error { color: #d42d2d; margin-bottom: 16px;}
        .status-form select, .status-form button {
            padding: 7px 10px;
            border-radius: 7px;
            border: 1.5px solid #b5bee5;
            font-size: 1em;
            background: #f7fafd;
            margin-right: 5px;
        }
        .status-form button {
            background: #2b5cbc;
            color: #fff;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.14s;
        }
        .status-form button:hover {
            background: #243570;
        }
        .alert-box {
            background: #fff7e3;
            border: 1.5px solid #f6c967;
            color: #c28d1d;
            padding: 18px 24px;
            border-radius: 10px;
            margin: 26px 0 20px 0;
            font-size: 1.08em;
            box-shadow: 0 2px 12px #ffe7b355;
            font-weight: bold;
        }
        @media (max-width: 900px) {
            .dashboard-header { padding: 14px 8px; }
            .dashboard-header h2 { font-size: 1.5em;}
            .alert-box { font-size: 0.97em; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="dashboard-header">
            <h2>Welcome, <span class="staff-name"><?= htmlspecialchars($staff_name) ?></span></h2>
            <div style="margin-top:7px;">This is your delivery staff dashboard.</div>
        </div>

        <?php if (!empty($alerts)): ?>
            <?php foreach ($alerts as $alert): ?>
                <div class="alert-box"><?= $alert ?></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="welcome-msg">
            Here are your assigned services:
        </div>
        <?php if (isset($success_msg)): ?>
            <div class="msg-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if (isset($error_msg)): ?>
            <div class="msg-error"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>
        <div style="overflow-x:auto;">
        <table class="assigned-table">
            <thead>
                <tr>
                    <th style="width:45px;">#</th>
                    <th style="width:110px;">Service Type</th>
                    <th style="width:110px;">Booking ID</th>
                    <th style="width:120px;">Customer</th>
                    <th style="width:130px;">Car</th>
                    <th style="width:90px;">Fee (RM)</th>
                    <th style="width:130px;">Pickup DateTime</th>
                    <th style="width:130px;">Return DateTime</th>
                    <th style="width:180px;">Location</th>
                    <th style="width:150px;">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($services)): ?>
                <tr><td colspan="10" style="text-align:center;color:#888;">No assigned services found.</td></tr>
            <?php else: ?>
                <?php foreach ($services as $i => $row): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
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
                            <form method="post" class="status-form" style="display:inline;">
                                <input type="hidden" name="service_id" value="<?= $row['service_id'] ?>">
                                <select name="status">
                                    <option value="pending" <?= $row['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="out_for_delivery" <?= $row['status'] == 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
                                    <option value="delivered" <?= $row['status'] == 'delivered' ? 'selected' : '' ?>>Delivered</option>
                                </select>
                                <button type="submit" name="update_status">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</body>
</html>