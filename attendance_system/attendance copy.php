<?php
require_once 'config.php';
requireLogin();

$db   = getDB();
$user = currentUser();
$view = $_GET['view'] ?? 'mark';

// ---- MARK ATTENDANCE via AJAX ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $method    = $_POST['method'] ?? '';
    $studentId = 0;
    $student   = null;

    if ($method === 'fingerprint') {
        // Real hardware path: JS sends raw base64 template captured by BioCapture engine
        $fpTemplateB64 = trim($_POST['fp_template'] ?? '');
        // Legacy fallback: manual code entry (kept for admin override)
        $fpCode        = trim($_POST['fp_code']     ?? '');

        if (!empty($fpTemplateB64)) {
            // Hash the received template and look it up — O(1), no full table scan
            $fpHash = hash('sha256', base64_decode($fpTemplateB64));
            $stmt   = $db->prepare("SELECT s.*, c.class_name FROM students s
                                    LEFT JOIN classes c ON c.id=s.class_id
                                    WHERE s.fingerprint_hash=? AND s.status='active'");
            $stmt->execute([$fpHash]);
            $student = $stmt->fetch();
            if (!$student) {
                echo json_encode(['success'=>false,'message'=>'Fingerprint not matched. Please re-enrol this student.']); exit;
            }
        } elseif (!empty($fpCode)) {
            // Admin override: match by stored hash directly
            $stmt = $db->prepare("SELECT s.*, c.class_name FROM students s
                                  LEFT JOIN classes c ON c.id=s.class_id
                                  WHERE s.fingerprint_hash=? AND s.status='active'");
            $stmt->execute([$fpCode]);
            $student = $stmt->fetch();
            if (!$student) {
                echo json_encode(['success'=>false,'message'=>'Fingerprint code not recognised.']); exit;
            }
        } else {
            echo json_encode(['success'=>false,'message'=>'No fingerprint data received from scanner.']); exit;
        }
    } elseif ($method === 'id') {
        $admNo = trim($_POST['admission_number'] ?? '');
        if (empty($admNo)) { echo json_encode(['success'=>false,'message'=>'Enter admission number.']); exit; }
        $stmt = $db->prepare("SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.admission_number=? AND s.status='active'");
        $stmt->execute([$admNo]);
        $student = $stmt->fetch();
        if (!$student) { echo json_encode(['success'=>false,'message'=>'Student not found.']); exit; }
    } else {
        echo json_encode(['success'=>false,'message'=>'Invalid method.']); exit;
    }

    // Check if already marked today
    $today = date('Y-m-d');
    $check = $db->prepare("SELECT * FROM attendance WHERE student_id=? AND attendance_date=?");
    $check->execute([$student['id'], $today]);
    $existing = $check->fetch();

    if ($existing) {
        // Update time_out if already marked
        $db->prepare("UPDATE attendance SET time_out=NOW() WHERE id=?")->execute([$existing['id']]);
        echo json_encode([
            'success'  => true,
            'type'     => 'checkout',
            'message'  => "✅ Time-out recorded for {$student['full_name']}",
            'student'  => ['name'=>$student['full_name'],'adm'=>$student['admission_number'],'class'=>$student['class_name']??'—','action'=>'Checked Out','time'=>date('h:i A')],
        ]); exit;
    }

    // Mark attendance
    $db->prepare("INSERT INTO attendance (student_id,attendance_date,time_in,status,method,marked_by) VALUES(?,?,NOW(),'present',?,?)")
       ->execute([$student['id'], $today, $method === 'fingerprint' ? 'fingerprint' : 'id_card', $user['id']]);

    echo json_encode([
        'success'  => true,
        'type'     => 'checkin',
        'message'  => "✅ Attendance marked for {$student['full_name']}",
        'student'  => ['name'=>$student['full_name'],'adm'=>$student['admission_number'],'class'=>$student['class_name']??'—','action'=>'Checked In','time'=>date('h:i A')],
    ]); exit;
}

// ---- BULK MARK ABSENT ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_absent'])) {
    requireRole(['director','class_master','admin_officer']);
    $classId = (int)($_POST['class_id'] ?? 0);
    $date    = $_POST['date'] ?? date('Y-m-d');
    if ($classId) {
        $stmtS = $db->prepare("SELECT id FROM students WHERE class_id=? AND status='active'");
        $stmtS->execute([$classId]);
        $studentsInClass = $stmtS->fetchAll(PDO::FETCH_COLUMN);
        $markedCount = 0;
        foreach ($studentsInClass as $sid) {
            $chk = $db->prepare("SELECT id FROM attendance WHERE student_id=? AND attendance_date=?");
            $chk->execute([$sid, $date]);
            if (!$chk->fetch()) {
                $db->prepare("INSERT INTO attendance (student_id,attendance_date,status,method,marked_by) VALUES(?,?,'absent','manual',?)")
                   ->execute([$sid, $date, $user['id']]);
                $markedCount++;
            }
        }
        flash('success', "$markedCount student(s) marked absent for " . date('M d, Y', strtotime($date)));
    }
    header('Location: attendance.php?view=history'); exit;
}

