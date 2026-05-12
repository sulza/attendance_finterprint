<?php
require_once 'config.php';
requireRole(['director']);
$pageTitle = 'Institutional Analytics';

$db = getDB();

// 1. HARDENED INPUTS & FILTERS
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$classId  = (int)($_GET['class_id'] ?? 0);

// Use a shared params array for Prepared Statements
$dateParams = [$dateFrom, $dateTo];
$fullParams = [$dateFrom, $dateTo];
$classFilterSql = "";
if ($classId > 0) {
    $classFilterSql = " AND s.class_id = ?";
    $fullParams[] = $classId;
}

/**
 * 2. SUMMARY DATA (Consolidated)
 */
// Active Student count (respecting class filter)
$countStmt = $db->prepare("SELECT COUNT(*) FROM students s WHERE status='active' $classFilterSql");
$countStmt->execute($classId > 0 ? [$classId] : []);
$totalStudentsCount = $countStmt->fetchColumn();

// Attendance aggregate
$summaryStmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT a.attendance_date) as school_days,
        SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) as pres,
        SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) as abs
    FROM attendance a
    JOIN students s ON s.id = a.student_id
    WHERE a.attendance_date BETWEEN ? AND ? $classFilterSql
");
$summaryStmt->execute($fullParams);
$summ = $summaryStmt->fetch();

$totalPresent = $summ['pres'] ?? 0;
$totalAbsent  = $summ['abs'] ?? 0;
$totalDays    = $summ['school_days'] ?? 0;

/**
 * 3. RANKINGS (Top & Bottom Performers)
 */
$rankSql = "
    SELECT s.id, s.full_name, s.admission_number, c.class_name, s.photo,
           COUNT(a.id) as days_logged,
           SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) as present_days
    FROM students s
    LEFT JOIN classes c ON c.id=s.class_id
    LEFT JOIN attendance a ON a.student_id=s.id AND a.attendance_date BETWEEN ? AND ?
    WHERE s.status='active' $classFilterSql
    GROUP BY s.id, s.full_name, s.admission_number, c.class_name, s.photo
";

// Top 10 - Fixed math logic in ORDER BY
$topStmt = $db->prepare($rankSql . " 
    ORDER BY (SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) DESC, 
    present_days DESC 
    LIMIT 10"
);
$topStmt->execute($fullParams);
$topStudents = $topStmt->fetchAll();

// Bottom 10 - Fixed math logic in ORDER BY
$botStmt = $db->prepare($rankSql . " 
    ORDER BY (SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) / NULLIF(COUNT(a.id), 0)) ASC, 
    present_days ASC 
    LIMIT 10"
);
$botStmt->execute($fullParams);
$bottomStudents = $botStmt->fetchAll();

/**
 * 4. CLASS SUMMARY
 */
$classSummaryStmt = $db->prepare("
    SELECT c.class_name, 
           COUNT(DISTINCT s.id) as student_pop,
           SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) as pres,
           SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) as abs
    FROM classes c
    JOIN students s ON s.class_id=c.id AND s.status='active'
    LEFT JOIN attendance a ON a.student_id=s.id AND a.attendance_date BETWEEN ? AND ?
    GROUP BY c.id ORDER BY (SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END)/NULLIF(COUNT(a.id),0)) DESC
");
$classSummaryStmt->execute($dateParams);
$classSummary = $classSummaryStmt->fetchAll();

