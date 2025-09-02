<?php
require_once 'test_setup.php';

class BookingUnitTest {
    // Test booking validation
    public function testBookingValidation() {
        $db = getTestDB();
        
        // Create test data
        $customer = createTestCustomer($db, '_booking_validation');
        $car = createTestCar($db, '_booking_validation');
        
        // Valid booking data
        $valid_booking = [
            'cust_id' => $customer['cust_id'],
            'car_id' => $car['car_id'],
            'pickup_date' => date('Y-m-d', strtotime('+1 day')),
            'return_date' => date('Y-m-d', strtotime('+4 days')),
        ];
        
        // Invalid: past dates
        $past_booking = [
            'cust_id' => $customer['cust_id'],
            'car_id' => $car['car_id'],
            'pickup_date' => date('Y-m-d', strtotime('-2 days')),
            'return_date' => date('Y-m-d', strtotime('+1 day')),
        ];
        
        // Invalid: return before pickup
        $reversed_dates_booking = [
            'cust_id' => $customer['cust_id'],
            'car_id' => $car['car_id'],
            'pickup_date' => date('Y-m-d', strtotime('+4 days')),
            'return_date' => date('Y-m-d', strtotime('+1 day')),
        ];
        
        // Run validations
        $valid_result = $this->validateBookingDates(
            $valid_booking['pickup_date'], 
            $valid_booking['return_date']
        );
        
        $past_result = $this->validateBookingDates(
            $past_booking['pickup_date'], 
            $past_booking['return_date']
        );
        
        $reversed_result = $this->validateBookingDates(
            $reversed_dates_booking['pickup_date'], 
            $reversed_dates_booking['return_date']
        );
        
        // Clean up
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
        
        if (!$valid_result) {
            return "Valid booking validation failed";
        }
        
        if ($past_result) {
            return "Past date validation failed";
        }
        
        if ($reversed_result) {
            return "Reversed dates validation failed";
        }
        
        return true;
    }
    
    // Helper function to validate booking dates
    private function validateBookingDates($pickup_date, $return_date) {
        $today = date('Y-m-d');
        $pickup_timestamp = strtotime($pickup_date);
        $return_timestamp = strtotime($return_date);
        $today_timestamp = strtotime($today);
        
        // Check if pickup date is in the past
        if ($pickup_timestamp < $today_timestamp) {
            return false;
        }
        
        // Check if return date is before pickup date
        if ($return_timestamp < $pickup_timestamp) {
            return false;
        }
        
        return true;
    }
    
    // Test booking status updates
    public function testBookingStatusUpdates() {
        $db = getTestDB();
        
        // Create test data
        $customer = createTestCustomer($db, '_status_updates');
        $car = createTestCar($db, '_status_updates');
        
        // Create initial booking
        $booking_id = $this->createTestBooking($db, $customer['cust_id'], $car['car_id'], 'pending');
        
        // Test status transitions
        $to_confirmed = $this->updateBookingStatus($db, $booking_id, 'confirmed');
        $status_after_confirm = $this->getBookingStatus($db, $booking_id);
        
        $to_completed = $this->updateBookingStatus($db, $booking_id, 'completed');
        $status_after_complete = $this->getBookingStatus($db, $booking_id);
        
        // Invalid transition (should fail)
        $to_pending = $this->updateBookingStatus($db, $booking_id, 'pending');
        $status_after_invalid = $this->getBookingStatus($db, $booking_id);
        
        // Clean up
        $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
        
        if (!$to_confirmed || $status_after_confirm !== 'confirmed') {
            return "Transition to confirmed failed";
        }
        
        if (!$to_completed || $status_after_complete !== 'completed') {
            return "Transition to completed failed";
        }
        
        if ($to_pending || $status_after_invalid !== 'completed') {
            return "Invalid transition check failed";
        }
        
        return true;
    }
    
