<?php
include '../connect.php';
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit; }
date_default_timezone_set('Asia/Kuala_Lumpur');

// Year and month selection
$year_start = 2015;
$year_end = 2035;
$current_year = date('Y');
$current_month = date('n');

$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
if ($selected_year < $year_start || $selected_year > $year_end) $selected_year = $current_year;

$selected_month = isset($_GET['month']) ? intval($_GET['month']) : $current_month;
if ($selected_month < 1 || $selected_month > 12) $selected_month = $current_month;

// Months for dropdown
$months_dropdown = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$report = [];
$pie_labels = [];
$pie_data = [];
$pie_images = [];
$grand_total_bookings = 0;

// Popular cars query including an image (fetch first car_image for car)
$sql = "SELECT 
            c.car_id,
            c.car_brand,
            c.car_model,
            c.year,
            c.plate_no,
            c.color,
            COUNT(b.booking_id) AS total_bookings,
            SUM(b.day_count) AS total_days_rented,
            (SELECT ci.image_blob FROM car_image ci WHERE ci.car_id = c.car_id ORDER BY ci.sort_order ASC LIMIT 1) AS car_image
        FROM booking b
        LEFT JOIN car c ON b.car_id = c.car_id
        WHERE YEAR(b.pickup_datetime)=?
          AND MONTH(b.pickup_datetime)=?
          AND (b.status IN ('confirmed','completed','approved'))
        GROUP BY c.car_id
        ORDER BY total_bookings DESC, total_days_rented DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $selected_year, $selected_month);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $report[] = $row;
    $grand_total_bookings += $row['total_bookings'];
    $pie_labels[] = $row['car_brand'] . ' ' . $row['car_model'];
    $pie_data[] = $row['total_bookings'];
    // For images, convert blob to base64 if exists
    if ($row['car_image']) {
        $pie_images[] = 'data:image/jpeg;base64,'.base64_encode($row['car_image']);
    } else {
        $pie_images[] = null;
    }
}
$stmt->close();

