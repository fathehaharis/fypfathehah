<?php
require_once 'test_setup.php';

class BookingFlowIntegrationTest {
    // Test complete booking creation flow
    public function testBookingCreationFlow() {
        $db = getTestDB();
        
        // Step 1: Create a customer and login
        $customer = createTestCustomer($db, '_flow');
        $logged_in = $this->loginCustomer($db, $customer['username'], $customer['password']);
        
        if (!$logged_in) {
            $this->cleanup($db, null, null, $customer['cust_id']);
            return "Login step failed";
        }
        
        // Step 2: Search for available car
        $available_cars = $this->searchAvailableCars($db, [
            'pickup_date' => date('Y-m-d', strtotime('+1 day')),
            'return_date' => date('Y-m-d', strtotime('+4 days')),
            'seat_capacity' => 5
        ]);
        
        if (count($available_cars) == 0) {
            // Create a car if none available
            $car = createTestCar($db, '_flow');
            $car_id = $car['car_id'];
        } else {
            $car_id = $available_cars[0]['car_id'];
        }
        
        // Step 3: Calculate booking details
        $booking_details = $this->calculateBookingDetails($db, $car_id, 
            date('Y-m-d', strtotime('+1 day')),
            date('Y-m-d', strtotime('+4 days'))
        );
        
        if (!$booking_details) {
            $this->cleanup($db, $car_id, null, $customer['cust_id']);
            return "Booking calculation step failed";
        }
        
        // Step 4: Create booking
        $booking_id = $this->createBooking($db, $customer['cust_id'], $car_id, $booking_details);
        
        if (!$booking_id) {
            $this->cleanup($db, $car_id, null, $customer['cust_id']);
            return "Booking creation step failed";
        }
        
        // Step 5: Process payment
        $payment_result = $this->processPayment($db, $booking_id, $booking_details['total_price']);
        
        if (!$payment_result) {
            $this->cleanup($db, $car_id, $booking_id, $customer['cust_id']);
            return "Payment step failed";
        }
        
        // Step 6: Update booking status
        $status_update = $this->updateBookingStatus($db, $booking_id, 'confirmed');
        
        if (!$status_update) {
            $this->cleanup($db, $car_id, $booking_id, $customer['cust_id']);
            return "Status update step failed";
        }
        
        // Step 7: Check car availability after booking
        $still_available = $this->checkCarAvailability($db, $car_id,
            date('Y-m-d', strtotime('+1 day')),
            date('Y-m-d', strtotime('+4 days'))
        );
        
        if ($still_available) {
            $this->cleanup($db, $car_id, $booking_id, $customer['cust_id']);
            return "Car availability not updated after booking";
        }
        
        // Clean up
        $this->cleanup($db, $car_id, $booking_id, $customer['cust_id']);
        
        return true;
    }
    
    // Helper function for login
    private function loginCustomer($db, $username, $password) {
        $stmt = $db->prepare("SELECT * FROM customer WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            return false;
        }
        
        $customer = $result->fetch_assoc();
        return password_verify($password, $customer['password']);
    }
    
    // Helper function to search available cars
    private function searchAvailableCars($db, $criteria) {
        $query = "
            SELECT c.* 
            FROM car c
            WHERE c.status = 'available'
        ";
        
        $params = [];
        $types = "";
        
        // Add seat capacity filter if provided
        if (isset($criteria['seat_capacity'])) {
            $query .= " AND c.seat_capacity = ?";
            $params[] = $criteria['seat_capacity'];
            $types .= "i";
        }
        
        // Add date availability filter if provided
        if (isset($criteria['pickup_date']) && isset($criteria['return_date'])) {
            $query .= " AND c.car_id NOT IN (
                SELECT b.car_id
                FROM booking b
                WHERE b.status IN ('pending', 'confirmed', 'approved')
                AND (
                    (b.pickup_datetime <= ? AND b.return_datetime >= ?) OR
                    (b.pickup_datetime <= ? AND b.return_datetime >= ?) OR
                    (b.pickup_datetime >= ? AND b.return_datetime <= ?)
                )
            )";
            $params[] = $criteria['return_date'];
            $params[] = $criteria['pickup_date'];
            $params[] = $criteria['pickup_date'];
            $params[] = $criteria['pickup_date'];
            $params[] = $criteria['pickup_date'];
            $params[] = $criteria['return_date'];
            $types .= "ssssss";
        }
        
        $stmt = $db->prepare($query);
        
        if (count($params) > 0) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $cars = [];
        while ($row = $result->fetch_assoc()) {
            $cars[] = $row;
        }
        
