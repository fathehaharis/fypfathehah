<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

include '../connect.php';
// REMOVE profile_guard
// require '../includes/profile_guard.php';
// requireVerifiedProfile($conn, (int)$_SESSION['cust_id']);
$errors = [];

/*
  DAILY RENTAL ONLY RULES:
  - No hourly proration.
  - User chooses: Pickup Date + Pickup Time + Return Date (time auto-locked to same pickup time).
  - Return Date must be at least 1 day AFTER pickup date (minimum 24h rental).
  - Stored datetimes keep the same time segment (e.g., 2025-08-20 19:00:00 to 2025-08-23 19:00:00).
  - Additional: If now is 7pm, earliest selectable pickup time today is 8pm (rounded up to next full hour).
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $car_id          = isset($_POST['car_id']) ? (int)$_POST['car_id'] : 0;
    $pickup_date     = trim($_POST['pickup_date'] ?? '');
    $pickup_time     = trim($_POST['pickup_time'] ?? '');
    $return_date     = trim($_POST['return_date'] ?? '');
    $delivery_type   = $_POST['delivery_type'] ?? 'self_pickup';
    $delivery_loc    = trim($_POST['delivery_location'] ?? '');
    $return_loc      = trim($_POST['return_location'] ?? '');

    // Basic required validation
    if ($car_id <= 0 || !$pickup_date || !$pickup_time || !$return_date || !$delivery_type) {
        $errors[] = "Please fill in all required fields.";
    }

    // Format validation
    if ($pickup_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $pickup_date)) $errors[] = "Invalid pickup date format.";
    if ($return_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $return_date)) $errors[] = "Invalid return date format.";
    if ($pickup_time && !preg_match('/^\d{2}:\d{2}$/', $pickup_time)) $errors[] = "Invalid pickup time format.";

    // Service-specific requirements
    if ($delivery_type === 'delivery' && $delivery_loc === '') {
        $errors[] = "Delivery location is required for Delivery service.";
    }
    if ($delivery_type === 'pickup_and_return') {
        if ($delivery_loc === '') $errors[] = "Delivery location is required.";
        if ($return_loc === '')   $errors[] = "Return pickup location is required.";
    }

    // Build datetimes (return time enforced same as pickup time)
    $pickup_datetime = "$pickup_date $pickup_time:00";
    $return_datetime = "$return_date $pickup_time:00";

    if (empty($errors)) {
        try {
            $pickup_dt = new DateTime($pickup_datetime);
            $return_dt = new DateTime($return_datetime);
            $now       = new DateTime();
            $minLead   = (clone $now)->modify('+1 hour'); // Enforce at least next full hour

            if ($pickup_dt < $minLead) {
                $errors[] = "Pickup must be at least 1 hour from now.";
            }
            // Must be at least 1 day rental
            if ($return_dt <= $pickup_dt || $return_dt->diff($pickup_dt)->days < 1) {
                $errors[] = "Return date must be at least 1 day after pickup date (daily rental).";
            }
        } catch (Exception $e) {
            $errors[] = "Invalid date/time selection.";
        }
    }

    if (empty($errors)) {
        $_SESSION['booking_data'] = [
            'car_id'            => $car_id,
            'pickup_datetime'   => $pickup_datetime,
            'return_datetime'   => $return_datetime,
            'delivery_type'     => $delivery_type,
            'delivery_location' => ($delivery_type === 'self_pickup') ? '' : $delivery_loc,
            'return_location'   => ($delivery_type === 'pickup_and_return') ? $return_loc : ''
        ];
        header("Location: booking_driver.php");
        exit;
    }
}

// Fetch car
$car_id = isset($_GET['car_id']) ? (int)$_GET['car_id'] : ($car_id ?? 0);
if ($car_id <= 0) {
    die("Invalid car selection.");
}

$sql = "
    SELECT c.*,
           COALESCE(main_img.car_image_id, any_img.car_image_id) AS car_image_id
    FROM car c
    LEFT JOIN (
        SELECT car_id, MIN(car_image_id) AS car_image_id
        FROM car_image
        WHERE image_type='main'
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
$stmt->close();

if (!$car) {
    die("Car not found.");
}

/* NEW minimal fix: block booking if car status is not 'available'
   (since enum is now ('available','unavailable')) */