// History filters
$filterDate  = $_GET['date'] ?? '';
$filterClass = (int)($_GET['class_id'] ?? 0);
$filterStatus= $_GET['status'] ?? '';

$where = 'WHERE 1=1';
if ($filterDate)  $where .= " AND a.attendance_date = " . $db->quote($filterDate);
if ($filterClass) $where .= " AND s.class_id = $filterClass";
if ($filterStatus) $where .= " AND a.status = " . $db->quote($filterStatus);
if ($user['role'] === 'class_master' && $user['class_id']) {
    $where .= " AND s.class_id = " . (int)$user['class_id'];
}

$historyRecords = $view === 'history'
    ? $db->query("SELECT a.*,s.full_name,s.admission_number,c.class_name,u.full_name as marked_by_name
                  FROM attendance a
                  JOIN students s ON s.id=a.student_id
                  LEFT JOIN classes c ON c.id=s.class_id
                  LEFT JOIN users u ON u.id=a.marked_by
                  $where ORDER BY a.attendance_date DESC, a.created_at DESC LIMIT 500")->fetchAll()
    : [];

// CSV Export
if (isset($_GET['export']) && $view === 'history') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="attendance_' . date('Ymd') . '.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Admission No','Student Name','Class','Date','Time In','Time Out','Status','Method','Marked By']);
    foreach ($historyRecords as $r) {
        fputcsv($out, [
            $r['admission_number'],$r['full_name'],$r['class_name']??'—',
            $r['attendance_date'],$r['time_in']??'—',$r['time_out']??'—',
            $r['status'],$r['method'],$r['marked_by_name']??'—'
        ]);
    }
    fclose($out); exit;
}

$classes = $db->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();
$todayPresent = $db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date=CURDATE() AND status='present'")->fetchColumn();
$todayAbsent  = $db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date=CURDATE() AND status='absent'")->fetchColumn();

$pageTitle = $view === 'history' ? 'Attendance History' : 'Mark Attendance';
include 'layout_header.php';
?>

<?php if ($view === 'mark'): ?>
<div class="page-header">
    <div>
        <div class="page-header-title">Mark Attendance</div>
        <div class="page-header-sub"><?= date('l, F j, Y') ?> · <?= $todayPresent ?> present so far</div>
    </div>
    <a href="attendance.php?view=history" class="btn btn-outline-secondary"><i class="bi bi-clock-history me-1"></i>View History</a>
</div>

