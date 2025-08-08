<?php
session_start();
date_default_timezone_set('Asia/Kuala_Lumpur');

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL); // Remove in production

if (!isset($_SESSION['cust_id'])) {
    header("Location: /index.php");
    exit;
}

$booking   = $_SESSION['booking_data']   ?? [];
$guarantor = $_SESSION['guarantor_data'] ?? [];

/* Ensure previous steps done */
if (empty($booking) || empty($guarantor)) {
    header("Location: book_car.php");
    exit;
}

/* Fallback mapping for older key names (ONLY if earlier pages used location_delivery / location_return) */
if (empty($booking['delivery_location']) && !empty($booking['location_delivery'])) {
    $booking['delivery_location'] = $booking['location_delivery'];
}
if (empty($booking['return_location']) && !empty($booking['location_return'])) {
    $booking['return_location'] = $booking['location_return'];
}

function formatDeliveryType(string $t): string {
    if ($t === '') return '';
    return ucwords(str_replace('_',' ', $t));
}

include '../connect.php';

/* Fetch minimal customer info (driver = customer) */
$cust_id = (int)$_SESSION['cust_id'];
$stmt = $conn->prepare("SELECT full_name, phone_no, email, id_no FROM customer WHERE cust_id = ?");
$stmt->bind_param("i", $cust_id);
$stmt->execute();
$stmt->bind_result($c_full_name, $c_phone, $c_email, $c_id_no);
$stmt->fetch();
$stmt->close();