// Excel export
if(isset($_GET['export']) && $_GET['export'] == "excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=popular_cars_report_{$selected_year}_{$selected_month}.xls");
    echo "<table border='1'>";
    echo "<tr><th colspan='8' style='font-size:18px;text-align:left'>Most Popular Cars Report</th></tr>";
    echo "<tr><th>Car Brand</th><th>Model</th><th>Year</th><th>Plate No</th><th>Color</th><th>Total Bookings</th><th>Total Days Rented</th><th>Image</th></tr>";
    foreach ($report as $row) {
        echo "<tr>";
        echo "<td>{$row['car_brand']}</td>";
        echo "<td>{$row['car_model']}</td>";
        echo "<td>{$row['year']}</td>";
        echo "<td>{$row['plate_no']}</td>";
        echo "<td>{$row['color']}</td>";
        echo "<td>{$row['total_bookings']}</td>";
        echo "<td>{$row['total_days_rented']}</td>";
        if ($row['car_image']) {
            $img = 'data:image/jpeg;base64,'.base64_encode($row['car_image']);
            echo "<td><img src='$img' style='height:44px;max-width:77px;object-fit:cover;border-radius:6px;'/></td>";
        } else {
            echo "<td>-</td>";
        }
        echo "</tr>";
    }
    echo "<tr style='font-weight:bold'><td colspan='5'>Total Bookings</td><td>{$grand_total_bookings}</td><td></td><td></td></tr>";
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
.report-search-bar label {
    font-size: 1em;
    color: #325d92;
    font-weight: 600;
    margin-right: 4px;
}
.report-search-bar select {
    padding: 9px 14px;
    border-radius: 7px;
    border: 1.5px solid #b5bee5;
    font-size: 1.05em;
    background: #f7fafd;
    min-width: 110px;
}
.report-search-bar button, .report-search-bar a {
    padding: 9px 19px;
    background: #2b5cbc;
    color: #fff;
    border: none;
    border-radius: 7px;
    font-weight: 600;
    font-size: 1.03em;
    cursor: pointer;
    transition: background 0.14s;
    text-decoration: none;
    display: inline-block;
}
.report-search-bar button:hover, .report-search-bar a:hover {
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
    padding: 13px 10px;
    border-bottom: 1px solid #eef2fa;
    text-align: center;
    vertical-align: middle;
}
.report-table th {
    background: #f8fafd;
    font-weight: 700;
    color: #2b5cbc;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e4eaf1;
}
.report-table img {
    height: 48px;
    max-width: 78px;
    object-fit: cover;
    border-radius: 8px;
    box-shadow: 0 1px 7px #d9e2f4a0;
}
.report-table tr:last-child td {
    border-bottom: none;
}
.report-table tfoot td {font-weight:700;}
@media (max-width: 900px) {
    .report-table th, .report-table td { font-size: 0.97em; padding: 8px 4px; }
    .report-search-bar select { width: 100px; }
    .report-table img { height: 36px; max-width: 52px; }
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
.report-pie-panel {
    background: #fff;
    padding: 20px 18px 16px 18px;
    border-radius: 12px;
    margin-bottom: 18px;
    box-shadow: 0 2px 12px #e0e7ef33;
    max-width: 100%;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 28px;
}
@media (max-width: 700px) {
    .report-pie-panel {padding: 8px;}
}
.pie-legend-list {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
}
.pie-legend-list li {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 1em;
    background: #f6f9ff;
    border-radius: 7px;
    padding: 6px 12px 6px 7px;
    margin-bottom: 8px;
    box-shadow: 0 1px 6px #eaeff5a0;
}
.pie-legend-list img {
    height: 32px;
    width: 43px;
    object-fit: cover;
    border-radius: 5px;
    margin-right: 5px;
    box-shadow: 0 1px 7px #d9e2f4a0;
}
.pie-legend-color {
    display:inline-block;
    width:14px;
    height:14px;
    border-radius:100px;
    margin-right:8px;
    border:2px solid #fff;
}
</style>

<div class="report-main-panel">
    <div class="report-breadcrumb">
        <a href="admin_dashboard.php">Dashboard</a>
        / <a href="report_daily_income.php">Daily</a>
        / <a href="report_monthly_income.php">Monthly</a>
        / <a href="report_yearly_income.php">Yearly</a>
        / Most Popular Cars Report
    </div>
    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Most Popular Cars Report</h2>
    <form class="report-search-bar" method="get" action="report_most_popular_cars.php" autocomplete="off">
        <label for="month">Month</label>
        <select name="month" id="month">
            <?php foreach ($months_dropdown as $mn => $mlabel): ?>
                <option value="<?= $mn ?>" <?= ($mn == $selected_month ? 'selected' : '') ?>><?= $mlabel ?></option>
            <?php endforeach; ?>
        </select>
        <label for="year">Year</label>
        <select name="year" id="year">
            <?php for ($y = $year_start; $y <= $year_end; $y++): ?>
                <option value="<?= $y ?>" <?= ($y == $selected_year ? 'selected' : '') ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit">Submit</button>
        <a class="excel-btn" href="?year=<?= $selected_year ?>&month=<?= $selected_month ?>&export=excel" role="button">
            <i class="fa fa-file-excel-o"></i> Excel
        </a>
        <a href="admin_dashboard.php" class="excel-btn" style="margin-left:8px;background:#eaeaea;color:#254d84;border:1px solid #dbe7fb;">Back to Dashboard</a>
    </form>
    <div class="report-pie-panel">
        <div style="flex:1;min-width:240px;">
            <canvas id="popularCarPieChart" height="170"></canvas>
        </div>
        <?php if (count($pie_labels) > 0): ?>
        <ul class="pie-legend-list">
            <?php foreach ($pie_labels as $idx => $car_label): ?>
                <li>
                    <?php if ($pie_images[$idx]): ?>
                        <img src="<?= $pie_images[$idx] ?>" alt="Car">
                    <?php endif; ?>
                    <span class="pie-legend-color" style="background:<?= $idx < 10 ? "hsl(".($idx*36).",78%,56%)" : "#c4cbe8" ?>"></span>
                    <span><?= htmlspecialchars($car_label) ?></span>
                    <span style="margin-left:7px;color:#2965b2;font-weight:700;"><?= $pie_data[$idx] ?> bookings</span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <div style="overflow-x:auto;">
        <?php if (count($report) == 0): ?>
            <div style="color:#b00;text-align:center;margin:12px 0;">No car bookings recorded for this month.</div>
        <?php endif; ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>CAR IMAGE</th>
                    <th>CAR BRAND</th>
                    <th>MODEL</th>
                    <th>YEAR</th>
                    <th>PLATE NO</th>
                    <th>COLOR</th>
                    <th>TOTAL BOOKINGS</th>
                    <th>TOTAL DAYS RENTED</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($report as $row): ?>
                <tr>
                    <td>
                        <?php if ($row['car_image']): ?>
                            <img src="data:image/jpeg;base64,<?= base64_encode($row['car_image']) ?>" alt="Car">
                        <?php else: ?>
                            <span style="color:#b00;">No image</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['car_brand']) ?></td>
                    <td><?= htmlspecialchars($row['car_model']) ?></td>
                    <td><?= htmlspecialchars($row['year']) ?></td>
                    <td><?= htmlspecialchars($row['plate_no']) ?></td>
                    <td><?= htmlspecialchars($row['color']) ?></td>
                    <td><?= $row['total_bookings'] ?></td>
                    <td><?= $row['total_days_rented'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6"><b>Total Bookings</b></td>
                    <td><b><?= $grand_total_bookings ?></b></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const pieLabels = <?= json_encode($pie_labels) ?>;
const pieData = <?= json_encode($pie_data) ?>;
const pieColors = pieLabels.map((lbl, idx) => idx < 10 ? `hsl(${idx*36},78%,56%)` : "#c4cbe8");

const ctxPie = document.getElementById('popularCarPieChart').getContext('2d');
new Chart(ctxPie, {
    type: 'pie',
    data: {
        labels: pieLabels,
        datasets: [{
            data: pieData,
            backgroundColor: pieColors,
            borderColor: '#fff',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            title: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const label = context.label || '';
                        const value = context.parsed || 0;
                        return label + ': ' + value + ' bookings';
                    }
                }
            }
        }
    }
});
</script>
<?php include '../includes/footer.php'; ?>