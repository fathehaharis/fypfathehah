<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $car_id = isset($_POST['car_id']) ? (int)$_POST['car_id'] : 0;
    $pickup_date = trim($_POST['pickup_date'] ?? '');
    $pickup_time = trim($_POST['pickup_time'] ?? '');
    $return_date = trim($_POST['return_date'] ?? '');
    $return_time = trim($_POST['return_time'] ?? '');
    $delivery_type = $_POST['delivery_type'] ?? '';
    $location_delivery = trim($_POST['location_delivery'] ?? '');
    $location_return = trim($_POST['location_return'] ?? '');

    // Validate required fields
    if ($car_id <= 0 || empty($pickup_date) || empty($pickup_time) || 
        empty($return_date) || empty($return_time) || empty($delivery_type)) {
        $_SESSION['error'] = "Please fill in all required fields";
        header("Location: book_car.php?car_id=" . $car_id);
        exit;
    }

    // Validate date and time formats
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickup_date) || 
        !preg_match('/^\d{2}:\d{2}$/', $pickup_time) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_date) || 
        !preg_match('/^\d{2}:\d{2}$/', $return_time)) {
        $_SESSION['error'] = "Invalid date or time format";
        header("Location: book_car.php?car_id=" . $car_id);
        exit;
    }

    // Combine date and time properly (without extra spaces)
    $pickup_datetime = "$pickup_date $pickup_time:00";
    $return_datetime = "$return_date $return_time:00";

    // Validate date logic
    try {
        $pickup_dt = new DateTime($pickup_datetime);
        $return_dt = new DateTime($return_datetime);
        $now = new DateTime();
        
        if ($pickup_dt < $now) {
            $_SESSION['error'] = "Pickup date must be in the future";
            header("Location: book_car.php?car_id=" . $car_id);
            exit;
        }
        
        if ($return_dt <= $pickup_dt) {
            $_SESSION['error'] = "Return date must be after pickup date";
            header("Location: book_car.php?car_id=" . $car_id);
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Invalid date/time selection";
        header("Location: book_car.php?car_id=" . $car_id);
        exit;
    }

    // Store in session
    $_SESSION['booking_data'] = [
        'car_id' => $car_id,
        'pickup_datetime' => $pickup_datetime,
        'return_datetime' => $return_datetime,
        'delivery_type' => $delivery_type,
        'location_delivery' => $location_delivery,
        'location_return' => $location_return
    ];

    // Proceed to next step
    header("Location: booking_driver.php");
    exit;
}

// Validate & fetch car details (daily rental only)
$car_id = isset($_GET['car_id']) ? intval($_GET['car_id']) : 0;
if ($car_id <= 0) {
    echo "<div class='no-cars'>Invalid car selection.</div>";
    include '../includes/footer.php';
    exit;
}

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
$result = $stmt->get_result();
$car = $result->fetch_assoc();

if (!$car) {
    echo "<div class='no-cars'>Car not found.</div>";
    include '../includes/footer.php';
    exit;
}

// Fetch unavailable booking ranges (confirmed/pending bookings in the future)
$today = date("Y-m-d");
$booking_sql = "
    SELECT pickup_datetime, return_datetime
    FROM booking
    WHERE car_id = ?
      AND status IN ('confirmed', 'pending')
      AND DATE(return_datetime) >= ?
";
$booking_stmt = $conn->prepare($booking_sql);
$booking_stmt->bind_param("is", $car_id, $today);
$booking_stmt->execute();
$booking_result = $booking_stmt->get_result();
$unavailable_ranges = [];
while ($row = $booking_result->fetch_assoc()) {
    $unavailable_ranges[] = [
        'from' => date('Y-m-d', strtotime($row['pickup_datetime'])),
        'to'   => date('Y-m-d', strtotime($row['return_datetime']))
    ];
}
$booking_stmt->close();

// Pre-fill form data from session if available
$booking_data = $_SESSION['booking_data'] ?? [];
$pickup_date  = isset($booking_data['pickup_datetime']) ? substr($booking_data['pickup_datetime'], 0, 10) : '';
$pickup_time  = isset($booking_data['pickup_datetime']) ? substr($booking_data['pickup_datetime'], 11, 5) : '';
$return_date  = isset($booking_data['return_datetime']) ? substr($booking_data['return_datetime'], 0, 10) : '';
$return_time  = isset($booking_data['return_datetime']) ? substr($booking_data['return_datetime'], 11, 5) : '';
$delivery_type = $booking_data['delivery_type'] ?? '';
$location_delivery = $booking_data['location_delivery'] ?? '';
$location_return = $booking_data['location_return'] ?? '';

