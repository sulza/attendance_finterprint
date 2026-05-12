<?php
require_once 'config.php';
requireLogin();

$db = getDB();
$user = currentUser();
$pageTitle = 'Overview Dashboard';

$todayDate = date('Y-m-d');

/** 
 * SECURITY: Use Prepared Statements even for Dates
 */

// Combined Stats for Efficiency
$totalStudents = (int)$db->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
$totalClasses = (int)$db->query("SELECT COUNT(*) FROM classes")->fetchColumn();

$attendanceStmt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE attendance_date = ? AND status = 'present'");
$attendanceStmt->execute([$todayDate]);
$todayPresent = (int)$attendanceStmt->fetchColumn();

$todayAbsent = max(0, $totalStudents - $todayPresent);
$attendanceRate = $totalStudents > 0 ? round(($todayPresent / $totalStudents) * 100) : 0;
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

// Weekly data fetching
$weeklyStmt = $db->prepare("
    SELECT attendance_date, COUNT(*) as cnt
    FROM attendance
    WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND status='present'
    GROUP BY attendance_date ORDER BY attendance_date ASC
");
$weeklyStmt->execute();
$weeklyData = $weeklyStmt->fetchAll();

$weekDays = []; $weekCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $weekDays[] = date('D', strtotime($d));
    $found = 0;
    foreach ($weeklyData as $row) {
        if ($row['attendance_date'] === $d) { $found = (int)$row['cnt']; break; }
    }
    $weekCounts[] = $found;
}