<div class="row g-3">
<!-- Mark Attendance Panel -->
<div class="col-md-7">
    <div class="card">
        <div class="card-header"><div class="card-header-title"><i class="bi bi-fingerprint"></i> Scan / Enter ID</div></div>
        <div class="card-body">
            <!-- Method Tabs -->
            <ul class="nav nav-pills mb-4" id="methodTabs">
                <li class="nav-item">
                    <button class="nav-link active" id="tabFP" onclick="setMethod('fingerprint')" style="font-size:0.88rem;">
                        <i class="bi bi-fingerprint me-1"></i>Biometric
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link" id="tabID" onclick="setMethod('id')" style="font-size:0.88rem;">
                        <i class="bi bi-card-text me-1"></i>Admission No
                    </button>
                </li>
            </ul>

            <!-- Fingerprint Panel -->
            <div id="fpPanel">
                <!-- Device status bar -->
                <div style="background:var(--bg);border-radius:8px;padding:8px 14px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;">
                    <div style="font-size:.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Scanner</div>
                    <div id="attendDeviceStatus">
                        <span style="display:inline-flex;align-items:center;gap:6px;font-size:.78rem;">
                            <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block;"></span>Detecting…
                        </span>
                    </div>
                    <button onclick="BioCapture.init()" style="background:none;border:none;font-size:.75rem;color:var(--primary);cursor:pointer;padding:2px 8px;border-radius:6px;" title="Re-detect scanner">
                        <i class="bi bi-arrow-clockwise me-1"></i>Retry
                    </button>
                </div>

                <div class="text-center mb-3">
                    <!-- Scanner circle — click triggers real capture -->
                    <div class="fingerprint-scan" id="fpScanner" onclick="startScan()"
                         title="Click to capture fingerprint from connected scanner"
                         style="width:150px;height:150px;cursor:pointer;">
                        <div class="scan-ripple"></div>
                        <i class="bi bi-fingerprint" style="font-size:3.5rem;color:var(--muted);" id="fpIcon"></i>
                        <div style="font-size:.75rem;color:var(--muted);margin-top:6px;" id="fpLabel">Click to Scan</div>
                    </div>
                    <div id="scanStatusMsg" style="min-height:18px;font-size:.78rem;color:var(--muted);margin-top:8px;"></div>

                    <!-- Scan button (alternative to clicking circle) -->
                    <button onclick="startScan()" class="btn btn-primary mt-3" style="min-width:180px;">
                        <i class="bi bi-fingerprint me-1"></i>Capture Fingerprint
                    </button>

                    <!-- Admin override: hash lookup -->
                    <div class="mt-3" style="max-width:300px;margin:12px auto 0;">
                        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:6px;font-weight:600;">Admin Override (Hash Lookup)</div>
                        <div class="input-group input-group-sm">
                            <input type="text" id="fpCode" class="form-control"
                                   placeholder="Enter fingerprint hash…"
                                   style="font-family:monospace;font-size:.78rem;">
                            <button class="btn btn-outline-secondary" onclick="markAttendance('fingerprint')">
                                <i class="bi bi-check2"></i>
                            </button>
                        </div>
                        <div class="form-text" style="font-size:.72rem;">SHA-256 hash from student record (fallback only)</div>
                    </div>
                </div>
            </div>

            <!-- ID Panel -->
            <div id="idPanel" style="display:none;">
                <div class="text-center mb-3">
                    <div style="max-width:320px;margin:0 auto;">
                        <div style="width:80px;height:80px;background:var(--bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;border:2px dashed var(--border);">
                            <i class="bi bi-card-text" style="font-size:2rem;color:var(--muted);"></i>
                        </div>
                        <label class="form-label">Admission Number</label>
                        <div class="input-group">
                            <input type="text" id="admNo" class="form-control" placeholder="ESS/2024/0001" style="font-family:monospace;" onkeypress="if(event.key==='Enter')markAttendance('id')">
                            <button class="btn btn-primary" onclick="markAttendance('id')"><i class="bi bi-check2-circle me-1"></i>Mark</button>
                        </div>
                        <div class="form-text">Press Enter or click Mark to record attendance</div>
                    </div>
                </div>
            </div>

            <!-- Result Box -->
            <div id="resultBox" style="display:none;margin-top:20px;"></div>
        </div>
    </div>
</div>

