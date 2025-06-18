<?php
include '../connect.php';
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo "<div style='padding:40px;text-align:center;color:#b33;'>Invalid car ID.</div>";
    exit;
}
$car_id = intval($_GET['id']);

// Handle image upload (add or update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_image'])) {
    if (isset($_FILES['car_image']) && $_FILES['car_image']['error'] === UPLOAD_ERR_OK) {
        $fileData = file_get_contents($_FILES['car_image']['tmp_name']);
        $image_type = $_POST['image_type'] ?? 'main';

        // If an image already exists, update it (replace the first one)
        $check = $conn->query("SELECT car_image_id FROM car_image WHERE car_id = $car_id ORDER BY car_image_id ASC LIMIT 1");
        if ($check && $img = $check->fetch_assoc()) {
            $conn->query("UPDATE car_image SET image_path='" . $conn->real_escape_string($fileData) . "', image_type='" . $conn->real_escape_string($image_type) . "', uploaded_at=NOW() WHERE car_image_id=" . $img['car_image_id']);
        } else {
            $conn->query("INSERT INTO car_image (car_id, image_type, image_path, uploaded_at) VALUES ($car_id, '" . $conn->real_escape_string($image_type) . "', '" . $conn->real_escape_string($fileData) . "', NOW())");
        }
        header("Location: car_details.php?id=$car_id&img_upload=success");
        exit;
    }
}

// Update car documents if form submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_docs'])) {
    $fields = [
        'car_grant_path' => 'grant_file',
        'car_roadtax_path' => 'roadtax_file',
        'car_covernote_path' => 'covernote_file'
    ];
    $updates = [];
    foreach ($fields as $db_field => $input_name) {
        if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] === UPLOAD_ERR_OK) {
            $fileData = file_get_contents($_FILES[$input_name]['tmp_name']);
            $updates[] = "$db_field = '" . $conn->real_escape_string($fileData) . "'";
        }
    }
    if ($updates) {
        $conn->query("UPDATE car SET " . implode(',', $updates) . " WHERE car_id = $car_id");
        header("Location: car_details.php?id=$car_id&doc_upload=success");
        exit;
    }
}

// Delete car
if (isset($_POST['delete_car'])) {
    $conn->query("DELETE FROM car WHERE car_id = $car_id");
    $conn->query("DELETE FROM car_image WHERE car_id = $car_id");
    // Optionally delete bookings, etc.
    header("Location: cars.php?delete=success");
    exit;
}

// Get car info
$car_sql = "SELECT * FROM car WHERE car_id = $car_id";
$car_result = $conn->query($car_sql);
if (!$car_result || !$car_result->num_rows) {
    echo "<div style='padding:40px;text-align:center;color:#b33;'>Car not found.</div>";
    exit;
}
$car = $car_result->fetch_assoc();

// Get car images
$img_sql = "SELECT * FROM car_image WHERE car_id = $car_id ORDER BY car_image_id ASC";
$img_result = $conn->query($img_sql);
$images = [];
while ($img_row = $img_result->fetch_assoc()) {
    $images[] = $img_row;
}

// Document download link function (uses ID and field, not base64)
function docDownloadLink($car_id, $blob, $field, $label) {
    if (!$blob) return '-';
    return "<a href='download_doc.php?car_id=$car_id&field=$field&name=$label' target='_blank'>$label</a>";
}

// Prepare customer name lookup
$customerNames = [];
$cust_sql = "SELECT cust_id, full_name FROM customer";
$cust_res = $conn->query($cust_sql);
while ($cust_res && $row = $cust_res->fetch_assoc()) {
    $customerNames[$row['cust_id']] = $row['full_name'];
}

// Get booking history
$booking_sql = "SELECT * FROM booking WHERE car_id = $car_id ORDER BY return_datetime DESC";
$booking_result = $conn->query($booking_sql);
$bookings = [];
if ($booking_result) {
    while ($row = $booking_result->fetch_assoc()) {
        $bookings[] = $row;
    }
}
?>
<?php include 'admin_header.php'; ?>

