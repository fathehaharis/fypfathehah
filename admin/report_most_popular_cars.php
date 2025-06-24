<?php
include '../connect.php';
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit; }
date_default_timezone_set('Asia/Kuala_Lumpur');

// Year selection: from 2015 to 2035
$year_start = 2015;
$year_end = 2035;
$current_year = date('Y');
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
if ($selected_year < $year_start || $selected_year > $year_end) $selected_year = $current_year;

// Get top 10 most booked cars for the selected year
$sql = "SELECT 
            c.car_id, c.car_brand, c.car_model, c.year, c.color,
            COUNT(b.booking_id) AS num_booked,
            SUM(b.total_price) AS total_income
        FROM car c
        LEFT JOIN booking b ON c.car_id = b.car_id 
            AND YEAR(b.pickup_datetime) = ? 
            AND b.status IN ('confirmed','completed')
        GROUP BY c.car_id, c.car_brand, c.car_model, c.year, c.color
        ORDER BY num_booked DESC, total_income DESC
        LIMIT 10";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $selected_year);
$stmt->execute();
$result = $stmt->get_result();

$report = [];
$chart_labels = [];
$chart_data = [];
$chart_income = [];
while ($row = $result->fetch_assoc()) {
    $report[] = $row;
    $label = $row['car_brand'] . ' ' . $row['car_model'];
    $chart_labels[] = $label;
    $chart_data[] = intval($row['num_booked']);
    $chart_income[] = floatval($row['total_income']);
}
$stmt->close();

// Excel export
if(isset($_GET['export']) && $_GET['export'] == "excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=most_popular_cars_{$selected_year}.xls");
    echo "<table border='1'>";
    echo "<tr><th colspan='6' style='font-size:18px;text-align:left'>Most Popular Cars Report ({$selected_year})</th></tr>";
    echo "<tr><th>Car</th><th>Year</th><th>Color</th><th>No. of Bookings</th><th>Total Income (RM)</th></tr>";
    foreach ($report as $row) {
        echo "<tr>";
        echo "<td>{$row['car_brand']} {$row['car_model']}</td>";
        echo "<td>{$row['year']}</td>";
        echo "<td>{$row['color']}</td>";
        echo "<td>{$row['num_booked']}</td>";
        echo "<td>".number_format($row['total_income'], 2)."</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

include 'admin_header.php';
?>
<style>
.report-search-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 18px;
    align-items: center;
}
.report-search-bar select {
    padding: 9px 14px;
    border-radius: 7px;
    border: 1.5px solid #b5bee5;
    font-size: 1.05em;
    background: #f7fafd;
    min-width: 110px;
}
.report-search-bar button {
    padding: 9px 19px;
    background: #2b5cbc;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-weight: 600;
    font-size: 1.03em;
    cursor: pointer;
    transition: background 0.14s;
}
.report-search-bar button:hover {
    background: #243570;
}
.report-search-bar .excel-btn {
    background: #f5f7fa;
    color: #205cf3;
    border: 1.5px solid #dbe7fb;
    margin-left: 0;
    font-weight: 600;
}
.report-search-bar .excel-btn:hover { background: #e6f1ff; color: #1743a7;}
.report-table {
    width: 100%;
    border-collapse: collapse;
    margin: 18px 0 40px 0;
    background: #fff;
    box-shadow: 0 2px 12px #e0e7ef55;
    border-radius: 12px;
    overflow: hidden;
}
.report-table th, .report-table td {
    padding: 13px 14px;
    border-bottom: 1px solid #eef2fa;
    text-align: center;
}
.report-table th {
    background: #f8fafd;
    font-weight: 700;
    color: #2b5cbc;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e4eaf1;
}
.report-table tr:last-child td {
    border-bottom: none;
}
@media (max-width: 900px) {
    .report-table th, .report-table td { font-size: 0.97em; padding: 8px 6px; }
    .report-search-bar select { width: 100px; }
}
.report-breadcrumb {
    font-size: 1em;
    color: #92a2b3;
    margin-bottom: 10px;
}
.report-breadcrumb a {
    color: #6d87be;
    text-decoration: none;
    font-weight: 600;
}
.report-main-panel {
    max-width: 1120px;
    margin: 38px auto 25px auto;
}
.report-chart-panel {
    background: #fff;
    padding: 20px 18px 16px 18px;
    border-radius: 12px;
    margin-bottom: 18px;
    box-shadow: 0 2px 12px #e0e7ef33;
    max-width: 100%;
}
@media (max-width: 700px) {
    .report-chart-panel {padding: 8px;}
}
</style>

<div class="report-main-panel">
    <div class="report-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a> / Most Popular Cars
    </div>
    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Most Popular Cars Report</h2>
    <form class="report-search-bar" method="get" action="report_most_popular_cars.php" autocomplete="off">
        <select name="year" id="year">
            <?php for ($y = $year_start; $y <= $year_end; $y++): ?>
                <option value="<?= $y ?>" <?= ($y == $selected_year ? 'selected' : '') ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit">Submit</button>
        <button type="button" class="excel-btn" onclick="window.location='?year=<?= $selected_year ?>&export=excel'"><i class="fa fa-file-excel-o"></i> Excel</button>
    </form>
    <div class="report-chart-panel">
        <canvas id="popularCarsBarChart" height="120"></canvas>
    </div>
    <div style="overflow-x:auto;">
    <table class="report-table">
        <thead>
            <tr>
                <th>Car</th>
                <th>Year</th>
                <th>Color</th>
                <th>No. of Bookings</th>
                <th>Total Income (RM)</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($report) == 0): ?>
            <tr><td colspan="5">No booking data for this year.</td></tr>
        <?php else: foreach ($report as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['car_brand'] . ' ' . $row['car_model']) ?></td>
                <td><?= htmlspecialchars($row['year']) ?></td>
                <td><?= htmlspecialchars($row['color']) ?></td>
                <td><?= $row['num_booked'] ?></td>
                <td><?= number_format($row['total_income'], 2) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('popularCarsBarChart').getContext('2d');
const popularCarsBarChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [
            {
                label: 'No. of Bookings',
                data: <?= json_encode($chart_data) ?>,
                backgroundColor: 'rgba(33, 150, 243, 0.82)',
                borderColor: 'rgba(33, 150, 243, 1)',
                borderWidth: 1,
                maxBarThickness: 40
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            title: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                precision: 0,
                ticks: { stepSize: 1 }
            }
        }
    }
});
</script>
<?php include '../includes/footer.php'; ?>