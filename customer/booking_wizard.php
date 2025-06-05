<?php
session_start();
include '../connect.php';
include '../includes/header.php';

$car_id = isset($_GET['car_id']) ? intval($_GET['car_id']) : 0;
$stmt = $conn->prepare("SELECT c.*, COALESCE(img.car_image_id, 0) AS car_image_id FROM car c LEFT JOIN (SELECT car_id, MIN(car_image_id) AS car_image_id FROM car_image GROUP BY car_id) img ON c.car_id = img.car_id WHERE c.car_id = ?");
$stmt->bind_param("i", $car_id);
$stmt->execute();
$result = $stmt->get_result();
$car = $result->fetch_assoc();
$stmt->close();

$driverDetails = [];
if (isset($_SESSION['cust_id'])) {
    $cust_id = $_SESSION['cust_id'];
    $stmt = $conn->prepare("SELECT full_name, phone_no, email, username, license_no, passport_no, id_no, address, country, age FROM customer WHERE cust_id = ?");
    $stmt->bind_param("i", $cust_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $driverDetails = $result->fetch_assoc() ?: [];
    $stmt->close();
}

if (!$car) { echo "<p>Car not found.</p>"; include '../includes/footer.php'; exit; }
?>
<!DOCTYPE html>
<html>
<head>
    <title>Car Booking Wizard</title>
    <link rel="stylesheet" href="booking_wizard.css">
</head>
<body>
    <div id="wizardBar"></div>
    <form id="bookingForm" method="post" enctype="multipart/form-data" autocomplete="off" action="process_booking.php">
        <input type="hidden" name="car_id" value="<?= $car_id ?>">
        <div id="wizardContent"></div>
        <div id="wizardNav"></div>
    </form>
    <script>
    window.car = <?= json_encode($car) ?>;
    window.driverDetailsJson = <?= json_encode($driverDetails) ?>;
    </script>
    <script src="booking_wizard.js"></script>
</body>
</html>
<?php include '../includes/footer.php'; ?>