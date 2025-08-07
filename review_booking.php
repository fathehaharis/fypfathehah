<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

// Require booking flow prerequisites
if (empty($_SESSION['booking_data'])) {
    header("Location: book_car.php");
    exit;
}

// Optional: ensure driver step completed
if (empty($_SESSION['customer_driver_complete'])) {
    header("Location: booking_driver.php");
    exit;
}

$booking = $_SESSION['booking_data'];
$customer = $_SESSION['customer_data'] ?? [];
$guarantor = $_SESSION['guarantor_data'] ?? [];
$cust_id = $_SESSION['cust_id'];

$car_id = $booking['car_id'] ?? null;
if (!$car_id) {
    header("Location: book_car.php");
    exit;
}

// Fetch car info
$sql = "
    SELECT c.*, 
           COALESCE(main_img.car_image_id, any_img.car_image_id) AS car_image_id
    FROM car c
    LEFT JOIN (
        SELECT car_id, MIN(car_image_id) AS car_image_id
        FROM car_image
        WHERE image_type = 'main'
        GROUP BY car_id
    ) main_img ON c.car_id = main_img.car_id
    LEFT JOIN (
        SELECT car_id, MIN(car_image_id) AS car_image_id
        FROM car_image
        GROUP BY car_id
    ) any_img ON c.car_id = any_img.car_id
    WHERE c.car_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $car_id);
$stmt->execute();
$car = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$car) {
    echo "<div style='padding:20px;color:#b00020;'>Car not found.</div>";
    exit;
}

// Parse dates
$pickup_datetime = new DateTime($booking['pickup_datetime']);
$return_datetime = new DateTime($booking['return_datetime']);
$interval = $pickup_datetime->diff($return_datetime);
$days = max(1, (int)$interval->format('%a'));

$delivery_type = $booking['delivery_type'] ?? 'self_pickup';
$location_delivery = $booking['location_delivery'] ?? '';
$location_return = $booking['location_return'] ?? '';

$errors = [];

