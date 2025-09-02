<?php
/**
 * TimeLess Car Rental System - Test Runner
 */

// Start time tracking
$start_time = microtime(true);

// Helper function to run a test file
function runTestFile($file) {
    if (!file_exists($file)) {
        return "Error: Test file not found: $file";
    }
    
    // Capture output from the test file
    ob_start();
    $result = include($file);
    $output = ob_get_clean();
    
    return [
        'result' => $result,
        'output' => $output
    ];
}

// Set up HTML if running in browser
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>TimeLess Car Rental - Test Runner</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #333; }
            .test-menu { margin-bottom: 20px; }
            .test-menu a { display: inline-block; margin: 5px; padding: 10px; background: #f5f5f5; text-decoration: none; color: #333; border-radius: 3px; }
            .test-output { background: #f5f5f5; padding: 15px; border-radius: 5px; white-space: pre-wrap; font-family: monospace; }
            .passed { color: green; }
            .failed { color: red; }
        </style>
    </head>
    <body>
    <h1>TimeLess Car Rental - Test Runner</h1>
    <div class='test-menu'>
        <a href='?test=customer'>Customer Tests</a>
        <a href='?test=car'>Car Tests</a>
        <a href='?test=booking'>Booking Tests</a>
        <a href='?test=integration'>Integration Tests</a>
        <a href='?test=system'>System Tests</a>
    </div>";
}

// Map test types to files
$test_files = [
    'customer' => 'tests/unit/customer_test.php',
    'car' => 'tests/unit/car_test.php',
    'booking' => 'tests/unit/booking_test.php',
    'integration' => 'tests/integration/booking_flow_test.php',
    'system' => 'tests/system/complete_rental_test.php'
];

// Determine which test to run
$test_type = $_GET['test'] ?? null;

// Run the requested test
if ($test_type && isset($test_files[$test_type])) {
    $file = $test_files[$test_type];
    $result = runTestFile($file);
    
    if (php_sapi_name() !== 'cli') {
        echo "<h2>Results for " . ucfirst($test_type) . " Tests</h2>";
        echo "<div class='test-output'>{$result['output']}</div>";
    } else {
        echo $result['output'];
    }
} else {
    // Show instructions
    if (php_sapi_name() !== 'cli') {
        echo "<h2>Select a test to run</h2>";
        echo "<p>Click one of the test links above to run that test set.</p>";
    } else {
        echo "Usage: php test_runner.php [test_type]\n";
        echo "Available test types: " . implode(", ", array_keys($test_files)) . "\n";
    }
}

// Close HTML if in browser
if (php_sapi_name() !== 'cli') {
    echo "</body></html>";
}
?>