if (strcasecmp($car['status'], 'available') !== 0) {
    die("This car is currently unavailable.");
}

// Unavailable (confirmed/pending) date ranges (client-side guidance only)
$today = date("Y-m-d");
$booking_sql = "
    SELECT pickup_datetime, return_datetime
    FROM booking
    WHERE car_id = ?
      AND status IN ('confirmed','pending')
      AND DATE(return_datetime) >= ?
";
$booking_stmt = $conn->prepare($booking_sql);
$booking_stmt->bind_param("is", $car_id, $today);
$booking_stmt->execute();
$booking_res = $booking_stmt->get_result();
$unavailable_ranges = [];
while ($row = $booking_res->fetch_assoc()) {
    $unavailable_ranges[] = [
        'from' => date('Y-m-d', strtotime($row['pickup_datetime'])),
        'to'   => date('Y-m-d', strtotime($row['return_datetime']))
    ];
}
$booking_stmt->close();

// Prefill
$booking_data      = $_SESSION['booking_data'] ?? [];
// If stored booking data belongs to a different car, ignore it
if (!empty($booking_data) && (int)$booking_data['car_id'] !== $car_id) {
    $booking_data = [];
}

$pickup_dt_s       = $booking_data['pickup_datetime'] ?? '';
$return_dt_s       = $booking_data['return_datetime'] ?? '';
$pickup_date_pref  = $pickup_dt_s ? substr($pickup_dt_s, 0, 10) : '';
$pickup_time_pref  = $pickup_dt_s ? substr($pickup_dt_s, 11, 5) : '';
$return_date_pref  = $return_dt_s ? substr($return_dt_s, 0, 10) : '';

$delivery_type_pref = $booking_data['delivery_type'] ?? 'self_pickup';
$delivery_loc_pref  = $booking_data['delivery_location'] ?? '';
$return_loc_pref    = $booking_data['return_location'] ?? '';

$current_datetime = new DateTime();
$current_date = $current_datetime->format('Y-m-d');
$current_time = $current_datetime->format('H:i');

// Compute the earliest selectable time TODAY: next full hour
$leadMinutes = 60; // enforce at least one hour
$next = (clone $current_datetime)->modify("+{$leadMinutes} minutes");
$minute = (int)$next->format('i');
if ($minute === 0) {
    // already on :00
} elseif ($minute <= 30) {
    $next->setTime((int)$next->format('H'), 30, 0);
} else {
    // past :30 -> move to next hour :00
    $next->modify('+1 hour')->setTime((int)$next->format('H'), 0, 0);
}

