<?php
session_start();
include '../connect.php';

function save_image($file) {
    if(isset($_FILES[$file]) && $_FILES[$file]['error'] === UPLOAD_ERR_OK) {
        return file_get_contents($_FILES[$file]['tmp_name']);
    }
    return null;
}

$car_id = $_POST['car_id'] ?? 0;
$pickup_date = $_POST['pickup_date'] ?? '';
$pickup_time = $_POST['pickup_time'] ?? '';
$return_date = $_POST['return_date'] ?? '';
$return_time = $_POST['return_time'] ?? '';
$delivery_type = $_POST['delivery_type'] ?? 'self_pickup';
$delivery_location = $_POST['delivery_location'] ?? '';
$cust_name = $_POST['cust_name'] ?? '';
$cust_phone = $_POST['cust_phone'] ?? '';
$cust_email = $_POST['cust_email'] ?? '';
$cust_username = $_POST['cust_username'] ?? '';
$license_no = $_POST['license_no'] ?? '';
$passport_no = $_POST['passport_no'] ?? '';
$id_no = $_POST['id_no'] ?? '';
$country = $_POST['country'] ?? '';
$address = $_POST['address'] ?? '';
$age = $_POST['age'] ?? '';
$id_front_image = save_image('id_front_image');
$id_back_image = save_image('id_back_image');
$guarantor_name = $_POST['guarantor_name'] ?? '';
$guarantor_phone = $_POST['guarantor_phone'] ?? '';
$guarantor_id_no = $_POST['guarantor_id_no'] ?? '';
$guarantor_relationship = $_POST['guarantor_relationship'] ?? '';
$guarantor_id_front_image = save_image('guarantor_id_front_image');
$guarantor_id_back_image = save_image('guarantor_id_back_image');
$cust_signature = $_POST['cust_signature'] ?? '';

$pickup_returntime = $pickup_date . ' ' . $pickup_time . ':00';
$returntime = $return_date . ' ' . $return_time . ':00';

$stmt = $conn->prepare("SELECT cust_id FROM customer WHERE email=? OR username=?");
$stmt->bind_param("ss", $cust_email, $cust_username);
$stmt->execute(); $stmt->bind_result($cust_id);
if (!$stmt->fetch()) {
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO customer (full_name, phone_no, email, username, license_no, passport_no, id_no, id_front_image, id_back_image, address, country, age) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("sssssssssssi", $cust_name, $cust_phone, $cust_email, $cust_username, $license_no, $passport_no, $id_no, $id_front_image, $id_back_image, $address, $country, $age);
    $stmt->execute();
    $cust_id = $stmt->insert_id;
}
$stmt->close();

$stmt = $conn->prepare("INSERT INTO guarantor (cust_id, full_name, phone_no, id_no, id_front_image, id_back_image, relationship) VALUES (?,?,?,?,?,?,?)");
$stmt->bind_param("issssss", $cust_id, $guarantor_name, $guarantor_phone, $guarantor_id_no, $guarantor_id_front_image, $guarantor_id_back_image, $guarantor_relationship);
$stmt->execute();
$guarantor_id = $stmt->insert_id;
$stmt->close();

$stmt = $conn->prepare("SELECT daily_rate FROM car WHERE car_id=?");
$stmt->bind_param("i", $car_id);
$stmt->execute(); $stmt->bind_result($daily_rate); $stmt->fetch(); $stmt->close();
$start = new DateTime($pickup_date);
$end = new DateTime($return_date);
$booking_duration = $start->diff($end)->days;
if ($booking_duration < 1) $booking_duration = 1;
$delivery_fee = ($delivery_type=='delivery')?10:(($delivery_type=='full_delivery')?30:0);
$total_price = $booking_duration * $daily_rate + $delivery_fee;

$stmt = $conn->prepare("INSERT INTO booking (cust_id, car_id, pickup_returntime, returntime, booking_duration, total_price, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
$stmt->bind_param("iissid", $cust_id, $car_id, $pickup_returntime, $returntime, $booking_duration, $total_price);
$stmt->execute();
$booking_id = $stmt->insert_id;
$stmt->close();

if ($delivery_fee>0) {
    $service_type = $delivery_type=='delivery' ? "Deliver car to me" : "Deliver & return pickup";
    $stmt = $conn->prepare("INSERT INTO service (booking_id, service_type, fee, notes) VALUES (?,?,?,?)");
    $stmt->bind_param("isds", $booking_id, $service_type, $delivery_fee, $delivery_location);
    $stmt->execute();
    $stmt->close();
}

if($cust_signature){
    $agreement_file_path = null;
    $signature_blob = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $cust_signature));
    $stmt = $conn->prepare("INSERT INTO agreement_form (booking_id, customer_id, guarantor_id, admin_id, agreement_file_path, cust_signature) VALUES (?,?,?,?,?,?)");
    $null = null; $admin_id = null;
    $stmt->bind_param("iiiibs", $booking_id, $cust_id, $guarantor_id, $admin_id, $agreement_file_path, $signature_blob);
    $stmt->execute();
    $stmt->close();
}

echo "<h2>Booking complete!</h2><p>Your booking is pending confirmation. Please proceed to payment (integration coming soon).</p>";
?>