// Attendance by class (Paginated top list)
$classStmt = $db->prepare("
    SELECT c.class_name, COUNT(a.id) as present_count
    FROM classes c
    LEFT JOIN students s ON s.class_id = c.id AND s.status='active'
    LEFT JOIN attendance a ON a.student_id = s.id AND a.attendance_date = ? AND a.status='present'
    GROUP BY c.id, c.class_name
    ORDER BY present_count DESC LIMIT 5
");
$classStmt->execute([$todayDate]);
$classAttendance = $classStmt->fetchAll();

// Recent logs with security parameters
$logsStmt = $db->prepare("
    SELECT a.*, s.full_name, s.admission_number, c.class_name, u.full_name as marked_by_name
    FROM attendance a
    JOIN students s ON s.id = a.student_id
    LEFT JOIN classes c ON c.id = s.class_id
    LEFT JOIN users u ON u.id = a.marked_by
    ORDER BY a.created_at DESC LIMIT 8
");
$logsStmt->execute();
$recentLogs = $logsStmt->fetchAll();

// Gender distribution
$genderData = $db->query("SELECT gender, COUNT(*) as cnt FROM students WHERE status='active' GROUP BY gender")->fetchAll();

include 'layout_header.php';
?>

<!-- Branding Header -->
<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
    <div class="mb-3 mb-md-0">
        <h1 class="fw-bold mb-1" style="font-family:'Syne', sans-serif;"><?= s($pageTitle) ?></h1>
        <p class="text-muted small mb-0"><i class="bi bi-clock"></i> Live Status as of <?= date('H:i') ?> · <span class="fw-medium"><?= date('D, M j, Y') ?></span></p>
    </div>
    <div class="d-flex gap-2">
        <button onclick="window.location.reload();" class="btn btn-outline-secondary btn-sm rounded-3"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
        <?php if (hasRole(['director','admission_officer'])): ?>
            <a href="students.php?action=register" class="btn btn-accent btn-sm rounded-3 px-3">
                <i class="bi bi-person-plus-fill me-1"></i> Register Student
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Overview Stats Grid -->
<div class="row g-4 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left: 4px solid var(--primary);">
            <div class="stat-icon" style="background: rgba(10,35,66,0.05); color: var(--primary);"><i class="bi bi-person-lines-fill"></i></div>
            <div class="stat-value"><?= number_format($totalStudents) ?></div>
            <div class="stat-label">Total Enrolment</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left: 4px solid var(--accent2);">
            <div class="stat-icon" style="background: rgba(62,207,142,0.08); color: var(--accent2);"><i class="bi bi-patch-check"></i></div>
            <div class="stat-value"><?= number_format($todayPresent) ?></div>
            <div class="stat-label">Logged Present</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left: 4px solid #ef4444;">
            <div class="stat-icon" style="background: rgba(239,68,68,0.08); color: #ef4444;"><i class="bi bi-clock-history"></i></div>
            <div class="stat-value"><?= number_format($todayAbsent) ?></div>
            <div class="stat-label">Marked Absent</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card" style="border-left: 4px solid var(--accent);">
            <div class="stat-icon" style="background: rgba(232,184,75,0.08); color: var(--accent);"><i class="bi bi-diagram-3"></i></div>
            <div class="stat-value"><?= number_format($totalClasses) ?></div>
            <div class="stat-label">Class Sections</div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Main Tracking Chart -->
    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-header border-0 bg-transparent py-3 px-4 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2 text-accent"></i> Attendance Flow</h6>
                <div class="dropdown">
                    <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="dropdown">Last 7 Days</button>
                </div>
            </div>
            <div class="card-body px-4 pb-4">
                <canvas id="mainFlowChart" height="280"></canvas>
            </div>
        </div>
    </div>

    <!-- Attendance Rate Visual -->
    <div class="col-12 col-xl-4">
        <div class="card h-100 bg-primary text-white border-0 shadow-lg">
            <div class="card-body p-4 d-flex flex-column align-items-center justify-content-center text-center">
                <div class="position-relative mb-4">
                    <canvas id="circularAttendance" width="160" height="160"></canvas>
                    <div class="position-absolute top-50 start-50 translate-middle">
                        <h2 class="fw-bold mb-0 Syne"><?= $attendanceRate ?>%</h2>
                        <p class="small mb-0 opacity-50">TALLY</p>
                    </div>
                </div>
                <h5 class="Syne fw-bold mb-2">School Integrity Rate</h5>
                <p class="small text-white-50 px-4">Showing present student vs inactive/absent students for current date.</p>
                
                <div class="row w-100 g-2 mt-2">
                    <div class="col-6">
                        <div class="p-2 rounded bg-white bg-opacity-10">
                            <h4 class="mb-0"><?= $todayPresent ?></h4>
                            <small class="opacity-50 text-uppercase" style="font-size:10px">Attending</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 rounded bg-white bg-opacity-10">
                            <h4 class="mb-0"><?= $todayAbsent ?></h4>
                            <small class="opacity-50 text-uppercase" style="font-size:10px">Not found</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance by Class List -->
    <div class="col-md-5">
        <div class="card border-0 h-100 shadow-sm">
            <div class="card-header bg-transparent py-3">
                <h6 class="fw-bold mb-0">High-Active Classes</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($classAttendance as $idx => $cls): ?>
                    <li class="list-group-item d-flex align-items-center py-3 border-light">
                        <div class="me-3 fw-bold text-muted" style="width:20px; font-size:12px"><?= $idx+1 ?></div>
                        <div class="flex-grow-1">
                            <div class="fw-bold text-navy" style="font-size:13px"><?= s($cls['class_name']) ?></div>
                            <div class="progress mt-1" style="height:4px">
                                <?php $barPct = $totalStudents > 0 ? ($cls['present_count'] / $totalStudents) * 500 : 0; ?>
                                <div class="progress-bar bg-accent" style="width: <?= min(100, $barPct) ?>%"></div>
                            </div>
                        </div>
                        <div class="ms-3 badge rounded-pill bg-light text-navy border fw-bold"><?= $cls['present_count'] ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Recent Logs with Method Visibility -->
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">Live Presence Stream</h6>
                <a href="reports.php" class="small text-accent text-decoration-none fw-bold">Audit History</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0" style="font-size:13px">
                        <thead class="bg-light">
                            <tr>
                                <th class="border-0 ps-3">User</th>
                                <th class="border-0">Entry Point</th>
                                <th class="border-0">Time</th>
                                <th class="border-0">Identity</th>
                                <th class="border-0 text-center">Stat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-bold"><?= s($log['full_name']) ?></div>
                                    <small class="text-muted"><?= s($log['admission_number']) ?></small>
                                </td>
                                <td><span class="text-navy fw-medium"><?= s($log['class_name']) ?></span></td>
                                <td class="text-muted"><?= date('h:i:s A', strtotime($log['time_in'])) ?></td>
                                <td>
                                    <?php if ($log['method'] === 'biometric' || $log['method'] === 'fingerprint'): ?>
                                        <i class="bi bi-fingerprint text-accent"></i> <small class="fw-medium">L1-HW</small>
                                    <?php else: ?>
                                        <i class="bi bi-keyboard text-muted"></i> <small>MNL-V</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="p-1 px-2 rounded-pill small fw-bold" style="background:#eefdf8; color:#10b981; font-size:10px">PRESENT</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* CSS Integration */
.stat-card {
    background: #fff;
    padding: 20px;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s;
}
.stat-card:hover { transform: translateY(-3px); }
.stat-icon {
    width: 42px; height: 42px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    margin-bottom: 12px;
}
.stat-value { font-size: 1.5rem; font-weight: 800; font-family: 'Syne', sans-serif; color: var(--primary); }
.stat-label { font-size: 0.8rem; color: #64748b; font-weight: 500; }
</style>

<?php
$weekLabelsJS = json_encode($weekDays);
$weekDataJS = json_encode($weekCounts);
$extraScripts = "
<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
<script>
// Dashboard Chart Config
const flowCtx = document.getElementById('mainFlowChart').getContext('2d');
new Chart(flowCtx, {
    type: 'line',
    data: {
        labels: {$weekLabelsJS},
        datasets: [{
            label: 'Students Logged',
            data: {$weekDataJS},
            borderColor: '#f4be38',
            backgroundColor: 'rgba(244, 190, 56, 0.1)',
            fill: true,
            tension: 0.4,
            borderWidth: 3,
            pointRadius: 4,
            pointBackgroundColor: '#071829'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
            y: { grid: { borderDash: [5, 5] }, ticks: { stepSize: 20, color: '#94a3b8' } }
        }
    }
});

// Circular Progress for Integrity Rate
const circCtx = document.getElementById('circularAttendance').getContext('2d');
new Chart(circCtx, {
    type: 'doughnut',
    data: {
        datasets: [{
            data: [{$attendanceRate}, " . (100 - $attendanceRate) . "],
            backgroundColor: ['#f4be38', 'rgba(255,255,255,0.1)'],
            borderWidth: 0,
            circumference: 360,
            rotation: 0
        }]
    },
    options: {
        cutout: '80%',
        responsive: false,
        plugins: { legend: { display: false }, tooltip: { enabled: false } }
    }
});
</script>
";
include 'layout_footer.php';
?>