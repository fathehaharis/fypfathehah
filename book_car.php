<?php
session_start();
if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
include '../includes/header.php';

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

// Malaysia time!
date_default_timezone_set('Asia/Kuala_Lumpur');
$current_datetime = new DateTime();
$current_date = $current_datetime->format('Y-m-d');
$current_time = $current_datetime->format('H:i');
?>
<link rel="stylesheet" href="/assets/css/style.css">

<!-- flatpickr CSS and JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
body { background: #f7f8fa;}
.car-detail-outer { display: flex; align-items: center; justify-content: center; min-height: calc(100vh - 110px); width: 100%; }
.car-detail-card { background: #fff; border-radius: 18px; box-shadow: 0 4px 18px rgba(44,60,102,0.09); width: 520px; margin: 48px 0; padding: 0; }
.car-detail-title { font-size: 1.3em; font-weight: 700; color: #2f377d; margin: 0; padding: 32px 0 8px 0; text-align: left; padding-left: 36px; }
.car-img-bg { background: #f2f5fa; border-radius: 14px; display: flex; justify-content: center; align-items: center; margin: 0 32px 18px 32px; padding: 20px 0 10px 0; }
.car-detail-img { width: 290px; max-width: 100%; height: 120px; object-fit: contain; border-radius: 10px; background: none; display: block; }
.car-detail-table { width: 92%; font-size: 1.06em; margin: 0 auto 20px auto; border-collapse: separate; border-spacing: 0 6px; }
.car-detail-table th, .car-detail-table td { text-align: left; vertical-align: top; padding: 2px 12px 2px 0; font-weight: 600; color: #363636; }
.car-detail-table th { color: #262b56; width: 140px; font-weight: 700; letter-spacing: 0.2px; }
.car-detail-table td { font-weight: 400; color: #202020; }
.form-table { width: 92%; margin: 10px auto 0 auto; font-size: 1.06em; border-collapse: separate; border-spacing: 0 10px; }
.form-table th { width: 140px; color: #262b56; font-weight: 700; text-align: left; vertical-align: middle; padding-right: 10px; }
.form-table td { padding-bottom: 3px; }
input[type="date"], select, input[type="time"], input[type="text"].flatpickr-input { padding: 7px 10px; border-radius: 7px; border: 1px solid #bfc8e6; font-size: 1em; background: #f9fafd; margin-right: 8px; }
input[type="date"]:invalid, input[type="text"].flatpickr-input:invalid { color: #aaa; }
.form-btn-row { width: 92%; margin: 24px auto 0 auto; display: flex; flex-direction: row; justify-content: flex-end; gap: 14px; }
.next-btn { background: #3c4cb8; color: #fff; border: none; padding: 13px 43px; border-radius: 11px; font-size: 1.17em; font-weight: 700; cursor: pointer; transition: background 0.18s; }
.next-btn:hover { background: #234c96; }
.back-btn { background: #e6eaff; color: #2f377d; border: none; padding: 13px 43px; border-radius: 11px; font-size: 1.08em; font-weight: 700; cursor: pointer; transition: background 0.18s; text-decoration: none; text-align: center; display: inline-block; }
.back-btn:hover { background: #cfd8fa; color: #172043; }
@media (max-width: 700px) {
    .car-detail-card { width:98vw; min-width: unset; }
    .car-detail-title { font-size: 1.05em; padding-left: 4vw;}
    .car-img-bg { margin: 0 2vw 10px 2vw; padding: 7px 0 0 0; }
    .car-detail-img { width: 90vw; height: 90px;}
    .car-detail-table th, .form-table th { width: 36vw;}
    .form-btn-row { flex-direction: column; gap: 8px;}
    .next-btn, .back-btn { width: 100%; margin: 0; }
}
</style>
<script>
const unavailableRanges = <?= json_encode($unavailable_ranges) ?>;
const currentDate = "<?= $current_date ?>";
const currentTime = "<?= $current_time ?>";

// Helper for time logic
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
        if (
            selectedDate === currentDate &&
            timeToMinutes(option.value) < timeToMinutes(currentTime)
        ) {
            option.disabled = true;
            option.style.color = '#aaa';
        } else {
            option.disabled = false;
            option.style.color = '';
        }
    }
    // Select first enabled option if selected is disabled
    if (pickupTimeSelect.selectedOptions.length && pickupTimeSelect.selectedOptions[0].disabled) {
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
    returnTimeSelect.setAttribute('readonly', 'readonly');
    returnTimeSelect.setAttribute('disabled', 'disabled');
}

window.addEventListener('DOMContentLoaded', function() {
    const pickupInput = document.querySelector("input[name='pickup_date']");
    const returnInput = document.querySelector("input[name='return_date']");
    const disabledDates = unavailableRanges.map(r => ({from: r.from, to: r.to}));

    // Pickup date picker (block past and unavailable dates!)
    const pickupPicker = flatpickr(pickupInput, {
        minDate: "today",
        maxDate: new Date().fp_incr(365),
        dateFormat: "Y-m-d",
        disable: disabledDates,
        allowInput: false,
        onChange: function(selectedDates, dateStr, instance) {
            if (selectedDates.length) {
                const pickupDate = selectedDates[0];
                const minReturn = new Date(pickupDate);
                minReturn.setDate(minReturn.getDate() + 1);
                const maxReturn = new Date(pickupDate);
                maxReturn.setDate(maxReturn.getDate() + 5);

                returnPicker.set('minDate', minReturn);
                returnPicker.set('maxDate', maxReturn);

                // Auto-adjust return date if invalid
                if (!returnPicker.selectedDates.length || returnPicker.selectedDates[0] < minReturn || returnPicker.selectedDates[0] > maxReturn) {
                    returnPicker.setDate(minReturn, true);
                }
                blockPastPickupTime();
                setReturnTimeOptions();
            }
        }
    });

    // Return date picker (also blocks unavailable dates)
    const returnPicker = flatpickr(returnInput, {
        minDate: "today",
        maxDate: new Date().fp_incr(365),
        dateFormat: "Y-m-d",
        disable: disabledDates,
        allowInput: false
    });

    // Update times on page load and when pickup time or date changes
    blockPastPickupTime();
    setReturnTimeOptions();
    document.querySelector("select[name='pickup_time']").addEventListener('change', setReturnTimeOptions);
    pickupInput.addEventListener('change', function() {
        blockPastPickupTime();
        setReturnTimeOptions();
    });

    // Show/hide delivery location
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
});
</script>

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
            <tr><th>Plate No:</th>       <td><?= htmlspecialchars($car['plate_no']) ?></td></tr>
            <tr><th>Year:</th>           <td><?= htmlspecialchars($car['year']) ?></td></tr>
            <tr><th>Color:</th>          <td><?= htmlspecialchars($car['color']) ?></td></tr>
            <tr><th>Transmission:</th>   <td><?= htmlspecialchars($car['transmission']) ?></td></tr>
            <tr><th>Seat Capacity:</th>  <td><?= htmlspecialchars($car['seat_capacity']) ?></td></tr>
            <tr><th>Mileage:</th>        <td><?= htmlspecialchars($car['mileage']) ?> km</td></tr>
            <tr><th>Daily Rate:</th>     <td>RM <?= number_format($car['daily_rate'], 2) ?></td></tr>
        </table>

        <form action="booking_driver.php" method="POST" style="margin-bottom:0;">
            <input type="hidden" name="car_id" value="<?= $car['car_id'] ?>">

            <table class="form-table">
                <tr>
                    <td colspan="2" style="color:#2f377d; font-size:0.99em; padding-bottom:10px;">
                        <b>Service fee:</b> RM1.50 per km. The actual service amount will be confirmed by admin after booking review.
                    </td>
                </tr>
                <tr>
                    <th>Pickup Date:</th>
                    <td>
                        <!-- NOTE: type="text" for flatpickr! -->
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
                        <!-- Only one option, controlled by JS -->
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
<?php include '../includes/footer.php'; ?>