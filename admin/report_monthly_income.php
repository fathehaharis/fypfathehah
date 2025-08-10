<?php
include '../connect.php';
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit; }
date_default_timezone_set('Asia/Kuala_Lumpur');

// Year selection
$year_start = 2015;
$year_end = 2035;
$current_year = date('Y');

$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;
if ($selected_year < $year_start || $selected_year > $year_end) $selected_year = $current_year;

// Months for dropdown
$months = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

$report = [];
$grand_total = 0.0;
$grand_sales = 0;
$grand_refunds = 0.0;
$grand_net_income = 0.0;

// Initialize months
foreach ($months as $mnum => $mname) {
    $report[$mnum] = [
        'month' => $mname,
        'num_sales' => 0,
        'amount' => 0.00,
        'refunds' => 0.00,
        'net_income' => 0.00
    ];
}

// Query: payments per month
$sql = "SELECT MONTH(p.payment_date) AS month,
               COUNT(*) AS num_sales,
               SUM(p.amount) AS total_income
        FROM payment p
        LEFT JOIN booking b ON p.booking_id = b.booking_id
        WHERE p.payment_status='paid'
          AND YEAR(p.payment_date)=?
          AND (b.status IS NULL OR b.status NOT IN ('cancelled','rejected'))
        GROUP BY month";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $selected_year);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $m = intval($row['month']);
    $report[$m]['num_sales'] = $row['num_sales'];
    $report[$m]['amount'] = $row['total_income'];
    $grand_total += $row['total_income'];
    $grand_sales += $row['num_sales'];
}
$stmt->close();

// Refunds processed per month (refund_status = 'processed')
$sql = "SELECT MONTH(created_at) AS month, SUM(amount) AS total_refund
        FROM refunds
        WHERE YEAR(created_at)=?
          AND refund_status = 'processed'
        GROUP BY month";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $selected_year);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $m = intval($row['month']);
    $report[$m]['refunds'] = $row['total_refund'];
    $grand_refunds += $row['total_refund'];
}
$stmt->close();

// Calculate NET INCOME per month
foreach ($months as $mnum => $mname) {
    $report[$mnum]['net_income'] = $report[$mnum]['amount'] - $report[$mnum]['refunds'];
    $grand_net_income += $report[$mnum]['net_income'];
}

// Prepare data for chart.js (use NET INCOME)
$chart_labels = [];
$chart_net_income = [];
foreach ($months as $mnum => $mname) {
    $chart_labels[] = $mname;
    $chart_net_income[] = floatval($report[$mnum]['net_income']);
}

// Excel export
if(isset($_GET['export']) && $_GET['export'] == "excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=monthly_income_report_{$selected_year}.xls");
    echo "<table border='1'>";
    echo "<tr><th colspan='5' style='font-size:18px;text-align:left'>Monthly Income Report</th></tr>";
    echo "<tr><th>Month</th><th>No. of Sales</th><th>Total Income (RM)</th><th>Refunds (RM)</th><th>Net Income (RM)</th></tr>";
    foreach ($months as $mnum => $mname) {
        echo "<tr>";
        echo "<td>{$mname}</td>";
        echo "<td>{$report[$mnum]['num_sales']}</td>";
        echo "<td>".number_format($report[$mnum]['amount'], 2)."</td>";
        echo "<td>".number_format($report[$mnum]['refunds'], 2)."</td>";
        echo "<td>".number_format($report[$mnum]['net_income'], 2)."</td>";
        echo "</tr>";
    }
    echo "<tr style='font-weight:bold'><td>Total</td><td>{$grand_sales}</td><td>".number_format($grand_total, 2)."</td><td>".number_format($grand_refunds, 2)."</td><td>".number_format($grand_net_income, 2)."</td></tr>";
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
        <a href="admin_dashboard.php">Dashboard</a>
        / <a href="report_daily_income.php">Daily</a>
        / <a href="report_yearly_income.php">Yearly</a>
        / <a href="report_popular_car.php">Popular Car</a>
        / Monthly Income Report
    </div>
    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Monthly Income Report</h2>
    <form class="report-search-bar" method="get" action="report_monthly_income.php" autocomplete="off">
        <label for="year">Year</label>
        <select name="year" id="year">
            <?php for ($y = $year_start; $y <= $year_end; $y++): ?>
                <option value="<?= $y ?>" <?= ($y == $selected_year ? 'selected' : '') ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit">Submit</button>
        <a class="excel-btn" href="?year=<?= $selected_year ?>&export=excel" role="button">
            <i class="fa fa-file-excel-o"></i> Excel
        </a>
        <a href="admin_dashboard.php" class="excel-btn" style="margin-left:8px;background:#eaeaea;color:#254d84;border:1px solid #dbe7fb;">Back to Dashboard</a>
    </form>
    <div class="report-chart-panel">
        <canvas id="incomeBarChart" height="120"></canvas>
    </div>
    <div style="overflow-x:auto;">
        <?php if ($grand_total == 0): ?>
            <div style="color:#b00;text-align:center;margin:12px 0;">No income recorded for this year.</div>
        <?php endif; ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>MONTH</th>
                    <th>NO. OF SALES</th>
                    <th>TOTAL INCOME (RM)</th>
                    <th>REFUNDS (RM)</th>
                    <th>NET INCOME (RM)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($months as $mnum => $mname): ?>
                <tr>
                    <td><?= $mname ?></td>
                    <td><?= $report[$mnum]['num_sales'] ?></td>
                    <td><?= number_format($report[$mnum]['amount'], 2) ?></td>
                    <td><?= number_format($report[$mnum]['refunds'], 2) ?></td>
                    <td><?= number_format($report[$mnum]['net_income'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td><b>Total</b></td>
                    <td><b><?= $grand_sales ?></b></td>
                    <td><b><?= number_format($grand_total, 2) ?></b></td>
                    <td><b><?= number_format($grand_refunds, 2) ?></b></td>
                    <td><b><?= number_format($grand_net_income, 2) ?></b></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('incomeBarChart').getContext('2d');
const incomeBarChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [
            {
                label: 'Net Income (RM)',
                data: <?= json_encode($chart_net_income) ?>,
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
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'RM ' + context.parsed.y.toFixed(2);
                    }
                }
            },
            title: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1000 }
            }
        }
    }
});
</script>
<?php include '../includes/footer.php'; ?>