$current_datetime = new DateTime();
$current_date = $current_datetime->format('Y-m-d');
$current_time = $current_datetime->format('H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Car - Timeless Car Rental</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error-message" style="color: red; padding: 10px; margin: 10px 0; border: 1px solid red; text-align: center;">
            <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="car-detail-outer">
        <div class="car-detail-card">
            <div class="car-detail-title"><?= htmlspecialchars($car['car_brand'] . ' ' . $car['car_model']) ?></div>
            <div class="car-img-bg">
                <img class="car-detail-img"
                    src="<?= !empty($car['car_image_id']) ? "get_car_image.php?car_image_id=" . $car['car_image_id'] : '/assets/images/viva_elite.png' ?>"
                    alt="Car image"
                    onerror="this.src='/assets/images/viva_elite.png'">
            </div>

            <table class="car-detail-table">
                <tr><th>Plate No:</th><td><?= htmlspecialchars($car['plate_no']) ?></td></tr>
                <tr><th>Year:</th><td><?= htmlspecialchars($car['year']) ?></td></tr>
                <tr><th>Color:</th><td><?= htmlspecialchars($car['color']) ?></td></tr>
                <tr><th>Transmission:</th><td><?= htmlspecialchars($car['transmission']) ?></td></tr>
                <tr><th>Seat Capacity:</th><td><?= htmlspecialchars($car['seat_capacity']) ?></td></tr>
                <tr><th>Mileage:</th><td><?= htmlspecialchars($car['mileage']) ?> km</td></tr>
                <tr><th>Daily Rate:</th><td>RM <?= number_format($car['daily_rate'], 2) ?></td></tr>
            </table>

            <form id="booking-form" action="book_car.php" method="POST" style="margin-bottom:0;">
                <input type="hidden" name="car_id" value="<?= $car['car_id'] ?>">

                <table class="form-table">
                    <tr>
                        <td colspan="2" style="color:#2f377d; font-size:0.99em; padding-bottom:10px;">
                            <b>Service fee:</b> The actual service amount will be confirmed by admin after booking review.
                        </td>
                    </tr>
                    <tr>
                        <th>Pickup Date:</th>
                        <td>
                            <input type="text" name="pickup_date" value="<?= htmlspecialchars($pickup_date) ?>" required readonly="readonly">
                            <span style="margin-left:10px;margin-right:2px;">Time:</span>
                            <select name="pickup_time" required>
                                <?php
                                for ($h = 0; $h < 24; $h++) {
                                    for ($m = 0; $m < 60; $m += 30) {
                                        $hour = str_pad($h, 2, '0', STR_PAD_LEFT);
                                        $minute = str_pad($m, 2, '0', STR_PAD_LEFT);
                                        $val = "$hour:$minute";
                                        $selected = ($pickup_time === $val) ? 'selected' : '';
                                        echo "<option value=\"$val\" $selected>$val</option>";
                                    }
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Return Date:</th>
                        <td>
                            <input type="text" name="return_date" value="<?= htmlspecialchars($return_date) ?>" required readonly="readonly">
                            <span style="margin-left:10px;margin-right:2px;">Time:</span>
                            <select name="return_time" required>
                                <?php if ($pickup_time): ?>
                                    <option value="<?= htmlspecialchars($pickup_time) ?>"><?= htmlspecialchars($pickup_time) ?></option>
                                <?php endif; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Service:</th>
                        <td>
                            <select name="delivery_type" required>
                                <option value="self_pickup" <?= $delivery_type === "self_pickup" ? "selected" : "" ?>>Pick up myself (FREE)</option>
                                <option value="delivery" <?= $delivery_type === "delivery" ? "selected" : "" ?>>Deliver car to me</option>
                                <option value="pickup_and_return" <?= $delivery_type === "pickup_and_return" ? "selected" : "" ?>>Deliver &amp; return pickup</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="location-delivery-row" style="display:none;">
                        <th>Delivery Location:<span class="required-star">*</span></th>
                        <td>
                            <textarea name="location_delivery" rows="2" placeholder="Enter your delivery location"><?= htmlspecialchars($location_delivery) ?></textarea>
                        </td>
                    </tr>
                    <tr id="location-return-row" style="display:none;">
                        <th>Return Car Location:<span class="required-star">*</span></th>
                        <td>
                            <textarea name="location_return" rows="2" placeholder="Enter your return car location"><?= htmlspecialchars($location_return) ?></textarea>
                        </td>
                    </tr>
                </table>
                <div class="form-btn-row">
                    <a href="dashboard.php" class="back-btn">Back</a>
                    <button type="submit" class="next-btn">Next</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
    const unavailableRanges = <?= json_encode($unavailable_ranges) ?>;
    const currentDate = "<?= $current_date ?>";
    const currentTime = "<?= $current_time ?>";

    function timeToMinutes(t) {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    }

    function blockPastPickupTime() {
        const pickupInput = document.querySelector("input[name='pickup_date']");
        const pickupTimeSelect = document.querySelector("select[name='pickup_time']");
        const selectedDate = pickupInput.value;
        
        for (let i = 0; i < pickupTimeSelect.options.length; i++) {
            let option = pickupTimeSelect.options[i];
            if (selectedDate === currentDate && timeToMinutes(option.value) < timeToMinutes(currentTime)) {
                option.disabled = true;
                option.style.color = '#aaa';
            } else {
                option.disabled = false;
                option.style.color = '';
            }
        }
        
        if (pickupTimeSelect.selectedOptions[0]?.disabled) {
            for (let i = 0; i < pickupTimeSelect.options.length; i++) {
                if (!pickupTimeSelect.options[i].disabled) {
                    pickupTimeSelect.selectedIndex = i;
                    break;
                }
            }
        }
    }

    function setReturnTimeOptions() {
        const pickupTimeSelect = document.querySelector("select[name='pickup_time']");
        const returnTimeSelect = document.querySelector("select[name='return_time']");
        returnTimeSelect.innerHTML = '';
        
        if (pickupTimeSelect.value) {
            const opt = document.createElement('option');
            opt.value = pickupTimeSelect.value;
            opt.textContent = pickupTimeSelect.value;
            returnTimeSelect.appendChild(opt);
            returnTimeSelect.value = pickupTimeSelect.value;
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        const pickupInput = document.querySelector("input[name='pickup_date']");
        const returnInput = document.querySelector("input[name='return_date']");
        const disabledDates = unavailableRanges.map(r => ({from: r.from, to: r.to}));

        // Pickup date picker
        const pickupPicker = flatpickr(pickupInput, {
            minDate: "today",
            maxDate: new Date().fp_incr(365),
            dateFormat: "Y-m-d",
            disable: disabledDates,
            allowInput: false,
            onChange: function(selectedDates) {
                if (selectedDates.length) {
                    const pickupDate = selectedDates[0];
                    const minReturn = new Date(pickupDate);
                    minReturn.setDate(minReturn.getDate() + 1);
                    const maxReturn = new Date(pickupDate);
                    maxReturn.setDate(maxReturn.getDate() + 5);

                    returnPicker.set('minDate', minReturn);
                    returnPicker.set('maxDate', maxReturn);

                    if (!returnPicker.selectedDates.length || returnPicker.selectedDates[0] < minReturn || returnPicker.selectedDates[0] > maxReturn) {
                        returnPicker.setDate(minReturn, true);
                    }
                    blockPastPickupTime();
                    setReturnTimeOptions();
                }
            }
        });

        // Return date picker
        const returnPicker = flatpickr(returnInput, {
            minDate: "today",
            maxDate: new Date().fp_incr(365),
            dateFormat: "Y-m-d",
            disable: disabledDates,
            allowInput: false
        });

        // Initialize and set up event listeners
        blockPastPickupTime();
        setReturnTimeOptions();
        document.querySelector("select[name='pickup_time']").addEventListener('change', setReturnTimeOptions);
        pickupInput.addEventListener('change', blockPastPickupTime);

        // Delivery location visibility
        const deliveryTypeSelect = document.querySelector('select[name="delivery_type"]');
        const deliveryRow = document.getElementById('location-delivery-row');
        const returnRow = document.getElementById('location-return-row');
        const deliveryInput = document.querySelector('textarea[name="location_delivery"]');
        const returnInputLoc = document.querySelector('textarea[name="location_return"]');

        function updateLocationVisibility() {
            if (deliveryTypeSelect.value === 'delivery') {
                deliveryRow.style.display = "";
                returnRow.style.display = "none";
                deliveryInput.required = true;
                returnInputLoc.required = false;
                returnInputLoc.value = "";
            } else if (deliveryTypeSelect.value === 'pickup_and_return') {
                deliveryRow.style.display = "";
                returnRow.style.display = "";
                deliveryInput.required = true;
                returnInputLoc.required = true;
            } else {
                deliveryRow.style.display = "none";
                returnRow.style.display = "none";
                deliveryInput.required = false;
                returnInputLoc.required = false;
                deliveryInput.value = "";
                returnInputLoc.value = "";
            }
        }
        
        deliveryTypeSelect.addEventListener('change', updateLocationVisibility);
        updateLocationVisibility();

        // Form validation
        document.getElementById('booking-form').addEventListener('submit', function(e) {
            const pickupDate = document.querySelector('input[name="pickup_date"]');
            const pickupTime = document.querySelector('select[name="pickup_time"]');
            const returnDate = document.querySelector('input[name="return_date"]');
            
            if (!pickupDate.value || !pickupTime.value || !returnDate.value) {
                alert('Please fill in all required fields');
                e.preventDefault();
                return false;
            }
            
            // Verify return date is after pickup date
            const pickup = new Date(pickupDate.value + 'T' + pickupTime.value);
            const returnDt = new Date(returnDate.value + 'T' + pickupTime.value);
            
            if (returnDt <= pickup) {
                alert('Return date must be after pickup date');
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    });
    </script>
    <?php include '../includes/footer.php'; ?>
</body>
</html>