    // Helper function to create test booking
    private function createTestBooking($db, $cust_id, $car_id, $status) {
        $pickup_date = date('Y-m-d H:i:s', strtotime('+1 day'));
        $return_date = date('Y-m-d H:i:s', strtotime('+4 days'));
        
        $stmt = $db->prepare("
            INSERT INTO booking 
            (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
             daily_rate, total_price, security_deposit, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $day_count = 3;
        $daily_rate = 150.00;
        $total_price = $day_count * $daily_rate + 100.00;
        $security_deposit = 100.00;
        
        $stmt->bind_param("iissiddds", 
            $cust_id, $car_id, $pickup_date, $return_date,
            $day_count, $daily_rate, $total_price, $security_deposit, $status
        );
        $stmt->execute();
        
        return $db->insert_id;
    }
    
    // Helper function to update booking status
    private function updateBookingStatus($db, $booking_id, $new_status) {
        // Get current status
        $current_status = $this->getBookingStatus($db, $booking_id);
        
        // Check if transition is allowed
        $valid_transition = $this->isValidStatusTransition($current_status, $new_status);
        
        if (!$valid_transition) {
            return false;
        }
        
        // Update status
        $stmt = $db->prepare("UPDATE booking SET status = ? WHERE booking_id = ?");
        $stmt->bind_param("si", $new_status, $booking_id);
        return $stmt->execute();
    }
    
    // Helper function to get booking status
    private function getBookingStatus($db, $booking_id) {
        $stmt = $db->prepare("SELECT status FROM booking WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['status'];
    }
    
    // Helper function to check if status transition is valid
    private function isValidStatusTransition($from_status, $to_status) {
        $valid_transitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['approved', 'cancelled'],
            'approved' => ['in_progress', 'cancelled'],
            'in_progress' => ['completed', 'cancelled'],
            'completed' => [],
            'cancelled' => []
        ];
        
        return in_array($to_status, $valid_transitions[$from_status] ?? []);
    }
    
    // Test booking cancellation and refund calculation
    public function testCancellationAndRefund() {
        $db = getTestDB();
        
        // Create test data
        $customer = createTestCustomer($db, '_cancel_test');
        $car = createTestCar($db, '_cancel_test');
        
        // Create booking
        $booking_id = $this->createTestBooking($db, $customer['cust_id'], $car['car_id'], 'confirmed');
        
        // Get booking details
        $stmt = $db->prepare("SELECT total_price, security_deposit FROM booking WHERE booking_id = ?");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $booking = $result->fetch_assoc();
        
        // Calculate different cancellation scenarios
        $early_cancellation = $this->calculateRefundAmount($booking['total_price'], $booking['security_deposit'], 7);
        $late_cancellation = $this->calculateRefundAmount($booking['total_price'], $booking['security_deposit'], 1);
        $same_day_cancellation = $this->calculateRefundAmount($booking['total_price'], $booking['security_deposit'], 0);
        
        // Clean up
        $db->query("DELETE FROM booking WHERE booking_id = $booking_id");
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
        
        // Expected values
        $expected_early = $booking['total_price'] + $booking['security_deposit']; // Full refund
        $expected_late = $booking['security_deposit'] + ($booking['total_price'] * 0.5); // 50% refund
        $expected_same_day = $booking['security_deposit']; // Only security deposit refund
        
        if ($early_cancellation != $expected_early) {
            return "Early cancellation refund calculation failed";
        }
        
        if ($late_cancellation != $expected_late) {
            return "Late cancellation refund calculation failed";
        }
        
        if ($same_day_cancellation != $expected_same_day) {
            return "Same day cancellation refund calculation failed";
        }
        
        return true;
    }
    
    // Helper function to calculate refund amount
    private function calculateRefundAmount($total_price, $security_deposit, $days_before_pickup) {
        // Always refund security deposit
        $refund = $security_deposit;
        
        // Refund policy based on days before pickup
        if ($days_before_pickup >= 3) {
            // Full refund if cancelled 3+ days before
            $refund += $total_price;
        } else if ($days_before_pickup >= 1) {
            // 50% refund if cancelled 1-2 days before
            $refund += $total_price * 0.5;
        }
        // No rental fee refund if cancelled on pickup day
        
        return $refund;
    }
}

// Run tests
$test = new BookingUnitTest();
echo "Booking Validation: " . ($test->testBookingValidation() === true ? "PASSED" : "FAILED") . "\n";
echo "Booking Status Updates: " . ($test->testBookingStatusUpdates() === true ? "PASSED" : "FAILED") . "\n";
echo "Cancellation & Refund: " . ($test->testCancellationAndRefund() === true ? "PASSED" : "FAILED") . "\n";
?>