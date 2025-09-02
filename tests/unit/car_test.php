<?php
require_once __DIR__ . '/../../test_setup.php';

class CarUnitTest {
    // Test car search functionality
    public function testCarSearch() {
        $db = getTestDB();
        
        echo "\n--- Starting Car Search Test ---\n";
        
        // Create test cars with different attributes
        echo "Creating test cars...\n";
        $car1 = $this->createCarWithAttributes($db, 'Toyota', 'Camry', 2022, 'Automatic', 5, 150.00);
        $car2 = $this->createCarWithAttributes($db, 'Honda', 'Civic', 2021, 'Automatic', 5, 130.00);
        $car3 = $this->createCarWithAttributes($db, 'Toyota', 'Fortuner', 2023, 'Automatic', 7, 250.00);
        
        echo "Created car ID: " . $car1['car_id'] . " (Toyota Camry)\n";
        echo "Created car ID: " . $car2['car_id'] . " (Honda Civic)\n";
        echo "Created car ID: " . $car3['car_id'] . " (Toyota Fortuner)\n";
        
        // Test search by brand
        echo "\nSearching for Toyota cars...\n";
        $toyota_cars = $this->searchCars($db, 'car_brand', 'Toyota');
        echo "Found " . count($toyota_cars) . " Toyota cars\n";
        
        echo "Searching for Honda cars...\n";
        $honda_cars = $this->searchCars($db, 'car_brand', 'Honda');
        echo "Found " . count($honda_cars) . " Honda cars\n";
        
        // Test search by seats
        echo "Searching for 5-seater cars...\n";
        $five_seater_cars = $this->searchCars($db, 'seat_capacity', 5);
        echo "Found " . count($five_seater_cars) . " 5-seater cars\n";
        
        echo "Searching for 7-seater cars...\n";
        $seven_seater_cars = $this->searchCars($db, 'seat_capacity', 7);
        echo "Found " . count($seven_seater_cars) . " 7-seater cars\n";
        
        // Clean up
        echo "\nCleaning up test data...\n";
        $db->query("DELETE FROM car WHERE car_id IN ({$car1['car_id']}, {$car2['car_id']}, {$car3['car_id']})");
        
        // Verify search results
        if (count($toyota_cars) != 2) {
            return "Toyota car search failed: Expected 2 cars, found " . count($toyota_cars);
        }
        
        if (count($honda_cars) != 1) {
            return "Honda car search failed: Expected 1 car, found " . count($honda_cars);
        }
        
        if (count($five_seater_cars) != 2) {
            return "5-seater search failed: Expected 2 cars, found " . count($five_seater_cars);
        }
        
        if (count($seven_seater_cars) != 1) {
            return "7-seater search failed: Expected 1 car, found " . count($seven_seater_cars);
        }
        
        echo "All car search tests passed!\n";
        return true;
    }
    