        return $cars;
    }
    
    // Helper function to calculate booking details
    private function calculateBookingDetails($db, $car_id, $pickup_date, $return_date) {
        $stmt = $db->prepare("SELECT daily_rate FROM car WHERE car_id = ?");
        $stmt->bind_param("i", $car_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 0) {
            return false;
        }
        
        $car = $result->fetch_assoc();
        $daily_rate = $car['daily_rate'];
        
        $pickup_time = strtotime($pickup_date);
        $return_time = strtotime($return_date);
        $day_count = ceil(($return_time - $pickup_time) / (60 * 60 * 24));
        
        $rental_cost = $daily_rate * $day_count;
        $security_deposit = 100.00;
        $total_price = $rental_cost + $security_deposit;
        
        return [
            'pickup_date' => $pickup_date,
            'return_date' => $return_date,
            'day_count' => $day_count,
            'daily_rate' => $daily_rate,
            'rental_cost' => $rental_cost,
            'security_deposit' => $security_deposit,
            'total_price' => $total_price
        ];
    }
    
    // Helper function to create booking
    private function createBooking($db, $cust_id, $car_id, $details) {
        $stmt = $db->prepare("
            INSERT INTO booking 
            (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
             daily_rate, total_price, security_deposit, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $status = 'pending';
        
        $stmt->bind_param("iissiddds", 
            $cust_id, $car_id, $details['pickup_date'], $details['return_date'],
            $details['day_count'], $details['daily_rate'], $details['total_price'],
            $details['security_deposit'], $status
        );
        
        if (!$stmt->execute()) {
            return false;
        }
        
        return $db->insert_id;
    }
    
    // Helper function to process payment
    private function processPayment($db, $booking_id, $amount) {
        $stmt = $db->prepare("
            INSERT INTO payment
            (booking_id, payment_date, amount, payment_method, payment_status)
            VALUES (?, NOW(), ?, ?, ?)
        ");
        
        $payment_method = 'online_banking';
        $payment_status = 'paid';
        
        $stmt->bind_param("idss", $booking_id, $amount, $payment_method, $payment_status);
        return $stmt->execute();
    }
    
    // Helper function to update booking status
    private function updateBookingStatus($db, $booking_id, $status) {
        $stmt = $db->prepare("UPDATE booking SET status = ? WHERE booking_id = ?");
        $stmt->bind_param("si", $status, $booking_id);
        return $stmt->execute();
    }
    
    // Helper function to check car availability
    private function checkCarAvailability($db, $car_id, $pickup_date, $return_date) {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM booking
            WHERE car_id = ?
            AND status IN ('pending', 'confirmed', 'approved')
            AND (
                (pickup_datetime <= ? AND return_datetime >= ?) OR
                (pickup_datetime <= ? AND return_datetime >= ?) OR
                (pickup_datetime >= ? AND return_datetime <= ?)
            )
        ");
        
        $stmt->bind_param("issssss", 
            $car_id, 
            $return_date, $pickup_date,
            $pickup_date, $pickup_date,
            $pickup_date, $return_date
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $conflicting_bookings = $result->fetch_assoc()['count'];
        
        return $conflicting_bookings == 0; // Available if no conflicting bookings
    }
    
    // Helper function for cleanup
    private function cleanup($db, $car_id = null, $booking_id = null, $cust_id = null) {
        if ($booking_id) {
            $db->query("DELETE FROM payment WHERE booking_id = $booking_id");
            $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
        }
        
        if ($car_id) {
            $db->query("DELETE FROM car WHERE car_id = $car_id");
        }
        
        if ($cust_id) {
            $db->query("DELETE FROM customer WHERE cust_id = $cust_id");
        }
    }
    
    // Test payment integration with booking
    public function testPaymentIntegration() {
        $db = getTestDB();
        
        // Create test data
        $customer = createTestCustomer($db, '_payment_integration');
        $car = createTestCar($db, '_payment_integration');
        
        // Create booking
        $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
        $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
        
        $stmt = $db->prepare("
            INSERT INTO booking 
            (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
             daily_rate, total_price, security_deposit, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $day_count = 3;
        $daily_rate = $car['daily_rate'];
        $total_price = $day_count * $daily_rate + 100.00;
        $security_deposit = 100.00;
        $status = 'pending';
        
        $stmt->bind_param("iissiddds", 
            $customer['cust_id'], $car['car_id'], $pickup_date, $return_date,
            $day_count, $daily_rate, $total_price, $security_deposit, $status
        );
        
        $stmt->execute();
        $booking_id = $db->insert_id;
        
        // Process payment
        $payment_result = $this->processPayment($db, $booking_id, $total_price);
        
        // Check if payment was created
        $stmt = $db->prepare("SELECT * FROM payment WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payment_exists = ($result->num_rows > 0);
        $payment = $result->fetch_assoc();
        
        // Update booking status based on payment
        if ($payment_exists && $payment['payment_status'] == 'paid') {
            $stmt = $db->prepare("UPDATE booking SET status = 'confirmed' WHERE booking_id = ?");
            $stmt->bind_param("i", $booking_id);
            $stmt->execute();
        }
        
        // Check if booking status was updated
        $stmt = $db->prepare("SELECT status FROM booking WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $booking_status = $result->fetch_assoc()['status'];
        
        // Clean up
        $db->query("DELETE FROM payment WHERE booking_id = $booking_id");
        $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
        $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        
        if (!$payment_exists) {
            return "Payment record not created";
        }
        
        if ($booking_status !== 'confirmed') {
            return "Booking status not updated after payment";
        }
        
        return true;
    }
    
    // Test delivery service integration
    public function testDeliveryServiceIntegration() {
        $db = getTestDB();
        
        // Create test data
        $customer = createTestCustomer($db, '_delivery');
        $car = createTestCar($db, '_delivery');
        
        // Create booking
        $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
        $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
        
        $stmt = $db->prepare("
            INSERT INTO booking 
            (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
             daily_rate, total_price, security_deposit, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $day_count = 3;
        $daily_rate = $car['daily_rate'];
        $total_price = $day_count * $daily_rate + 100.00;
        $security_deposit = 100.00;
        $status = 'confirmed';
        
        $stmt->bind_param("iissiddds", 
            $customer['cust_id'], $car['car_id'], $pickup_date, $return_date,
            $day_count, $daily_rate, $total_price, $security_deposit, $status
        );
        
        $stmt->execute();
        $booking_id = $db->insert_id;
        
        // Create delivery staff
        $stmt = $db->prepare("
            INSERT INTO delivery_staff
            (full_name, phone_no, username, password, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $staff_name = "Test Staff";
        $staff_phone = "0123456789";
        $staff_username = "test_staff_" . time();
        $staff_password = password_hash("Password123", PASSWORD_DEFAULT);
        $staff_status = "active";
        
        $stmt->bind_param("sssss", 
            $staff_name, $staff_phone, $staff_username, $staff_password, $staff_status
        );
        
        $stmt->execute();
        $staff_id = $db->insert_id;
        
        // Create delivery service
        $stmt = $db->prepare("
            INSERT INTO service
            (booking_id, service_type, fee, status, delivery_location, staff_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $service_type = 'delivery';
        $fee = 30.00;
        $service_status = 'assigned';
        $location = '123 Test St, City';
        
        $stmt->bind_param("idsssi", 
            $booking_id, $service_type, $fee, $service_status, $location, $staff_id
        );
        
        $service_created = $stmt->execute();
        $service_id = $db->insert_id;
        
        // Update total price to include delivery fee
        $new_total = $total_price + $fee;
        $stmt = $db->prepare("UPDATE booking SET total_price = ? WHERE booking_id = ?");
        $stmt->bind_param("di", $new_total, $booking_id);
        $price_updated = $stmt->execute();
        
        // Check if service was assigned to the staff
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM service
            WHERE booking_id = ? AND staff_id = ?
        ");
        $stmt->bind_param("ii", $booking_id, $staff_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $service_assigned = ($result->fetch_assoc()['count'] > 0);
        
        // Clean up
        $db->query("DELETE FROM service WHERE service_id = $service_id");
        $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
        $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        $db->query("DELETE FROM delivery_staff WHERE staff_id = $staff_id");
        
        if (!$service_created) {
            return "Service creation failed";
        }
        
        if (!$price_updated) {
            return "Price update with delivery fee failed";
        }
        
        if (!$service_assigned) {
            return "Service not properly assigned to staff";
        }
        
        return true;
    }
}

// Run tests
$test = new BookingFlowIntegrationTest();
echo "Booking Creation Flow: " . ($test->testBookingCreationFlow() === true ? "PASSED" : "FAILED") . "\n";
echo "Payment Integration: " . ($test->testPaymentIntegration() === true ? "PASSED" : "FAILED") . "\n";
echo "Delivery Service Integration: " . ($test->testDeliveryServiceIntegration() === true ? "PASSED" : "FAILED") . "\n";
?>