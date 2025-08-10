<?php
include '../connect.php';
session_start();
if (!isset($_SESSION['admin_id'])) { header("Location: admin_login.php"); exit; }
date_default_timezone_set('Asia/Kuala_Lumpur');

// Year range config
$year_start = 2015;
$year_end = 2035;

// Filter years
$filter_start = isset($_GET['start_year']) ? intval($_GET['start_year']) : $year_start;
$filter_end = isset($_GET['end_year']) ? intval($_GET['end_year']) : $year_end;
if ($filter_start < $year_start || $filter_start > $year_end) $filter_start = $year_start;
if ($filter_end < $year_start || $filter_end > $year_end || $filter_end < $filter_start) $filter_end = $year_end;

$years = [];
for ($y = $filter_start; $y <= $filter_end; $y++) {
    $years[] = $y;
}

// Report data
$report = [];
$grand_total = 0.0;
$grand_sales = 0;
$grand_refunds = 0.0;
$grand_net_income = 0.0;

// Initialize report array
foreach ($years as $year) {
    $report[$year] = [
        'year' => $year,
        'num_sales' => 0,
        'amount' => 0.00,
        'refunds' => 0.00,
        'net_income' => 0.00
    ];
}

// Query: payments per year (with filter)
$sql = "SELECT YEAR(p.payment_date) AS year,
               COUNT(*) AS num_sales,
               SUM(p.amount) AS total_income
        FROM payment p
        LEFT JOIN booking b ON p.booking_id = b.booking_id
        WHERE p.payment_status='paid'
          AND (b.status IS NULL OR b.status NOT IN ('cancelled','rejected'))
          AND YEAR(p.payment_date) >= ? AND YEAR(p.payment_date) <= ?
        GROUP BY year";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $filter_start, $filter_end);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $year = intval($row['year']);
    if (isset($report[$year])) {
        $report[$year]['num_sales'] = $row['num_sales'];
        $report[$year]['amount'] = $row['total_income'];
        $grand_total += $row['total_income'];
        $grand_sales += $row['num_sales'];
    }
}
$stmt->close();

// Refunds processed per year (refund_status = 'processed', with filter)
$sql = "SELECT YEAR(created_at) AS year, SUM(amount) AS total_refund
        FROM refunds
        WHERE refund_status = 'processed'
          AND YEAR(created_at) >= ? AND YEAR(created_at) <= ?
        GROUP BY year";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $filter_start, $filter_end);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $year = intval($row['year']);
    if (isset($report[$year])) {
        $report[$year]['refunds'] = $row['total_refund'];
        $grand_refunds += $row['total_refund'];
    }
}
$stmt->close();

// Calculate NET INCOME per year
foreach ($years as $year) {
    $report[$year]['net_income'] = $report[$year]['amount'] - $report[$year]['refunds'];
    $grand_net_income += $report[$year]['net_income'];
}

// Prepare data for chart.js (NET INCOME)
$chart_labels = [];
$chart_net_income = [];
foreach ($years as $year) {
    $chart_labels[] = $year;
    $chart_net_income[] = floatval($report[$year]['net_income']);
}

// Excel export
if(isset($_GET['export']) && $_GET['export'] == "excel"){
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=yearly_income_report_{$filter_start}_{$filter_end}.xls");
    echo "<table border='1'>";
    echo "<tr><th colspan='5' style='font-size:18px;text-align:left'>Yearly Income Report</th></tr>";
    echo "<tr><th>Year</th><th>No. of Sales</th><th>Total Income (RM)</th><th>Refunds (RM)</th><th>Net Income (RM)</th></tr>";
    foreach ($years as $year) {
        echo "<tr>";
        echo "<td>{$year}</td>";
        echo "<td>{$report[$year]['num_sales']}</td>";
        echo "<td>".number_format($report[$year]['amount'], 2)."</td>";
        echo "<td>".number_format($report[$year]['refunds'], 2)."</td>";
        echo "<td>".number_format($report[$year]['net_income'], 2)."</td>";
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
.report-search-bar select, .report-search-bar button, .report-search-bar a {
    padding: 9px 14px;
    border-radius: 7px;
    border: 1.5px solid #b5bee5;
    font-size: 1.05em;
    background: #f7fafd;
    min-width: 110px;
}
.report-search-bar button, .report-search-bar a {
    background: #2b5cbc;
    color: #fff;
    border: none;
    font-weight: 600;
    font-size: 1.03em;
    cursor: pointer;
    transition: background 0.14s;
    text-decoration: none;
    display: inline-block;
    min-width: 0;
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
        / <a href="report_monthly_income.php">Monthly</a>
        / <a href="report_popular_car.php">Popular Car</a>
        / Yearly Income Report
    </div>
    <h2 style="color:#2b5cbc;font-weight:800;letter-spacing:1px;">Yearly Income Report</h2>
    <form class="report-search-bar" method="get" action="report_yearly_income.php" autocomplete="off">
        <label for="start_year">From</label>
        <select name="start_year" id="start_year">
            <?php for ($y = $year_start; $y <= $year_end; $y++): ?>
                <option value="<?= $y ?>" <?= ($y == $filter_start ? 'selected' : '') ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <label for="end_year">To</label>
        <select name="end_year" id="end_year">
            <?php for ($y = $year_start; $y <= $year_end; $y++): ?>
                <option value="<?= $y ?>" <?= ($y == $filter_end ? 'selected' : '') ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit">Filter</button>
        <a class="excel-btn" href="?start_year=<?= $filter_start ?>&end_year=<?= $filter_end ?>&export=excel" role="button">
            <i class="fa fa-file-excel-o"></i> Excel
        </a>
        <a href="admin_dashboard.php" class="excel-btn" style="margin-left:8px;background:#eaeaea;color:#254d84;border:1px solid #dbe7fb;">Back to Dashboard</a>
    </form>
    <div class="report-chart-panel">
        <canvas id="incomeBarChart" height="120"></canvas>
    </div>
    <div style="overflow-x:auto;">
        <?php if ($grand_total == 0): ?>
            <div style="color:#b00;text-align:center;margin:12px 0;">No income recorded for these years.</div>
        <?php endif; ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>YEAR</th>
                    <th>NO. OF SALES</th>
                    <th>TOTAL INCOME (RM)</th>
                    <th>REFUNDS (RM)</th>
                    <th>NET INCOME (RM)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($years as $year): ?>
                <tr>
                    <td><?= $year ?></td>
                    <td><?= $report[$year]['num_sales'] ?></td>
                    <td><?= number_format($report[$year]['amount'], 2) ?></td>
                    <td><?= number_format($report[$year]['refunds'], 2) ?></td>
                    <td><?= number_format($report[$year]['net_income'], 2) ?></td>
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
                ticks: { stepSize: 5000 }
            }
        }
    }
});
</script>
<?php include '../includes/footer.php'; ?>