<!-- Today's Log + Stats -->
<div class="col-md-5">
    <div class="row g-3 mb-3">
        <div class="col-6">
            <div class="stat-card green">
                <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
                <div class="stat-value" id="presentCount"><?= $todayPresent ?></div>
                <div class="stat-label">Present Today</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card red">
                <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
                <div class="stat-value"><?= $todayAbsent ?></div>
                <div class="stat-label">Absent Today</div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div class="card-header-title"><i class="bi bi-activity"></i> Live Log</div>
            <span style="font-size:0.75rem;color:var(--muted);" id="logTime"><?= date('h:i A') ?></span>
        </div>
        <div class="card-body p-0" style="max-height:360px;overflow-y:auto;" id="liveLog">
            <?php
            $todayLogs = $db->query("SELECT a.*,s.full_name,s.admission_number,c.class_name FROM attendance a JOIN students s ON s.id=a.student_id LEFT JOIN classes c ON c.id=s.class_id WHERE a.attendance_date=CURDATE() ORDER BY a.created_at DESC LIMIT 15")->fetchAll();
            foreach ($todayLogs as $l): ?>
            <div class="log-entry" style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:flex-start;">
                <div style="width:8px;height:8px;background:var(--accent2);border-radius:50%;margin-top:5px;flex-shrink:0;"></div>
                <div style="flex:1;">
                    <div style="font-weight:500;font-size:0.85rem;"><?= htmlspecialchars($l['full_name']) ?></div>
                    <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($l['class_name']??'—') ?> · <?= $l['time_in'] ? date('h:i A',strtotime($l['time_in'])) : '—' ?></div>
                </div>
                <span class="badge badge-<?= $l['status'] ?> rounded-pill" style="font-size:0.72rem;"><?= ucfirst($l['status']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (empty($todayLogs)): ?>
            <div style="padding:30px;text-align:center;color:var(--muted);font-size:0.88rem;">No attendance marked yet today</div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<!-- Bulk Mark Section -->
<?php if (hasRole(['director','class_master','admin_officer'])): ?>
<div class="card mt-3">
    <div class="card-header"><div class="card-header-title"><i class="bi bi-people-fill"></i> Bulk Mark Absent</div></div>
    <div class="card-body">
        <form method="POST" onsubmit="return confirm('Mark all unmarked students in this class as absent?')">
            <input type="hidden" name="bulk_absent" value="1">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Class</label>
                    <select name="class_id" class="form-select" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-x-circle me-1"></i>Mark All Absent</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php else: // HISTORY VIEW ?>
<div class="page-header">
    <div>
        <div class="page-header-title">Attendance History</div>
        <div class="page-header-sub">Filter and export attendance records</div>
    </div>
    <div class="d-flex gap-2">
        <a href="attendance.php?view=history&<?= http_build_query(array_merge($_GET,['export'=>1])) ?>" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Export CSV</a>
        <a href="attendance.php" class="btn btn-accent"><i class="bi bi-plus-circle me-1"></i>Mark Attendance</a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="view" value="history">
            <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($filterDate) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Class</label>
                <select name="class_id" class="form-select">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterClass==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <option value="present" <?= $filterStatus==='present'?'selected':'' ?>>Present</option>
                    <option value="absent" <?= $filterStatus==='absent'?'selected':'' ?>>Absent</option>
                    <option value="late" <?= $filterStatus==='late'?'selected':'' ?>>Late</option>
                    <option value="excused" <?= $filterStatus==='excused'?'selected':'' ?>>Excused</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
            <div class="col-md-2">
                <a href="attendance.php?view=history" class="btn btn-outline-secondary w-100">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table data-table mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th>Method</th>
                    <th>Marked By</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($historyRecords as $r): ?>
            <tr>
                <td style="font-size:0.82rem;"><?= formatDate($r['attendance_date']) ?></td>
                <td>
                    <a href="students.php?action=view&id=<?= $r['student_id'] ?>" style="text-decoration:none;color:var(--text);">
                        <div style="font-weight:500;font-size:0.85rem;"><?= htmlspecialchars($r['full_name']) ?></div>
                        <div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($r['admission_number']) ?></div>
                    </a>
                </td>
                <td style="font-size:0.82rem;"><?= htmlspecialchars($r['class_name']??'—') ?></td>
                <td style="font-size:0.82rem;"><?= $r['time_in'] ? date('h:i A',strtotime($r['time_in'])) : '—' ?></td>
                <td style="font-size:0.82rem;"><?= $r['time_out'] ? date('h:i A',strtotime($r['time_out'])) : '—' ?></td>
                <td>
                    <?php if ($r['method']==='fingerprint'): ?>
                    <span style="font-size:0.75rem;background:#f0fdf4;color:#15803d;padding:3px 8px;border-radius:6px;"><i class="bi bi-fingerprint me-1"></i>Biometric</span>
                    <?php elseif ($r['method']==='id_card'): ?>
                    <span style="font-size:0.75rem;background:#eff6ff;color:#1d4ed8;padding:3px 8px;border-radius:6px;"><i class="bi bi-card-text me-1"></i>ID Card</span>
                    <?php else: ?>
                    <span style="font-size:0.75rem;background:#fef3c7;color:#b45309;padding:3px 8px;border-radius:6px;">Manual</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:0.82rem;"><?= htmlspecialchars($r['marked_by_name']??'—') ?></td>
                <td><span class="badge badge-<?= $r['status'] ?> rounded-pill"><?= ucfirst($r['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($historyRecords)): ?>
            <tr><td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-calendar-x display-6 d-block mb-2"></i>No records found</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$extraScripts = '
<script>
/* ============================================================
   BioCapture — same auto-detection engine as fingerprint.php
   Copied inline so attendance page works standalone.
   ============================================================ */
const BioCapture = (function() {
    let activeSDK=null, activeDevice=null;
    const SDKS = [
        { name:"Futronic",
          async probe(){return new Promise(r=>{try{const ws=new WebSocket("ws://localhost:8765");ws.onopen=()=>{ws.close();r(true)};ws.onerror=()=>r(false);setTimeout(()=>{try{ws.close();}catch(e){}r(false)},2000);}catch(e){r(false)}})},
          async capture(){return new Promise((resolve,reject)=>{const ws=new WebSocket("ws://localhost:8765");let ok=false;ws.onopen=()=>ws.send(JSON.stringify({cmd:"capture",timeout:15000}));ws.onmessage=e=>{try{const d=JSON.parse(e.data);if(d.status==="ok"&&d.template){ok=true;ws.close();resolve({template:d.template,model:d.device_model||"Futronic",serial:d.device_serial||""})}else{ws.close();reject(d.message||"Futronic failed")}}catch(ex){ws.close();reject("Futronic: bad response")}};ws.onerror=()=>reject("Futronic: connection error");ws.onclose=()=>{if(!ok)reject("Futronic: closed")}});}
        },
        { name:"Mantra",
          async probe(){try{const r=await fetch("http://localhost:11100/mfs100/info",{signal:AbortSignal.timeout(2000)});return r.ok}catch(e){return false}},
          async capture(){const i=await(await fetch("http://localhost:11100/mfs100/info")).json();const c=await(await fetch("http://localhost:11100/mfs100/capture",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({timeout:15000,quality:70}),signal:AbortSignal.timeout(18000)})).json();if(c.ErrorCode===0&&c.BitmapData)return{template:c.BitmapData,model:i.DeviceName||"Mantra MFS100",serial:i.SerialNumber||""};throw new Error("Mantra: "+(c.ErrorDescription||"Failed"));}
        },
        { name:"DigitalPersona",
          async probe(){try{const r=await fetch("http://localhost:15895/dp/status",{signal:AbortSignal.timeout(2000)});return r.ok}catch(e){return typeof window.FingerprintSdkTest!=="undefined"}},
          async capture(){try{const r=await fetch("http://localhost:15895/dp/capture",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({format:"ISO",quality:75,timeout:15000}),signal:AbortSignal.timeout(18000)});const d=await r.json();if(d.Success&&d.Template)return{template:d.Template,model:d.DeviceName||"DigitalPersona",serial:d.DeviceSerial||""};throw new Error(d.Error)}catch(e){if(typeof window.FingerprintSdkTest!=="undefined")return new Promise((res,rej)=>window.FingerprintSdkTest.startCapture(s=>s&&s.Data?res({template:s.Data,model:"DigitalPersona U.are.U",serial:""}):rej("DP: no sample")));throw e;}}
        },
        { name:"SecuGen",
          async probe(){return typeof window.SGIBIOSDK!=="undefined"},
          async capture(){return new Promise((res,rej)=>window.SGIBIOSDK.GetBMPImage(r=>r.ErrorCode===0&&r.BMPBase64?res({template:r.BMPBase64,model:r.DeviceName||"SecuGen",serial:r.SerialNum||""}):rej("SecuGen: "+r.ErrorCode)));}
        },
        // Add custom scanner here if needed:
        // { name:"Custom", async probe(){...}, async capture(){...} },
    ];
    let detectedSDK = null;
    async function detect(){
        for(const sdk of SDKS){try{if(await sdk.probe()){activeSDK=sdk.name;detectedSDK=sdk;updateDeviceUI(sdk.name,"#3ecf8e");return sdk;}}catch(e){}}
        updateDeviceUI("No scanner","#ef4444");return null;
    }
    function updateDeviceUI(name,color){
        const el=document.getElementById("attendDeviceStatus");
        if(el)el.innerHTML=`<span style="display:inline-flex;align-items:center;gap:6px;font-size:.78rem;"><span style="width:8px;height:8px;border-radius:50%;background:${color};flex-shrink:0;display:inline-block;"></span>${name}</span>`;
    }
    return {
        init:detect,
        activeSDKName:()=>activeSDK,
        async capture(){
            const sdk = detectedSDK || await detect();
            if(!sdk) throw new Error("No fingerprint scanner detected. Check USB connection.");
            const r = await sdk.capture(); activeDevice=r.model; return r;
        }
    };
})();

let presentCount = ' . (int)$todayPresent . ';

// Init scanner detection on page load
document.addEventListener("DOMContentLoaded", function() {
    BioCapture.init();
    const admInput = document.getElementById("admNo");
    if (admInput) admInput.focus();
});

function setMethod(method) {
    currentMethod = method;
    document.getElementById("fpPanel").style.display = method==="fingerprint" ? "block" : "none";
    document.getElementById("idPanel").style.display = method==="id"          ? "block" : "none";
    document.getElementById("tabFP").classList.toggle("active", method==="fingerprint");
    document.getElementById("tabID").classList.toggle("active", method==="id");
    hideResult();
}
let currentMethod = "fingerprint";

// ---- Real scanner capture for attendance ----
async function startScan() {
    if (!selectedStudentId && currentMethod === "fingerprint") {
        // No student pre-selected — capture and match server-side
    }
    const scanner = document.getElementById("fpScanner");
    const icon    = document.getElementById("fpIcon");
    const label   = document.getElementById("fpLabel");
    scanner.classList.add("scanning");
    icon.style.color   = "#3ecf8e";
    label.textContent  = "Scanning…";
    document.getElementById("scanStatusMsg") && (document.getElementById("scanStatusMsg").textContent = "Reading fingerprint…");

    try {
        const captured = await BioCapture.capture();
        scanner.classList.remove("scanning");
        icon.style.color  = "#3ecf8e";
        label.textContent = "Matching…";

        // Send template to server for hash-based matching
        $.post("attendance.php", {
            ajax:        1,
            method:      "fingerprint",
            fp_template: captured.template,
        }, function(res) {
            icon.style.color  = "var(--muted)";
            label.textContent = "Click to Scan";
            showResult(res);
            if (res.success) {
                if (res.type === "checkin") { presentCount++; document.getElementById("presentCount").textContent = presentCount; }
                addToLog(res.student);
            }
        }, "json").fail(() => showResult({success:false,message:"Server error."}));

    } catch(err) {
        scanner.classList.remove("scanning");
        icon.style.color  = "#ef4444";
        label.textContent = "Failed — Retry";
        setTimeout(() => { icon.style.color="var(--muted)"; label.textContent="Click to Scan"; }, 3000);
        showResult({
            success: false,
            message: String(err) + '<br><small style="color:#991b1b;">Check scanner USB connection and ensure the device bridge service is running. '
                   + '<a href="fingerprint.php" style="color:var(--primary);font-weight:600;">Go to Enrolment page</a> for full diagnostics.</small>'
        });
    }
}

// ---- ID-based attendance ----
function markAttendance(method) {
    const payload = { ajax:1, method };
    if (method === "id") {
        payload.admission_number = document.getElementById("admNo").value.trim();
        if (!payload.admission_number) return;
    }
    $.post("attendance.php", payload, function(res) {
        showResult(res);
        if (res.success) {
            document.getElementById("admNo") && (document.getElementById("admNo").value = "");
            if (res.type === "checkin") { presentCount++; document.getElementById("presentCount").textContent = presentCount; }
            addToLog(res.student);
            document.getElementById("admNo") && document.getElementById("admNo").focus();
        }
    }, "json").fail(() => showResult({success:false,message:"Server error."}));
}

function showResult(res) {
    const box = document.getElementById("resultBox");
    box.style.display = "block";
    box.innerHTML = `<div style="padding:12px 16px;border-radius:10px;background:${res.success?"#f0fdf4":"#fff1f2"};border:1px solid ${res.success?"#bbf7d0":"#fecaca"};color:${res.success?"#15803d":"#dc2626"};font-weight:500;font-size:.9rem;">${res.message}</div>`;
    setTimeout(hideResult, 4000);
}
function hideResult() { const b=document.getElementById("resultBox"); if(b)b.style.display="none"; }

function addToLog(s) {
    const log = document.getElementById("liveLog");
    if (!log) return;
    const e = document.createElement("div");
    e.className = "log-entry";
    e.style.cssText = "padding:10px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:flex-start;background:#f0fdf4;";
    e.innerHTML = `<div style="width:8px;height:8px;background:#3ecf8e;border-radius:50%;margin-top:5px;flex-shrink:0;"></div>
        <div style="flex:1;"><div style="font-weight:500;font-size:.85rem;">${s.name}</div>
        <div style="font-size:.75rem;color:var(--muted);">${s.class} · ${s.time}</div></div>
        <span style="font-size:.72rem;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:10px;">${s.action}</span>`;
    log.insertBefore(e, log.firstChild);
    setTimeout(() => e.style.background = "", 2000);
}
</script>
<style>
@keyframes fadeIn { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:translateY(0)} }
</style>';
include 'layout_footer.php';
?>