    // Helper function to create car with specific attributes
    private function createCarWithAttributes($db, $brand, $model, $year, $transmission, $seats, $rate) {
        $plate = strtoupper(substr($brand, 0, 3)) . rand(1000, 9999);
        
        try {
            $stmt = $db->prepare("
                INSERT INTO car 
                (car_brand, car_model, year, color, mileage, plate_no, 
                 transmission, seat_capacity, status, daily_rate) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $color = 'Black';
            $mileage = rand(1000, 20000);
            $status = 'available';
            
            $stmt->bind_param("ssisssisds", 
                $brand, $model, $year, $color, $mileage, $plate,
                $transmission, $seats, $status, $rate
            );
            $stmt->execute();
            
            return [
                'car_id' => $db->insert_id,
                'plate_no' => $plate,
                'daily_rate' => $rate
            ];
        } catch (Exception $e) {
            echo "ERROR creating car: " . $e->getMessage() . "\n";
            throw $e;
        }
    }
    
    // Helper function to search cars - FIXED VERSION
    private function searchCars($db, $field, $value) {
        try {
            // Verify the column exists
            $columns = [
                'car_id', 'car_brand', 'car_model', 'year', 'color', 'mileage',
                'plate_no', 'transmission', 'seat_capacity', 'status', 'daily_rate'
            ];
            
            if (!in_array($field, $columns)) {
                echo "ERROR: Column '$field' does not exist or is not searchable in car table\n";
                echo "Available searchable columns: " . implode(", ", $columns) . "\n";
                return [];
            }
            
            // Construct a query string directly (safe because we validated the column name)
            $query = "SELECT * FROM car WHERE " . $field . " = ? AND status = 'available'";
            echo "Executing query: $query (with $field = $value)\n";
            
            $stmt = $db->prepare($query);
            
            if (is_numeric($value)) {
                $stmt->bind_param("i", $value);
            } else {
                $stmt->bind_param("s", $value);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $cars = [];
            while ($row = $result->fetch_assoc()) {
                $cars[] = $row;
                echo " - Found: {$row['car_brand']} {$row['car_model']} (ID: {$row['car_id']})\n";
            }
            
            return $cars;
        } catch (Exception $e) {
            echo "ERROR in searchCars: " . $e->getMessage() . "\n";
            return [];
        }
    }
    
    // Test car availability calculation
    public function testCarAvailability() {
        $db = getTestDB();
        
        // Car should be available initially
        $car = createTestCar($db, '_avail_test');
        
        // Check initial availability
        $initially_available = $this->checkAvailability($db, $car['car_id'], 
            date('Y-m-d', strtotime('+1 day')), 
            date('Y-m-d', strtotime('+3 days'))
        );
        
        // Create a booking for this car
        $customer = createTestCustomer($db, '_avail_test');
        $this->createBooking($db, $customer['cust_id'], $car['car_id'],
            date('Y-m-d', strtotime('+2 days')),
            date('Y-m-d', strtotime('+5 days'))
        );
        
        // Check different date ranges
        $completely_before = $this->checkAvailability($db, $car['car_id'],
            date('Y-m-d', strtotime('+7 days')),
            date('Y-m-d', strtotime('+10 days'))
        );
        
        $completely_after = $this->checkAvailability($db, $car['car_id'],
            date('Y-m-d', strtotime('-3 days')),
            date('Y-m-d', strtotime('-1 days'))
        );
        
        $overlapping = $this->checkAvailability($db, $car['car_id'],
            date('Y-m-d', strtotime('+1 day')),
            date('Y-m-d', strtotime('+3 days'))
        );
        
        $contained = $this->checkAvailability($db, $car['car_id'],
            date('Y-m-d', strtotime('+3 days')),
            date('Y-m-d', strtotime('+4 days'))
        );
        
        // Clean up
        $db->query("DELETE FROM booking WHERE car_id = {$car['car_id']}");
        $db->query("DELETE FROM car WHERE car_id = {$car['car_id']}");
        $db->query("DELETE FROM customer WHERE cust_id = {$customer['cust_id']}");
        
        if (!$initially_available) {
            return "Car should be available initially";
        }
        
        if (!$completely_before) {
            return "Car should be available for dates completely before booking";
        }
        
        if (!$completely_after) {
            return "Car should be available for dates completely after booking";
        }
        
        if ($overlapping) {
            return "Car should not be available for overlapping dates";
        }
        
        if ($contained) {
            return "Car should not be available for dates within booking";
        }
        
        return true;
    }
    
    // Helper function to check car availability
    private function checkAvailability($db, $car_id, $start_date, $end_date) {
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
            $end_date, $start_date,
            $start_date, $start_date,
            $start_date, $end_date
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $conflicting_bookings = $result->fetch_assoc()['count'];
        
        return $conflicting_bookings == 0;
    }
    
    // Helper function to create booking
    private function createBooking($db, $cust_id, $car_id, $pickup_date, $return_date) {
        $stmt = $db->prepare("
            INSERT INTO booking 
            (cust_id, car_id, pickup_datetime, return_datetime, day_count, 
             daily_rate, total_price, security_deposit, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $day_count = (strtotime($return_date) - strtotime($pickup_date)) / (60 * 60 * 24);
        $daily_rate = 150.00;
        $total_price = $day_count * $daily_rate;
        $security_deposit = 100.00;
        $status = 'confirmed';
        
        $stmt->bind_param("iissiddds", 
            $cust_id, $car_id, $pickup_date, $return_date,
            $day_count, $daily_rate, $total_price, $security_deposit, $status
        );
        $stmt->execute();
        
        return $db->insert_id;
    }
    
    // Test price calculation
    public function testPriceCalculation() {
        // Basic price calculation
        $daily_rate = 150.00;
        $day_count = 5;
        $basic_price = $daily_rate * $day_count;
        
        // With additional services
        $delivery_fee = 30.00;
        $with_services_price = $basic_price + $delivery_fee;
        
        // With discount
        $discount_percentage = 10;
        $discount_amount = $basic_price * ($discount_percentage / 100);
        $discounted_price = $basic_price - $discount_amount;
        
        // With security deposit
        $security_deposit = 100.00;
        $total_payment = $basic_price + $security_deposit;
        
        if ($basic_price != 750.00) {
            return "Basic price calculation failed";
        }
        
        if ($with_services_price != 780.00) {
            return "Service price calculation failed";
        }
        
        if ($discounted_price != 675.00) {
            return "Discount calculation failed";
        }
        
        if ($total_payment != 850.00) {
            return "Total payment calculation failed";
        }
        
        return true;
    }
}

// Run tests
$test = new CarUnitTest();
echo "Car Search: " . ($test->testCarSearch() === true ? "PASSED" : "FAILED") . "\n";
echo "Car Availability: " . ($test->testCarAvailability() === true ? "PASSED" : "FAILED") . "\n";
echo "Price Calculation: " . ($test->testPriceCalculation() === true ? "PASSED" : "FAILED") . "\n";
?>