$min_time_today = $next->format('H:i');
$min_time_date  = $next->format('Y-m-d');
$cutoffRolledToTomorrow = ($min_time_date !== $current_date);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Book Car (Daily) | Timeless Car Rental</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/css/style.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
body { background:#eceef4; }
.booking-wrapper {
    max-width: 980px;
    margin: 35px auto 60px;
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 34px;
}
@media (max-width:1000px){ .booking-wrapper { grid-template-columns: 1fr; } }
.card {
    background:#fff;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(40,55,95,0.10);
    padding:26px 30px 30px;
    position:relative;
}
.section-title {
    font-size:1.25em;
    font-weight:700;
    letter-spacing:.5px;
    color:#2f377d;
    margin:0 0 18px;
}
.car-img-bg {
    background:#f4f6fb;
    border:1px solid #e2e8f4;
    border-radius:12px;
    overflow:hidden;
    text-align:center;
    padding:10px;
    margin-bottom:18px;
}
.car-img-bg img {
    width:100%; height:210px; object-fit:cover;
}
.car-info-table {
    width:100%; border-collapse:collapse; margin-bottom:6px; font-size:.92em;
}
.car-info-table th {
    text-align:left; width:44%; padding:6px 6px; color:#455175;
    font-weight:600; background:#f5f7fb; border-right:1px solid #e1e7f1;
}
.car-info-table td {
    padding:6px 10px; color:#333; background:#fafbfe;
}
.form-grid {
    width:100%; border-collapse:collapse;
}
.form-grid th {
    text-align:left; width:200px; padding:9px 6px 4px;
    vertical-align:top; font-size:.95em; color:#2d3d66; font-weight:600;
}
.form-grid td { padding:6px 6px 14px; }
input[type="text"], select, textarea {
    width:100%; padding:8px 10px; border:1px solid #d5dae5; border-radius:6px;
    font-size:.95em; background:#fff; resize:vertical;
}
textarea { min-height:70px; }
input[readonly] { background:#f5f7fb; cursor:pointer; }
.inline-note { font-size:.78em; color:#6b7487; margin-top:4px; }
.inline-hint { font-size:.78em; color:#6b7487; margin-top:8px; display:none; }
.required-star { color:#c62828; margin-left:4px; font-weight:700;}
.service-note {
    background:#f8f4e7; border:1px solid #e3d7b8;
    padding:10px 14px; border-radius:8px; font-size:.82em;
    color:#7a6432; margin:0 0 14px;
}
.error-box {
    background:#ffe2e2; border:1px solid #d95353; color:#962222;
    padding:10px 14px; border-radius:8px; margin:0 0 14px; font-size:.85em;
}
.btn-row {
    margin-top:10px; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;
}
.next-btn, .back-btn {
    border:none; cursor:pointer; font-weight:600; font-size:.95em;
    border-radius:8px; padding:12px 26px; transition:.18s;
    text-decoration:none; display:inline-block;
}
.next-btn { background:#3c4cb8; color:#fff; }
.next-btn:hover { background:#234c96; }
.back-btn { background:#d1d5de; color:#222; }
.back-btn:hover { background:#bfc5ce; }
.fade-in { animation:fade .35s ease; }
@keyframes fade {from{opacity:0;transform:translateY(4px);}to{opacity:1;transform:translateY(0);} }
</style>
</head>
<body>
<?php include '../includes/header.php'; ?>

<div class="booking-wrapper fade-in">

    <!-- Car Info -->
    <div class="card">
        <div class="section-title">Selected Car</div>
        <div class="car-img-bg">
            <img src="<?= !empty($car['car_image_id']) ? 'get_car_image.php?car_image_id='.$car['car_image_id'] : '/assets/images/viva_elite.png' ?>"
                 alt="Car Image"
                 onerror="this.src='/assets/images/viva_elite.png'">
        </div>
        <table class="car-info-table">
            <tr><th>Brand / Model</th><td><?= htmlspecialchars($car['car_brand'].' '.$car['car_model']) ?></td></tr>
            <tr><th>Color</th><td><?= htmlspecialchars($car['color']) ?></td></tr>
            <tr><th>Transmission</th><td><?= htmlspecialchars($car['transmission']) ?></td></tr>
            <tr><th>Seat Capacity</th><td><?= htmlspecialchars($car['seat_capacity']) ?></td></tr>
            <tr><th>Mileage</th><td><?= htmlspecialchars($car['mileage']) ?> km</td></tr>
            <tr><th>Daily Rate</th><td>RM <?= number_format($car['daily_rate'],2) ?></td></tr>
        </table>
        <div class="inline-note">
            Rental price = Daily Rate × Number of Days (no hourly proration).
        </div>
    </div>

    <!-- Booking Form -->
    <div class="card">
        <div class="section-title">Booking Details (Daily)</div>

        <?php if (!empty($errors)): ?>
            <div class="error-box">
                <?php foreach($errors as $e): ?>
                    <div><?= htmlspecialchars($e) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="service-note">
            Any delivery / pickup service fee will be confirmed later by admin. Your total will remain provisional until then.<br>
            <strong>Note:</strong> The delivery fee will be updated by admin upon booking approval and is calculated based on the distance between our car rental shop and your selected pickup/return location.
        </div>

        <form id="booking-form" action="book_car.php?car_id=<?= $car_id ?>" method="POST" autocomplete="off" novalidate>
            <input type="hidden" name="car_id" value="<?= $car_id ?>">

            <table class="form-grid">
                <tr>
                    <th>Pickup Date & Time<span class="required-star">*</span></th>
                    <td>
                        <input type="text" name="pickup_date" value="<?= htmlspecialchars($pickup_date_pref) ?>" required readonly>
                        <div style="margin-top:6px;">
                            <select name="pickup_time" id="pickup_time" required style="width:auto; min-width:120px;">
                                <option value="">--:--</option>
                                <?php
                                for ($h=0;$h<24;$h++){
                                    for ($m=0;$m<60;$m+=30){
                                        $hour = str_pad($h,2,'0',STR_PAD_LEFT);
                                        $minute = str_pad($m,2,'0',STR_PAD_LEFT);
                                        $val = "$hour:$minute";
                                        $sel = ($pickup_time_pref === $val) ? 'selected' : '';
                                        echo "<option value=\"$val\" $sel>$val</option>";
                                    }
                                }
                                ?>
                            </select>
                            <div id="time-hint" class="inline-hint"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Return Date (Same Time)<span class="required-star">*</span></th>
                    <td>
                        <input type="text" name="return_date" value="<?= htmlspecialchars($return_date_pref) ?>" required readonly>
                        <input type="hidden" id="return_time_locked" value="">
                        <div class="inline-note">
                            Return time is locked to the same pickup time (daily rental).
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Service Type<span class="required-star">*</span></th>
                    <td>
                        <select name="delivery_type" id="delivery_type" required>
                            <option value="self_pickup" <?= $delivery_type_pref==='self_pickup'?'selected':''; ?>>Self Pickup (FREE)</option>
                            <option value="delivery" <?= $delivery_type_pref==='delivery'?'selected':''; ?>>Delivery (Drop Off)</option>
                            <option value="pickup_and_return" <?= $delivery_type_pref==='pickup_and_return'?'selected':''; ?>>Pickup & Return Service</option>
                        </select>
                    </td>
                </tr>
                <tr id="delivery_location_row" style="display:none;">
                    <th>Delivery Location<span class="required-star">*</span></th>
                    <td>
                        <textarea name="delivery_location" placeholder="Exact address, landmark, instructions"><?= htmlspecialchars($delivery_loc_pref) ?></textarea>
                        <div class="inline-note">Required for Delivery / Pickup & Return.</div>
                    </td>
                </tr>
                <tr id="return_location_row" style="display:none;">
                    <th>Return Pickup Location<span class="required-star">*</span></th>
                    <td>
                        <textarea name="return_location" placeholder="Where the car will be collected"><?= htmlspecialchars($return_loc_pref) ?></textarea>
                        <div class="inline-note">Only required for Pickup & Return Service.</div>
                    </td>
                </tr>
            </table>

            <div class="btn-row">
                <a href="dashboard.php" class="back-btn">Cancel</a>
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
const minTimeToday = "<?= $min_time_today ?>";
const minTimeDate  = "<?= $min_time_date ?>"; // date for minTimeToday (could be tomorrow)
const cutoffRolled = (minTimeDate !== currentDate);

function timeToMinutes(t){
    const [h,m]=t.split(':').map(Number);
    return h*60+m;
}

document.addEventListener('DOMContentLoaded', () => {
    const pickupDateInput  = document.querySelector('input[name="pickup_date"]');
    const pickupTimeSelect = document.getElementById('pickup_time');
    const returnDateInput  = document.querySelector('input[name="return_date"]');
    const deliveryTypeSel  = document.getElementById('delivery_type');
    const deliveryRow      = document.getElementById('delivery_location_row');
    const returnRow        = document.getElementById('return_location_row');
    const delTextarea      = document.querySelector('textarea[name="delivery_location"]');
    const retTextarea      = document.querySelector('textarea[name="return_location"]');
    const timeHintEl       = document.getElementById('time-hint');

    const disabledDates = unavailableRanges.map(r => ({from:r.from, to:r.to}));

    const pickupPicker = flatpickr(pickupDateInput,{
        minDate:"today",
        maxDate:new Date().fp_incr(365),
        dateFormat:"Y-m-d",
        disable:disabledDates,
        onChange:(selected)=>{
            if(selected.length){
                adjustReturnMin(selected[0]);
                blockPastTimesIfToday();
                validateReturnDate();
                updateTimeHint();
            }
        }
    });

    const returnPicker = flatpickr(returnDateInput,{
        minDate: pickupDateInput.value ? pickupDateInput.value : "today",
        maxDate: new Date().fp_incr(365),
        dateFormat:"Y-m-d",
        disable:disabledDates,
        onChange:()=>validateReturnDate()
    });

    function adjustReturnMin(pickupDateObj){
        const minReturn = new Date(pickupDateObj);
        minReturn.setDate(minReturn.getDate()+1);
        returnPicker.set('minDate', minReturn);
        if (!returnPicker.selectedDates.length || returnPicker.selectedDates[0] < minReturn) {
            returnPicker.setDate(minReturn, true);
        }
    }

    function blockPastTimesIfToday(){
        const selectedDate = pickupDateInput.value;
        for (let opt of pickupTimeSelect.options){
            if (!opt.value) continue;
            // Reset first
            opt.disabled=false; opt.style.color='';

            if (selectedDate === currentDate) {
                if (cutoffRolled) {
                    // No slots left today — disable all
                    opt.disabled = true;
                    opt.style.color = '#aaa';
                } else {
                    // Enforce at least next full hour from now (e.g., if 19:xx => min 20:00)
                    if (timeToMinutes(opt.value) < timeToMinutes(minTimeToday)) {
                        opt.disabled = true;
                        opt.style.color = '#aaa';
                    }
                }
            }
        }

        // If current selection is disabled, choose the first available option
        if (pickupTimeSelect.selectedOptions[0]?.disabled){
            let picked = false;
            for (let o of pickupTimeSelect.options){
                if (!o.disabled && o.value){
                    pickupTimeSelect.value=o.value;
                    picked = true;
                    break;
                }
            }
            if (!picked) {
                // No time available for today; suggest picking another date
                pickupTimeSelect.value = '';
            }
        }
    }

    function updateTimeHint(){
        const selectedDate = pickupDateInput.value;
        if (selectedDate === currentDate) {
            timeHintEl.style.display = 'block';
            if (cutoffRolled) {
                timeHintEl.textContent = 'No time slots left today. Please choose tomorrow or later.';
            } else {
                timeHintEl.textContent = 'Earliest available today: ' + minTimeToday;
            }
        } else {
            timeHintEl.style.display = 'none';
            timeHintEl.textContent = '';
        }
    }

    function validateReturnDate(){
        if (!pickupDateInput.value || !returnDateInput.value) return;
        const p = new Date(pickupDateInput.value + 'T00:00:00');
        const r = new Date(returnDateInput.value + 'T00:00:00');
        if (r <= p){
            const newDate = new Date(p);
            newDate.setDate(newDate.getDate()+1);
            returnPicker.setDate(newDate, true);
        }
    }

    function toggleServiceFields(){
        const v = deliveryTypeSel.value;
        if (v==='delivery'){
            deliveryRow.style.display='';
            returnRow.style.display='none';
            delTextarea.required=true;
            retTextarea.required=false;
            retTextarea.value='';
        } else if (v==='pickup_and_return'){
            deliveryRow.style.display='';
            returnRow.style.display='';
            delTextarea.required=true;
            retTextarea.required=true;
        } else {
            deliveryRow.style.display='none';
            returnRow.style.display='none';
            delTextarea.required=false;
            retTextarea.required=false;
        }
    }

    deliveryTypeSel.addEventListener('change',toggleServiceFields);
    pickupDateInput.addEventListener('change',()=>{ blockPastTimesIfToday(); updateTimeHint(); });
    pickupTimeSelect.addEventListener('change',blockPastTimesIfToday);

    blockPastTimesIfToday();
    toggleServiceFields();
    validateReturnDate();
    updateTimeHint();

    document.getElementById('booking-form').addEventListener('submit',(e)=>{
        if (!pickupDateInput.value || !pickupTimeSelect.value || !returnDateInput.value){
            alert('Please complete all required date fields.');
            e.preventDefault();
            return;
        }
        const pickupDT = new Date(pickupDateInput.value + 'T' + pickupTimeSelect.value + ':00');
        const minPickup = new Date();
        minPickup.setMinutes(minPickup.getMinutes() + 60); // at least 1 hour from now
        if (pickupDT < minPickup){
            alert('Pickup must be at least 1 hour from now.');
            e.preventDefault();
            return;
        }
        const returnDT = new Date(returnDateInput.value + 'T' + pickupTimeSelect.value + ':00');
        const dayDiff = (returnDT - pickupDT) / (1000*60*60*24);
        if (dayDiff < 1){
            alert('Return date must be at least 1 day after pickup.');
            e.preventDefault();
        }
    });
});
</script>
<?php include '../includes/footer.php'; ?>
</body>
</html>