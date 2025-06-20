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

// Number of days in the selected month/year
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);

$report = [];
$grand_total = 0.0;

// Initialize days
for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = sprintf("%02d/%02d/%d", $d, $selected_month, $selected_year);
    $report[$d] = [
        'date' => $date_str,
        'amount' => 0.00
    ];
}

// Query: payments per day
$sql = "SELECT DAY(p.payment_date) AS day, SUM(p.amount) AS total
        FROM payment p
        LEFT JOIN booking b ON p.booking_id = b.booking_id
        WHERE p.payment_status='paid'
          AND YEAR(p.payment_date)=?
          AND MONTH(p.payment_date)=?
          AND (b.status IS NULL OR (b.status NOT IN ('cancelled','rejected')))
        GROUP BY day";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $selected_year, $selected_month);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $d = intval($row['day']);
    $report[$d]['amount'] = $row['total'];
    $grand_total += $row['total'];
}
$stmt->close();

// Prepare data for chart.js
$chart_labels = [];
$chart_amounts = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $chart_labels[] = $d;
    $chart_amounts[] = floatval($report[$d]['amount']);
}

// Excel export
if(isset($_GET['export']) && $_GET['export'] == "excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=daily_income_report_{$selected_year}_{$selected_month}.xls");
    echo "<table border='1'>";
    echo "<tr><th colspan='2' style='font-size:18px;text-align:left'>Daily Income Report</th></tr>";
    echo "<tr><th>Date</th><th>Total Amount (RM)</th></tr>";
    foreach ($report as $d => $row) {
        echo "<tr>";
        echo "<td>{$row['date']}</td>";
        echo "<td>".number_format($row['amount'], 2)."</td>";
        echo "</tr>";
    }
    echo "<tr style='font-weight:bold'><td>Total</td><td>".number_format($grand_total, 2)."</td></tr>";
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
.report-table tfoot td {font-weight:700;}
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
        <a href="admin_dashboard.php">Dashboard</a> / Daily Income Report
    </div>
    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Daily Income Report</h2>
    <form class="report-search-bar" method="get" action="report_daily_income.php" autocomplete="off">
        <select name="month" id="month">
            <?php foreach ($months_dropdown as $mn => $mlabel): ?>
                <option value="<?= $mn ?>" <?= ($mn == $selected_month ? 'selected' : '') ?>><?= $mlabel ?></option>
            <?php endforeach; ?>
        </select>
        <select name="year" id="year">
            <?php for ($y = $year_start; $y <= $year_end; $y++): ?>
                <option value="<?= $y ?>" <?= ($y == $selected_year ? 'selected' : '') ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit">Submit</button>
        <button type="button" class="excel-btn" onclick="window.location='?year=<?= $selected_year ?>&month=<?= $selected_month ?>&export=excel'"><i class="fa fa-file-excel-o"></i> Excel</button>
    </form>
    <div class="report-chart-panel">
        <canvas id="incomeLineChart" height="120"></canvas>
    </div>
    <div style="overflow-x:auto;">
    <table class="report-table">
        <thead>
            <tr>
                <th>DATE</th>
                <th>TOTAL AMOUNT (RM)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($report as $row): ?>
            <tr>
                <td><?= $row['date'] ?></td>
                <td><?= 'MYR ' . number_format($row['amount'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td><b>Total</b></td>
                <td><b><?= 'MYR ' . number_format($grand_total, 2) ?></b></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('incomeLineChart').getContext('2d');
const incomeLineChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
            label: 'Total Amount (RM)',
            data: <?= json_encode($chart_amounts) ?>,
            fill: false,
            borderColor: 'rgba(33, 150, 243, 0.82)',
            backgroundColor: 'rgba(33, 150, 243, 0.27)',
            tension: 0.2,
            pointBackgroundColor: '#2b5cbc',
            pointRadius: 3
        }]
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
                ticks: { stepSize: 100 }
            },
            x: {
                title: { display: true, text: 'Days' }
            }
        }
    }
});
</script>
<?php include '../includes/footer.php'; ?>