// Confirm booking handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_booking'])) {
    // Basic validations
    if ($return_datetime <= $pickup_datetime) {
        $errors[] = 'Return date/time must be after pickup date/time.';
    }

    // Check for overlap with existing bookings
    if (empty($errors)) {
        $overlap_sql = "
            SELECT 1
            FROM booking
            WHERE car_id = ?
              AND status IN ('confirmed', 'pending')
              AND pickup_datetime < ?
              AND return_datetime > ?
            LIMIT 1
        ";
        $overlap_stmt = $conn->prepare($overlap_sql);
        $pickup_str = $pickup_datetime->format('Y-m-d H:i:s');
        $return_str = $return_datetime->format('Y-m-d H:i:s');
        $overlap_stmt->bind_param("iss", $car_id, $return_str, $pickup_str);
        $overlap_stmt->execute();
        $overlap = $overlap_stmt->get_result()->fetch_row();
        $overlap_stmt->close();
        if ($overlap) {
            $errors[] = 'Selected dates overlap with an existing booking. Please go back and choose different dates.';
        }
    }

    if (empty($errors)) {
        // Insert booking (pending)
        $insert_sql = "
            INSERT INTO booking (
                car_id, cust_id, pickup_datetime, return_datetime,
                delivery_type, location_delivery, location_return,
                status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
        ";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param(
            "iisssss",
            $car_id,
            $cust_id,
            $pickup_str,
            $return_str,
            $delivery_type,
            $location_delivery,
            $location_return
        );
        if ($insert_stmt->execute()) {
            $new_booking_id = $insert_stmt->insert_id;
            $insert_stmt->close();

            // Optionally clear transient flags but keep data for success page if needed
            $_SESSION['last_booking_id'] = $new_booking_id;

            header("Location: booking_success.php");
            exit;
        } else {
            $errors[] = 'Failed to create booking. Please try again.';
        }
    }
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="/assets/css/style.css">
<style>
.review-wrap {
    max-width: 900px;
    margin: 40px auto;
    background: #fff;
    border-radius: 13px;
    box-shadow: 0 4px 16px rgba(44,60,102,0.09);
    padding: 24px 28px;
}
.review-title { font-size: 1.28em; font-weight: 700; color: #2f377d; margin: 4px 0 18px 0; }
.section { margin-bottom: 18px; }
.section h3 { margin: 0 0 10px 0; font-size: 1.08em; color: #2a2f5a; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.kv { display: grid; grid-template-columns: 170px 1fr; gap: 8px; row-gap: 10px; }
.label { color: #4a4a4a; font-weight: 600; }
.value { color: #1e1e1e; }
.car-img { max-width: 260px; height: 120px; object-fit: contain; background: #f2f5fa; border-radius: 8px; padding: 8px; }
.divider { height: 1px; background: #e9ecf5; margin: 16px 0; }
.btn-row { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
.next-btn { background: #3c4cb8; color: #fff; border: none; padding: 12px 30px; border-radius: 7px; font-size: 1.06em; font-weight: 600; cursor: pointer; }
.back-btn { background: #ccc; color: #222; border: none; padding: 12px 30px; border-radius: 7px; font-size: 1.06em; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
.error-message { background: #ffe0e0; color: #a80000; border: 1px solid #a80000; padding: 10px; margin-bottom: 15px; border-radius: 5px; }
.small { color: #6a6a6a; font-size: 0.94em; }
@media (max-width: 800px) { .grid { grid-template-columns: 1fr; } .kv { grid-template-columns: 140px 1fr; } }
</style>

<div class="review-wrap">
    <div class="review-title">Review Your Booking</div>

    <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $err): ?>
                <div><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section grid">
        <div>
            <h3>Car</h3>
            <div class="kv">
                <div class="label">Car</div>
                <div class="value"><?= htmlspecialchars($car['car_brand'] . ' ' . $car['car_model']) ?></div>
                <div class="label">Plate No</div>
                <div class="value"><?= htmlspecialchars($car['plate_no']) ?></div>
                <div class="label">Daily Rate</div>
                <div class="value">RM <?= number_format($car['daily_rate'], 2) ?></div>
                <div class="label">Duration</div>
                <div class="value"><?= $days ?> day(s)</div>
                <div class="label">Pickup</div>
                <div class="value"><?= htmlspecialchars($pickup_datetime->format('Y-m-d H:i')) ?></div>
                <div class="label">Return</div>
                <div class="value"><?= htmlspecialchars($return_datetime->format('Y-m-d H:i')) ?></div>
            </div>
        </div>
        <div>
            <img class="car-img" src="<?= !empty($car['car_image_id']) ? "get_car_image.php?car_image_id=" . $car['car_image_id'] : '/assets/images/viva_elite.png' ?>" alt="Car image" onerror="this.src='/assets/images/viva_elite.png'">
        </div>
    </div>

    <div class="divider"></div>

    <div class="section grid">
        <div>
            <h3>Service</h3>
            <div class="kv">
                <div class="label">Type</div>
                <div class="value"><?= htmlspecialchars(str_replace('_',' ', $delivery_type)) ?></div>
                <?php if ($delivery_type === 'delivery' || $delivery_type === 'pickup_and_return'): ?>
                    <div class="label">Delivery Location</div>
                    <div class="value"><?= htmlspecialchars($location_delivery ?: '-') ?></div>
                <?php endif; ?>
                <?php if ($delivery_type === 'pickup_and_return'): ?>
                    <div class="label">Return Pickup Location</div>
                    <div class="value"><?= htmlspecialchars($location_return ?: '-') ?></div>
                <?php endif; ?>
                <div class="label">Service Fee</div>
                <div class="value">RM 1.50 per km <span class="small">(final amount to be confirmed by admin)</span></div>
            </div>
        </div>
        <div>
            <h3>Cost</h3>
            <div class="kv">
                <div class="label">Car Rental</div>
                <div class="value">RM <?= number_format($car['daily_rate'] * $days, 2) ?></div>
                <div class="label">Service Fee</div>
                <div class="value">To be confirmed</div>
                <div class="label">Estimated Total</div>
                <div class="value">RM <?= number_format($car['daily_rate'] * $days, 2) ?> + service</div>
            </div>
        </div>
    </div>

    <div class="divider"></div>

    <div class="section grid">
        <div>
            <h3>Your Details (Driver)</h3>
            <div class="kv">
                <div class="label">Full Name</div>
                <div class="value"><?= htmlspecialchars($customer['full_name'] ?? '') ?></div>
                <div class="label">Phone</div>
                <div class="value"><?= htmlspecialchars($customer['phone_no'] ?? '') ?></div>
                <div class="label">ID No</div>
                <div class="value"><?= htmlspecialchars($customer['id_no'] ?? '') ?></div>
                <div class="label">Address</div>
                <div class="value"><?= htmlspecialchars($customer['address'] ?? '') ?></div>
                <div class="label">Age</div>
                <div class="value"><?= htmlspecialchars($customer['age'] ?? '') ?></div>
            </div>
        </div>
        <div>
            <h3>Guarantor</h3>
            <div class="kv">
                <div class="label">Full Name</div>
                <div class="value"><?= htmlspecialchars($guarantor['guarantor_full_name'] ?? '') ?></div>
                <div class="label">Phone</div>
                <div class="value"><?= htmlspecialchars($guarantor['guarantor_phone_no'] ?? '') ?></div>
                <div class="label">ID No</div>
                <div class="value"><?= htmlspecialchars($guarantor['guarantor_id_no'] ?? '') ?></div>
                <div class="label">Relationship</div>
                <div class="value"><?= htmlspecialchars($guarantor['guarantor_relationship'] ?? '') ?></div>
            </div>
        </div>
    </div>

    <form method="POST" class="btn-row">
        <a class="back-btn" href="booking_guarantor.php?car_id=<?= htmlspecialchars((string)$car_id) ?>">Back</a>
        <button type="submit" name="confirm_booking" value="1" class="next-btn">Confirm Booking</button>
    </form>
</div>

<?php include '../includes/footer.php'; ?>