<style>
.car-details-container {
    max-width: 900px;
    margin: 40px auto 30px auto;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 16px #e0e7ef33;
    padding: 32px 30px 28px 30px;
}
.car-details-title {
    font-size: 2em;
    font-weight: 800;
    color: #1f3455;
    letter-spacing: 0.5px;
    margin-bottom: 11px;
}
.car-details-plate {
    font-size: 1.13em;
    color: #5874a8;
    font-weight: 600;
    margin-bottom: 14px;
}
.car-details-status {
    display: inline-block;
    margin-bottom: 20px;
    padding: 4px 19px;
    font-size: 1em;
    border-radius: 14px;
    font-weight: 700;
    letter-spacing: 0.2px;
    background: #e6fcf3;
    color: #2bbf5f;
}
.car-details-status.rented {
    background: #ffeded;
    color: #e54848;
}
.car-details-status.maintenance {
    background: #fff7e6;
    color: #e7a84b;
}
.car-details-actions {
    display: flex;
    gap: 32px;
    margin: 13px 0 25px 0;
}
.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 120px;
    height: 60px;
    padding: 0;
    font-size: 1.18em;
    font-weight: 600;
    border-radius: 12px;
    border: 2px solid transparent;
    text-decoration: none;
    transition: background 0.13s, color 0.13s, border 0.13s;
    cursor: pointer;
    box-sizing: border-box;
}
.edit-btn {
    background: #f1f5fb;
    color: #2563eb;
    border: 2px solid #e2e8f0;
}
.edit-btn:hover {
    background: #e6f1fd;
    color: #183c7c;
    border-color: #b0c4e6;
}
.delete-btn {
    background: #fff4f4;
    color: #c32e2e;
    border: 2px solid #efb1b1;
}
.delete-btn:hover {
    background: #ffe3e3;
    color: #a51818;
    border-color: #e48d8d;
}
.car-details-actions form {
    margin: 0;
    padding: 0;
    display: inline;
}
.car-details-actions form button {
    font-family: inherit;
    font-size: 1.18em;
    font-weight: 600;
    width: 120px;
    height: 60px;
    padding: 0;
    border-radius: 12px;
    border: 2px solid #efb1b1;
    background: #fff4f4;
    color: #c32e2e;
    transition: background 0.13s, color 0.13s, border 0.13s;
    box-sizing: border-box;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.car-details-actions form button:hover {
    background: #ffe3e3;
    color: #a51818;
    border-color: #e48d8d;
}
.car-details-section-title {
    color: #304cc3;
    font-weight: 700;
    font-size: 1.12em;
    margin: 26px 0 7px 0;
    letter-spacing: 0.2px;
}
.car-details-table {
    width: 100%;
    border-collapse: collapse;
    margin: 25px 0 20px 0;
    font-size: 1.05em;
}
.car-details-table td {
    padding: 9px 14px;
    border-bottom: 1px solid #eef2fa;
    vertical-align: top;
}
.car-details-table tr:last-child td { border-bottom: none; }
.car-img-gallery {
    display: flex;
    gap: 16px;
    margin: 12px 0 24px 0;
    flex-wrap: wrap;
}
.car-img-gallery img {
    width: 120px;
    height: 80px;
    object-fit: cover;
    border-radius: 8px;
    border: 1.5px solid #e4e8f3;
    background: #f7fafc;
    box-shadow: 0 1.5px 8px #e0e7ef33;
    cursor: pointer;
    transition: transform 0.14s;
}
.car-img-gallery img:hover {
    transform: scale(1.09);
    z-index: 2;
}
.car-details-section-upload, .car-details-section-img-upload {
    margin: 9px 0 19px 0;
    background: #f9fafd;
    border-radius: 8px;
    padding: 13px 19px 10px 19px;
    box-shadow: 0 1.5px 7px #e7e7ef33;
}
.car-details-section-upload label, .car-details-section-img-upload label {
    font-weight: 600;
    color: #344e88;
    display: block;
    margin-bottom: 7px;
}
.car-details-section-upload input[type="file"],
.car-details-section-img-upload input[type="file"] {
    margin-bottom: 11px;
}
.car-details-section-upload button,
.car-details-section-img-upload button {
    background: #304cc3;
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 7px 18px;
    font-size: 1em;
    font-weight: 600;
    cursor: pointer;
}
.car-details-section-upload button:hover,
.car-details-section-img-upload button:hover {
    background: #1b2d70;
}
.booking-history-table {
    width: 100%;
    border-collapse: collapse;
    margin: 12px 0 0 0;
    font-size: 1.01em;
}
.booking-history-table th, .booking-history-table td {
    border-bottom: 1px solid #e9e9f2;
    padding: 8px 9px;
    text-align: left;
}
.booking-history-table th {
    background: #f7fafd;
    color: #304cc3;
    font-weight: 700;
}
.booking-history-table tr:last-child td { border-bottom: none; }
@media (max-width: 700px) {
    .car-details-container { padding: 14px 4vw 18px 4vw;}
    .car-img-gallery img { width: 90px; height: 60px;}
}
</style>

<div class="car-details-container">
    <div class="car-details-title"><?= strtoupper(htmlspecialchars($car['car_brand'])) ?> <?= htmlspecialchars($car['car_model']) ?></div>
    <div class="car-details-plate"><?= htmlspecialchars($car['plate_no']) ?></div>
    <span class="car-details-status<?= 
        $car['status']=='rented' ? ' rented' : ($car['status']=='maintenance' ? ' maintenance' : '') ?>">
        <?= ucfirst($car['status']) ?>
    </span>

    <div class="car-details-actions">
        <a href="edit_car.php?id=<?= $car['car_id'] ?>" class="action-btn edit-btn">Edit</a>
        <form method="post" style="display:inline;">
            <input type="hidden" name="delete_car" value="1" />
            <button type="submit" class="action-btn delete-btn">Delete</button>
        </form>
    </div>

    <div class="car-details-section-title">Details</div>
    <table class="car-details-table">
        <tr>
            <td><b>Year</b></td>
            <td><?= htmlspecialchars($car['year']) ?></td>
            <td><b>Color</b></td>
            <td><?= htmlspecialchars($car['color']) ?></td>
        </tr>
        <tr>
            <td><b>Transmission</b></td>
            <td><?= htmlspecialchars($car['transmission']) ?></td>
            <td><b>Seat Capacity</b></td>
            <td><?= htmlspecialchars($car['seat_capacity']) ?></td>
        </tr>
        <tr>
            <td><b>Mileage (km)</b></td>
            <td><?= htmlspecialchars($car['mileage']) ?></td>
            <td><b>Daily Rate (RM)</b></td>
            <td><?= number_format($car['daily_rate'], 2) ?></td>
        </tr>
        <tr>
            <td><b>Hourly Rate (RM)</b></td>
            <td><?= number_format($car['hourly_rate'], 2) ?></td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <div class="car-details-section-title">Images</div>
    <div class="car-img-gallery">
        <?php if (empty($images)): ?>
            <span style="color:#999;">No images uploaded.</span>
        <?php else: foreach ($images as $img): ?>
            <img src="data:image/jpeg;base64,<?= base64_encode($img['image_path']) ?>"
                 alt="Car image"
                 onclick="window.open(this.src, '_blank')" />
        <?php endforeach; endif; ?>
    </div>
    <div class="car-details-section-img-upload">
        <form method="post" enctype="multipart/form-data">
            <label for="car_image"><?= empty($images) ? "Add Car Image" : "Update Car Image" ?> (jpg/jpeg/png)</label>
            <input type="file" name="car_image" id="car_image" accept="image/*" required>
            <input type="hidden" name="image_type" value="main">
            <button type="submit" name="upload_image"><?= empty($images) ? "Add Image" : "Update Image" ?></button>
        </form>
    </div>

    <div class="car-details-section-title">Documents</div>
    <div class="car-details-section-upload">
        <form method="post" enctype="multipart/form-data">
            <label for="grant_file">Grant (pdf/image)</label>
            <input type="file" name="grant_file" id="grant_file" accept="application/pdf,image/*">
            <?= docDownloadLink($car['car_id'], $car['car_grant_path'], "car_grant_path", "Grant") ?><br>

            <label for="roadtax_file">Roadtax (pdf/image)</label>
            <input type="file" name="roadtax_file" id="roadtax_file" accept="application/pdf,image/*">
            <?= docDownloadLink($car['car_id'], $car['car_roadtax_path'], "car_roadtax_path", "Roadtax") ?><br>

            <label for="covernote_file">Covernote (pdf/image)</label>
            <input type="file" name="covernote_file" id="covernote_file" accept="application/pdf,image/*">
            <?= docDownloadLink($car['car_id'], $car['car_covernote_path'], "car_covernote_path", "Covernote") ?><br>

            <button type="submit" name="upload_docs">Upload/Replace</button>
        </form>
    </div>

    <div class="car-details-section-title">Booking History</div>
    <table class="booking-history-table">
        <tr>
            <th>#</th>
            <th>Customer</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Mileage Before</th>
            <th>Mileage After</th>
            <th>Status</th>
        </tr>
        <?php if (empty($bookings)): ?>
        <tr><td colspan="7" style="color:#999;">No booking history.</td></tr>
        <?php else: foreach ($bookings as $i => $b): ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td><?= htmlspecialchars($customerNames[$b['cust_id']] ?? $b['cust_id']) ?></td>
            <td><?= htmlspecialchars($b['pickup_datetime']) ?></td>
            <td><?= htmlspecialchars($b['return_datetime']) ?></td>
            <td><?= htmlspecialchars($b['pickup_mileage']) ?></td>
            <td><?= htmlspecialchars($b['return_mileage']) ?></td>
            <td><?= htmlspecialchars($b['status']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
    </table>
</div>

<?php include '../includes/footer.php'; ?>