/* Car */
$car_id = (int)($booking['car_id'] ?? 0);
$car = null;
if ($car_id > 0) {
    $stmt = $conn->prepare("SELECT car_brand, car_model, daily_rate FROM car WHERE car_id = ?");
    $stmt->bind_param("i", $car_id);
    $stmt->execute();
    $car = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$pickup_dt_str = $booking['pickup_datetime'] ?? '';
$return_dt_str = $booking['return_datetime'] ?? '';

try { $pickupDT = new DateTime($pickup_dt_str); } catch(Throwable $e){ $pickupDT = null; }
try { $returnDT = new DateTime($return_dt_str); } catch(Throwable $e){ $returnDT = null; }

if (!$pickupDT || !$returnDT || $returnDT <= $pickupDT) {
    header("Location: book_car.php?car_id=".$car_id);
    exit;
}

/* Days (daily rental only) */
$days = isset($booking['booking_duration'])
    ? (int)$booking['booking_duration']
    : max(1, (int)$pickupDT->diff($returnDT)->days);

/* Monetary (from review step, or recompute fallback) */
$rental_subtotal   = isset($booking['rental_subtotal'])
    ? (float)$booking['rental_subtotal']
    : (($car ? (float)$car['daily_rate'] : 0) * $days);

$security_deposit  = isset($booking['security_deposit']) ? (float)$booking['security_deposit'] : 100.00;
$provisional_total = isset($booking['provisional_total']) ? (float)$booking['provisional_total'] : ($rental_subtotal + $security_deposit);

$delivery_type     = $booking['delivery_type']     ?? 'self_pickup';
$delivery_location = trim($booking['delivery_location'] ?? '');
$return_location   = trim($booking['return_location'] ?? '');

$requires_delivery  = in_array($delivery_type, ['delivery','pickup_and_return'], true);
$has_return_segment = ($delivery_type === 'pickup_and_return');

/* Summary line */
$summary_line = sprintf(
    "%s %s | %d day(s) | Pickup: %s | Return: %s",
    $car['car_brand'] ?? 'Car',
    $car['car_model'] ?? '',
    $days,
    $pickupDT->format('Y-m-d H:i'),
    $returnDT->format('Y-m-d H:i')
);

/* Terms */
$agreement_terms = <<<EOT
AGREEMENT OF VEHICLE USAGE BETWEEN BORROWER AND TIMELESS CAR RENTAL

TimeLess Car Rental is a brand operated by TimeLess Car Rental. Attached herewith are the terms and conditions that shall be between the BORROWER of the vehicle and TimeLess Car Rental. When the agreement is signed, the BORROWER has acknowledged that he/she has read, understood and agreed to the terms and conditions.

IT IS HEREBY AGREED AS FOLLOWS:
1. The consolation loan agreed for this vehicle is as per agreed in the quotation. No claim will be made by the BORROWER if the vehicle is returned earlier than the promised expiry date and time.
2. TimeLess Car Rental reserves the right to claim additional consolation if the vehicle is returned late after the expiry of the LOAN as above. For vehicles with a daily rate of less than RM300, the value claimed is RM25/hour. For vehicles with a daily rate of more than RM300, the claimed value is RM60/hour. TimeLess Car Rental also has the right to exercise discretion in determining the level of demand for this clause (2).
3. Any loan extension must be notified to TimeLess Car Rental at least 3 hours in advance, subject to the availability of the vehicle. Only ONE (1) EXTENSION is allowed. For another extension, presence at the office with the vehicle is required.
4. This vehicle is not used for any criminal activities or offences under Malaysian law.
5. THE BORROWER is fully responsible for any misuse or criminal involvement.
6. The BORROWER must settle all summonses, compounds, or fines within the loan tenure.
7. TimeLess Car Rental may claim costs for any damage/accident/unauthorized repairs during the tenure.
8. If the vehicle is lost or severely damaged, BORROWER bears all related costs.
9. TimeLess Car Rental may keep copies of identification/supporting documents for processing.
10. BORROWER remains liable for issues arising after deposit refund if originating within loan period.
11. Vehicle must be returned with full fuel; failing which RM100 may be charged.
12. TimeLess Car Rental may contact the GUARANTOR if BORROWER cannot resolve arising issues.
13. Only the BORROWER may drive the vehicle (no unauthorized drivers).
14. No personal accident or property insurance is provided by TimeLess Car Rental.
15. BORROWER & GUARANTOR acknowledge vehicle compartments were shown empty of prohibited goods; they release TimeLess Car Rental from related claims.
16. Security deposit (as agreed) is held against breaches, unauthorized travel, or summons; refundable (less deductions) within five (5) working days after return.
DELIVERY FEE (If Applicable):
A delivery / pickup service fee is NOT included in the provisional total and will be confirmed by an administrator. BORROWER agrees final payable amount may increase once set.
EOT;

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Rental Agreement & Signature | Timeless Car Rental</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="/assets/css/style.css">
<style>
body { background:#eceef4; }
.agreement-section {
    max-width: 760px;
    margin: 40px auto 70px;
    background:#fff;
    border-radius:14px;
    box-shadow:0 4px 18px rgba(40,55,95,0.10);
    padding:34px 42px 36px;
}
.agreement-title {
    font-size:1.38em;
    font-weight:700;
    color:#2f377d;
    margin:0 0 8px;
    letter-spacing:.5px;
}
.summary-line {
    font-size:.82em;
    color:#4d5875;
    margin-bottom:20px;
    background:#f2f5fa;
    border:1px solid #e2e7f1;
    padding:8px 12px;
    border-radius:8px;
    line-height:1.35em;
}
.mini-table {
    width:100%; border-collapse:collapse;
    margin: 0 0 18px; font-size:.9em;
}
.mini-table th, .mini-table td { padding:8px 10px; vertical-align:top; }
.mini-table th {
    width:200px; text-align:left; background:#f5f6fa;
    font-weight:600; color:#3a4769; border-right:1px solid #dfe3ed;
    font-size:.8em; letter-spacing:.4px;
}
.mini-table td { background:#fafbfe; color:#2d364d; }
.badge-pending {
    display:inline-block; font-size:.65em; padding:3px 7px;
    background:#b47106; color:#fff; border-radius:10px; letter-spacing:.5px;
}
.agreement-terms {
    font-size:.86em; color:#243049; background:#f7f9fc;
    padding:16px 18px; border-radius:10px; height:300px; overflow-y:auto;
    line-height:1.45em; border:1px solid #e3e8f3; white-space:pre-line;
    margin-bottom:18px;
}
.input-row { margin-bottom:18px; }
input[type="checkbox"] { margin-right:8px; transform:scale(1.15); }
.sig-label { font-weight:600; color:#2d3d66; font-size:.9em; letter-spacing:.4px; }
#signature-pad {
    width:100%; height:140px; border:1.5px solid #bfc4e1;
    border-radius:8px; background:#fff; margin-top:6px; cursor:crosshair;
}
.sig-tools { margin-top:8px; }
.sig-tools button {
    background:#e3e7f0; border:none; padding:6px 16px;
    font-size:.8em; border-radius:6px; cursor:pointer; transition:.18s;
}
.sig-tools button:hover { background:#d2d8e4; }
.btn-row {
    margin-top:30px; display:flex; justify-content:flex-end;
    gap:10px; flex-wrap:wrap;
}
.action-btn {
    border:none; padding:12px 30px; border-radius:8px;
    font-size:1em; font-weight:600; cursor:pointer;
    transition:.18s; text-decoration:none;
}
.back-btn { background:#d1d5de; color:#222; }
.back-btn:hover { background:#bfc5ce; }
.submit-btn { background:#3c4cb8; color:#fff; }
.submit-btn:hover { background:#234c96; }
.pending-note {
    font-size:.72em; color:#7a4d05; margin-top:-12px; margin-bottom:16px;
}
.small-hint { font-size:.7em; color:#6c788f; margin-top:6px; }
@media (max-width:860px){
    .agreement-section { padding:28px 26px 34px; }
    .mini-table th { width:40%; }
}
</style>
</head>
<body>
<?php include '../includes/header.php'; ?>

<div class="agreement-section">
    <div class="agreement-title">Rental Agreement & Signature</div>
    <div class="summary-line">
        <?= htmlspecialchars($summary_line) ?><br>
        <?php if ($requires_delivery): ?>
            Delivery Type: <?= htmlspecialchars(formatDeliveryType($delivery_type)) ?>
            <?php if ($delivery_location): ?> | Delivery: <?= htmlspecialchars($delivery_location) ?><?php endif; ?>
            <?php if ($has_return_segment && $return_location): ?> | Return Pickup: <?= htmlspecialchars($return_location) ?><?php endif; ?>
        <?php else: ?>
            Service Type: Self Pickup
        <?php endif; ?>
    </div>

    <table class="mini-table">
        <tr><th>Car</th><td><?= htmlspecialchars(($car['car_brand'] ?? 'Car').' '.($car['car_model'] ?? '')) ?></td></tr>
        <tr><th>Pickup Date & Time</th><td><?= htmlspecialchars($pickupDT->format('Y-m-d H:i')) ?></td></tr>
        <tr><th>Return Date & Time</th><td><?= htmlspecialchars($returnDT->format('Y-m-d H:i')) ?></td></tr>
        <tr><th>Duration</th><td><?= $days ?> day(s)</td></tr>
        <tr><th>Daily Rate</th><td>RM <?= number_format($car['daily_rate'] ?? 0, 2) ?></td></tr>
        <tr><th>Rental Subtotal</th><td>RM <?= number_format($rental_subtotal,2) ?></td></tr>
        <tr><th>Security Deposit</th><td>RM <?= number_format($security_deposit,2) ?></td></tr>
        <?php if ($requires_delivery): ?>
            <tr><th>Delivery Fee</th><td><span class="badge-pending">PENDING</span></td></tr>
            <tr><th>Provisional Total (Excl Delivery)</th><td><strong>RM <?= number_format($provisional_total,2) ?></strong></td></tr>
        <?php else: ?>
            <tr><th>Total Payable</th><td><strong>RM <?= number_format($provisional_total,2) ?></strong></td></tr>
        <?php endif; ?>
    </table>

    <?php if ($requires_delivery): ?>
        <div class="pending-note">Final total will increase once the delivery/pickup service fee is confirmed by admin.</div>
    <?php endif; ?>

    <form action="booking_submit.php" method="POST" onsubmit="return submitAgreementForm();" enctype="multipart/form-data">
        <div class="agreement-terms"><?= nl2br(htmlspecialchars($agreement_terms)) ?></div>

        <div class="input-row">
            <label>
                <input type="checkbox" id="agree" name="agree" value="1" required>
                I have read and agree to all terms above.
            </label>
        </div>

        <div class="input-row">
            <label class="sig-label">Signature (draw below):</label>
            <canvas id="signature-pad"></canvas>
            <input type="hidden" name="signature_data" id="signature_data" required>
            <div class="sig-tools">
                <button type="button" onclick="clearSignaturePad();">Clear</button>
            </div>
            <div class="small-hint">Your signature will be stored with this booking.</div>
        </div>

        <div class="btn-row">
            <a href="review_booking.php" class="action-btn back-btn">Back</a>
            <button type="submit" class="action-btn submit-btn">Submit Booking</button>
        </div>
    </form>
</div>

<script>
const canvas = document.getElementById('signature-pad');
const ctx = canvas.getContext('2d');
let drawing = false;

function resizeCanvas(){
    const ratio = window.devicePixelRatio || 1;
    const w = canvas.clientWidth;
    const h = canvas.clientHeight;
    canvas.width = w * ratio;
    canvas.height = h * ratio;
    ctx.scale(ratio, ratio);
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#1d2e6f';
}
resizeCanvas();
window.addEventListener('resize', () => {
    // Clear on resize (simpler). If you want to preserve, you'd need to copy image data.
    resizeCanvas();
});

function getPos(e){
    if (e.touches){
        const rect = canvas.getBoundingClientRect();
        return {
            x: e.touches[0].clientX - rect.left,
            y: e.touches[0].clientY - rect.top
        };
    }
    return { x: e.offsetX, y: e.offsetY };
}

function startDraw(e){
    e.preventDefault();
    drawing = true;
    const p = getPos(e);
    ctx.beginPath();
    ctx.moveTo(p.x, p.y);
}
function draw(e){
    if (!drawing) return;
    e.preventDefault();
    const p = getPos(e);
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
}
function endDraw(e){
    if (!drawing) return;
    drawing = false;
}

canvas.addEventListener('mousedown', startDraw);
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('mouseup', endDraw);
canvas.addEventListener('mouseleave', endDraw);
canvas.addEventListener('touchstart', startDraw, {passive:false});
canvas.addEventListener('touchmove', draw, {passive:false});
canvas.addEventListener('touchend', endDraw, {passive:false});

function clearSignaturePad(){ ctx.clearRect(0,0,canvas.width,canvas.height); }

function isBlank(){
    const blank = document.createElement('canvas');
    blank.width = canvas.width;
    blank.height = canvas.height;
    return canvas.toDataURL() === blank.toDataURL();
}

function submitAgreementForm(){
    if (!document.getElementById('agree').checked) {
        alert("Please tick the agreement checkbox.");
        return false;
    }
    if (isBlank()){
        alert("Please provide your signature.");
        return false;
    }
    document.getElementById('signature_data').value = canvas.toDataURL('image/png');
    return true;
}
</script>

<?php include '../includes/footer.php'; ?>
</body>
</html>