// Method Chart and Daily Trend stay largely similar but using Prepared Statements
$trendStmt = $db->prepare("SELECT attendance_date, SUM(status='present') as p, SUM(status='absent') as a 
    FROM attendance a WHERE attendance_date BETWEEN ? AND ? GROUP BY attendance_date ORDER BY attendance_date ASC");
$trendStmt->execute($dateParams);
$dailyTrend = $trendStmt->fetchAll();

/**
 * CSV EXPORT SECURITY
 */
if (isset($_GET['export'])) {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="EMS_REPORT_'.$dateFrom.'_TO_'.$dateTo.'.csv"');
    $fp = fopen('php://output','w');
    fputcsv($fp, ['Identity Name','Admission ID','Classroom','Session Logs','Attendance Rate (%)']);
    foreach($topStudents as $r) {
        $rate = $r['days_logged'] > 0 ? round(($r['present_days']/$r['days_logged'])*100, 1) : 0;
        fputcsv($fp, [$r['full_name'], $r['admission_number'], $r['class_name'] ?? '—', $r['days_logged'], $rate.'%']);
    }
    fclose($fp); exit;
}

$classes = $db->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();
include 'layout_header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-center mb-4">
    <div>
        <h1 class="fw-black Syne text-navy mb-0">System Audit & Reports</h1>
        <p class="text-muted small">Generated on <?= date('d M Y, H:i') ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="reports.php?<?= http_build_query(array_merge($_GET,['export'=>1])) ?>" class="btn btn-navy btn-sm rounded-pill px-4">
            <i class="bi bi-file-earmark-spreadsheet me-2 text-accent"></i>Export Master Ledger
        </a>
    </div>
</div>

<!-- FILTER ENGINE -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body bg-light p-3 px-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="fs-tiny fw-bold text-navy opacity-50 ms-2">TIMEFRAME FROM</label>
                <input type="date" name="date_from" class="form-control rounded-3" value="<?= $dateFrom ?>">
            </div>
            <div class="col-md-3">
                <label class="fs-tiny fw-bold text-navy opacity-50 ms-2">TIMEFRAME UNTIL</label>
                <input type="date" name="date_to" class="form-control rounded-3" value="<?= $dateTo ?>">
            </div>
            <div class="col-md-3">
                <label class="fs-tiny fw-bold text-navy opacity-50 ms-2">FOCUSED CLASSROOM</label>
                <select name="class_id" class="form-select rounded-3">
                    <option value="0">Universal School-Wide</option>
                    <?php foreach($classes as $c): ?>
                    <option value="<?=$c['id']?>" <?=$classId==$c['id']?'selected':''?>><?=s($c['class_name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100 rounded-3 shadow-sm fw-bold">RUN SYSTEM ANALYSIS</button>
            </div>
        </form>
    </div>
</div>

<!-- STAT CARDS -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-4"><i class="bi bi-people fs-4"></i></div>
                <div><h3 class="mb-0 fw-black Syne text-navy"><?= $totalStudentsCount ?></h3><span class="fs-tiny fw-bold opacity-50">Segment Count</span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-success bg-opacity-10 text-success p-3 rounded-4"><i class="bi bi-patch-check fs-4"></i></div>
                <div><h3 class="mb-0 fw-black Syne text-navy"><?= $totalPresent ?></h3><span class="fs-tiny fw-bold opacity-50">Aggregated Present</span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-danger bg-opacity-10 text-danger p-3 rounded-4"><i class="bi bi-x-diamond fs-4"></i></div>
                <div><h3 class="mb-0 fw-black Syne text-navy"><?= $totalAbsent ?></h3><span class="fs-tiny fw-bold opacity-50">Log Gap (Absence)</span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm p-3 rounded-4 bg-white">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-warning bg-opacity-10 text-warning p-3 rounded-4"><i class="bi bi-calendar2-week fs-4"></i></div>
                <div><h3 class="mb-0 fw-black Syne text-navy"><?= $totalDays ?></h3><span class="fs-tiny fw-bold opacity-50">Calculated Cycles</span></div>
            </div>
        </div>
    </div>
</div>

<!-- TRENDS -->
<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-transparent border-0 pt-4 px-4"><h6 class="fw-black Syne text-navy"><i class="bi bi-bar-chart-fill me-2 text-accent"></i>Presence Progression Stream</h6></div>
            <div class="card-body"><canvas id="trendChart" height="280"></canvas></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
            <div class="card-header bg-transparent border-0 pt-4 px-4"><h6 class="fw-black Syne text-navy">Identity Mapping</h6></div>
            <div class="card-body p-4 pt-2">
                 <div class="mx-auto mb-3" style="max-width:240px;"><canvas id="methodChart"></canvas></div>
                 <div class="small fw-bold opacity-75 mt-3 text-uppercase fs-tiny tracking-wider">Device Fingerprints Summary</div>
                 <p class="text-muted small">Distribution of capture events using Mantra Hardware vs Virtual Overrides.</p>
            </div>
        </div>
    </div>
</div>

<!-- TABLE LAYERS -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-navy text-white fw-bold Syne small p-3"><i class="bi bi-diagram-3-fill me-2 text-accent"></i>SCHOOL PLACEMENT SUMMARY</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:13.5px">
            <thead class="bg-light"><tr><th class="ps-4">Group Classification</th><th>Dossiers</th><th>Total Logs</th><th>Log Integrity</th><th class="pe-4" style="width:250px">Dynamic Distribution</th></tr></thead>
            <tbody>
                <?php foreach($classSummary as $cls): 
                    $clRate = ($cls['pres'] + $cls['abs']) > 0 ? round($cls['pres'] / ($cls['pres'] + $cls['abs']) * 100) : 0; ?>
                <tr>
                    <td class="ps-4 fw-black text-navy"><?= s($cls['class_name']) ?></td>
                    <td><span class="badge bg-primary bg-opacity-10 text-primary px-3 rounded-pill"><?= $cls['student_pop'] ?> Enrolled</span></td>
                    <td class="small"><span class="text-success fw-bold"><?= $cls['pres'] ?>P</span> / <span class="text-danger fw-bold"><?= $cls['abs'] ?>A</span></td>
                    <td class="fw-bold"><?=$clRate?>%</td>
                    <td class="pe-4">
                         <div class="progress rounded-pill bg-light" style="height:6px">
                            <div class="progress-bar <?= ($clRate >= 70 ? 'bg-success' : 'bg-warning') ?>" style="width:<?=$clRate?>%"></div>
                         </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4">
    <!-- BEST/LOW SECTIONS -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
            <div class="card-header bg-success text-white small fw-bold px-4 py-3"><i class="bi bi-star-fill me-2"></i>PRECISION STUDENTS (HIGH ENGAGEMENT)</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach($topStudents as $s): $rt = $s['days_logged'] > 0 ? round($s['present_days']/$s['days_logged']*100) : 0; ?>
                    <li class="list-group-item d-flex align-items-center gap-3 p-3 border-0 border-bottom-light">
                        <div class="avatar-box rounded-3 bg-light overflow-hidden shadow-sm" style="width:36px; height:36px">
                             <?= !empty($s['photo']) ? '<img src="'.UPLOAD_URL.$s['photo'].'" style="width:100%;height:100%;object-fit:cover;">' : strtoupper(substr($s['full_name'],0,1)) ?>
                        </div>
                        <div class="flex-grow-1"><div class="fw-black text-navy Syne small"><?= s($s['full_name']) ?></div><small class="text-muted"><?= $s['admission_number'] ?></small></div>
                        <div class="text-end"><div class="fw-black text-success small"><?= $rt ?>%</div><small class="fs-tiny opacity-50 fw-bold"><?= $s['present_days'] ?> Sessions</small></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm rounded-4 h-100 overflow-hidden">
            <div class="card-header bg-danger text-white small fw-bold px-4 py-3"><i class="bi bi-exclamation-octagon-fill me-2"></i>LOW ACTIVITY AUDIT</div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach($bottomStudents as $s): $rt = $s['days_logged'] > 0 ? round($s['present_days']/$s['days_logged']*100) : 0; ?>
                    <li class="list-group-item d-flex align-items-center gap-3 p-3 border-0 border-bottom-light">
                         <div class="avatar-box rounded-3 bg-light d-flex align-items-center justify-content-center fw-bold opacity-75" style="width:36px; height:36px"><?= strtoupper(substr($s['full_name'],0,1)) ?></div>
                         <div class="flex-grow-1"><div class="fw-black text-navy Syne small"><?= s($s['full_name']) ?></div><small class="text-muted"><?= $s['admission_number'] ?></small></div>
                         <div class="text-end"><div class="fw-black text-danger small"><?= $rt ?>%</div><small class="fs-tiny opacity-50 fw-bold">Critical Deficiency</small></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
/* CSS: Refined Aesthetic Palette */
.Syne { font-family: 'Syne', sans-serif !important; letter-spacing: -0.01em; }
.fw-black { font-weight: 900 !important; }
.text-navy { color: #071829 !important; }
.bg-navy { background-color: #071829 !important; }
.fs-tiny { font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.12em; }
.avatar-box { flex-shrink: 0; background-color: #eee; font-size: 11px; display: flex; align-items: center; justify-content: center; }
.border-bottom-light { border-bottom: 1px solid #f8fafc; }
</style>

<?php
$methodStmt = $db->prepare("
SELECT a.method, COUNT(*) as cnt 
FROM attendance a 
JOIN students s ON a.student_id = s.id
WHERE a.attendance_date BETWEEN ? AND ? $classFilterSql
GROUP BY a.method
");
$methodStmt->execute($fullParams);
$methodDist = $methodStmt->fetchAll();
$trendDates = json_encode(array_column($dailyTrend, 'attendance_date'));
$trendP     = json_encode(array_map('intval', array_column($dailyTrend, 'p')));
$trendA     = json_encode(array_map('intval', array_column($dailyTrend, 'a')));
$mLabels    = json_encode(array_map(fn($v)=>ucfirst(str_replace('_',' ',$v['method'])), $methodDist));
$mCounts    = json_encode(array_column($methodDist, 'cnt'));

$extraScripts = "
<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
<script>
// Main Audit Stream Chart
new Chart(document.getElementById('trendChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: $trendDates,
        datasets: [
            { label:'IDENTITY DETECTED', data: $trendP, borderColor:'#f4be38', backgroundColor:'rgba(244,190,56,0.1)', fill:true, tension:0.4, borderWidth:3 },
            { label:'DEFICIT/ABSENCE', data: $trendA, borderColor:'rgba(255,255,255,0)', backgroundColor:'rgba(239,68,68,0.1)', fill:true, tension:0.4 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { 
            x: { grid: { display: false }, ticks: { font: {size: 10} } },
            y: { grid: { borderDash:[5,5] }, ticks: { font: {size: 10} } }
        }
    }
});

// Identity Mechanism Pie
new Chart(document.getElementById('methodChart'), {
    type: 'doughnut',
    data: {
        labels: $mLabels,
        datasets: [{ data: $mCounts, backgroundColor: ['#071829','#f4be38','#10b981'], borderWidth: 0 }]
    },
    options: { cutout: '82%', plugins: { legend: { position: 'bottom', labels: { boxWidth:12, font: {size:10, weight:600} } } } }
});
</script>";
include 'layout_footer.php';
?>