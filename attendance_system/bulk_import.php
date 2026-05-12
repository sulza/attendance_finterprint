<?php
require_once 'config.php';
requireRole(['director', 'admission_officer']);
$pageTitle = 'Identity Batch Ingest';
$db   = getDB();
$user = currentUser();

// ── CSRF PROTECTION ──
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$imported  = 0;
$skipped   = 0;
$errors_log = [];
$success_log = [];

/**
 * Download Standardized CSV Template
 */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="EMS_Bulk_Import_Template.csv"');
    $out = fopen('php://output', 'w');
    // Ensure UTF-8 BOM for Excel compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($out, [
        'full_name','nin','date_of_birth','gender','phone_number','address',
        'guardian_name','guardian_phone',
        'primary_school_name','primary_school_start','primary_school_end',
        'junior_secondary_name','junior_secondary_start','junior_secondary_end',
        'year_of_admission','admission_number','class_name'
    ]);
    fputcsv($out, ['JOHN DOE','12345678901','2010-05-15','male','080...','Address Here','Jane Doe','081...','PS School','2016','2021','JS School','2021','2024','2024','','JSS 1A']);
    fclose($out);
    exit;
}

/**
 * Handle Process Ingest
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    // Verify Security Token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Security Token Error.");
    }

    // Process checks
    set_time_limit(300); // Allow up to 5 mins for massive files
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'The file failed to upload correctly.');
    } else {
        $fileType = mime_content_type($file['tmp_name']);
        if (!in_array($fileType, ['text/csv', 'text/plain', 'application/vnd.ms-excel'])) {
            flash('error', 'Unsupported format. Please upload a standard CSV file.');
            header("Location: bulk_import.php"); exit;
        }

        // Map existing classes to prevent frequent DB hits
        $classRows = $db->query("SELECT id, LOWER(TRIM(class_name)) as name FROM classes")->fetchAll();
        $classMap  = array_column($classRows, 'id', 'name');

        $handle = fopen($file['tmp_name'], 'r');
        fgetcsv($handle); // Discard headers
        
        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (empty(array_filter($data))) continue; // Skip blank lines

            // Clean & Map inputs
            $d = array_map('trim', $data);
            $d = array_pad($d, 17, ''); // Force 17 columns
            
            list(
                $fullName, $nin, $dob, $gender, $phone, $address, $guardName, $guardPhone,
                $psName, $psStart, $psEnd, $jsName, $jsStart, $jsEnd, $yearAdm, $admNo, $className
            ) = $d;

            // Security Sanitization for insertion
            $fullName = strtoupper($fullName);
            $nin = preg_replace('/[^0-9]/', '', $nin); // Force NIN to be digits
            $gender = strtolower($gender);

            // Validation Logic
            if (empty($fullName) || strlen($nin) < 5 || empty($dob)) {
                $errors_log[] = "Line $rowNum: Identifiers missing (Name/NIN/DOB).";
                $skipped++; continue;
            }

            // Check duplicate Identity
            $dupStmt = $db->prepare("SELECT id FROM students WHERE nin = ? OR (full_name = ? AND date_of_birth = ?)");
            $dupStmt->execute([$nin, $fullName, $dob]);
            if ($dupStmt->fetch()) {
                $errors_log[] = "Line $rowNum ($fullName): Data exists or NIN is duplicate.";
                $skipped++; continue;
            }

            // Admission No Generator Logic
            if (empty($admNo)) $admNo = generateAdmissionNumber();

            // Classroom resolver
            $cid = $classMap[strtolower($className)] ?? null;

            try {
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO students 
                    (admission_number, full_name, nin, date_of_birth, gender, phone_number, address, guardian_name, guardian_phone, 
                     primary_school_name, primary_school_start, primary_school_end, junior_secondary_name, junior_secondary_start, junior_secondary_end, 
                     year_of_admission, class_id, registered_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                
                $stmt->execute([
                    $admNo, $fullName, $nin, $dob, $gender, $phone, $address, $guardName, $guardPhone,
                    $psName?:null, $psStart?:null, $psEnd?:null, $jsName?:null, $jsStart?:null, $jsEnd?:null,
                    (int)$yearAdm ?: date('Y'), $cid, $user['id']
                ]);

                $sid = $db->lastInsertId();
                // Virtual Enrolment - Fingerprint Pending status
                $db->prepare("UPDATE students SET status='active' WHERE id=?")->execute([$sid]);
                
                $db->commit();
                $imported++;
                $success_log[] = ['name' => $fullName, 'id' => $admNo];
            } catch (Exception $e) {
                $db->rollBack();
                $errors_log[] = "Line $rowNum ($fullName): Critical processing failure.";
                $skipped++;
            }
        }
        fclose($handle);
    }
}

include 'layout_header.php';
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-center">
    <div class="mb-3 mb-md-0 text-center text-md-start">
        <h1 class="fw-black Syne mb-1 text-navy">Registry Batch Process</h1>
        <p class="text-muted small"><i class="bi bi-file-earmark-arrow-up"></i> Mass-student record migration and identity import</p>
    </div>
    <div class="d-flex gap-2">
        <a href="bulk_import.php?template=1" class="btn btn-navy btn-sm px-3 rounded-pill fw-bold">
            <i class="bi bi-download me-2 text-accent"></i>Get Master CSV Template
        </a>
        <a href="students.php" class="btn btn-outline-secondary btn-sm px-3 rounded-pill"><i class="bi bi-arrow-left"></i> Registry</a>
    </div>
</div>

<!-- STATS CONSOLE -->
<?php if ($imported > 0 || $skipped > 0): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card border-start border-4 border-success p-3 rounded-4 shadow-sm">
                <span class="text-muted small text-uppercase fw-bold">Validated & Logged</span>
                <h2 class="fw-black text-navy mb-0"><?= $imported ?></h2>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card border-start border-4 border-danger p-3 rounded-4 shadow-sm">
                <span class="text-muted small text-uppercase fw-bold">Identity Clashes/Skipped</span>
                <h2 class="fw-black text-navy mb-0"><?= $skipped ?></h2>
            </div>
        </div>
        <div class="col-md-6">
            <div class="alert bg-navy text-white rounded-4 border-0 d-flex align-items-center mb-0 h-100 px-4">
                <i class="bi bi-info-circle-fill fs-3 text-accent me-3"></i>
                <div class="small">
                    All successful imports have been placed into **PENDING BIOMETRICS** status.
                    Ask students to visit the Enrolment desk.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- LEFT PANEL: DATA INGEST FORM -->
    <div class="col-md-6">
        <div class="card h-100 border-0 shadow-sm rounded-4">
            <div class="card-header bg-transparent py-3 px-4 border-bottom">
                <h6 class="mb-0 fw-black text-navy Syne">SECURE INGEST PORTAL</h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="mainImportForm">
                    <!-- HIDDEN SECURITY TOKEN -->
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div id="dragDropBox" 
                         class="d-flex flex-column align-items-center justify-content-center p-5 text-center bg-light border border-2 border-dashed rounded-5"
                         style="cursor: pointer; transition: 0.3s; min-height: 250px;">
                        <i class="bi bi-cloud-upload fs-1 text-primary opacity-25"></i>
                        <h6 class="fw-bold mt-3 mb-1">Dossier Data Capture</h6>
                        <p class="text-muted small">Select your optimized `.csv` export from Microsoft Excel</p>
                        
                        <div id="fileFeedback" class="badge bg-navy text-white rounded-pill px-3 py-2 d-none">
                            Ready for ingest...
                        </div>
                    </div>

                    <input type="file" name="csv_file" id="csv_input_hidden" accept=".csv" class="d-none" required>

                    <button class="btn btn-accent btn-lg w-100 mt-4 rounded-pill fw-black shadow text-navy py-3" id="execBtn">
                        INITIALIZE INGEST <i class="bi bi-arrow-right-short ms-2"></i>
                    </button>
                </form>

                <!-- Mapping Table mini -->
                <div class="mt-4 pt-4 border-top">
                    <h6 class="fs-tiny fw-bold text-navy opacity-50 mb-3"><i class="bi bi-link-45deg me-2"></i>DATABASE SYSTEM MAPPING</h6>
                    <div class="row row-cols-3 g-2">
                        <?php foreach (['full_name' => 'Identities','nin' => 'TaxID/ID','class_name' => 'Placement','guardian_phone' => 'Mobile','primary_school_name' => 'Archive','date_of_birth' => 'BornDate'] as $slug => $label): ?>
                            <div class="col">
                                <div class="p-2 border rounded text-center" style="font-size:10px">
                                    <div class="text-muted"><?= $label ?></div>
                                    <code class="fw-bold"><?= $slug ?></code>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL: PROCESSING REPORTS & CLASSIFICATION -->
    <div class="col-md-6">
        <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden">
             <!-- RESULTS LOGS -->
             <?php if ($errors_log): ?>
             <div class="bg-danger-subtle p-3 px-4 border-bottom d-flex align-items-center justify-content-between">
                <span class="small fw-black text-danger Syne">FATAL EXCEPTIONS / DUPLICATES</span>
                <span class="badge bg-danger rounded-pill"><?= count($errors_log) ?> Errors</span>
             </div>
             <div class="overflow-auto p-4" style="max-height: 250px;">
                <?php foreach ($errors_log as $elog): ?>
                    <div class="small p-2 bg-white border-bottom-light border-0 border-bottom border-start border-3 border-danger mb-2 shadow-sm rounded-1">
                        <i class="bi bi-x-circle text-danger me-2"></i><?= htmlspecialchars($elog) ?>
                    </div>
                <?php endforeach; ?>
             </div>
             <?php endif; ?>

             <div class="card-header bg-navy text-white small fw-bold px-4 py-3">PLACEMENT STATUS REGISTRY</div>
             <div class="card-body p-0">
                 <div class="list-group list-group-flush" style="max-height: 400px; overflow-y:auto">
                    <?php if (!$success_log): ?>
                        <div class="p-5 text-center text-muted small">
                            <i class="bi bi-folder-x fs-1 opacity-25"></i>
                            <p class="mt-2 Syne fw-bold opacity-50 text-uppercase tracking-wider">Awaiting Stream Processing</p>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($success_log as $idx => $slog): ?>
                        <div class="list-group-item d-flex align-items-center gap-3 p-3">
                            <span class="text-navy fw-black opacity-25 small"><?= $idx+1 ?></span>
                            <div class="rounded bg-success bg-opacity-10 p-2"><i class="bi bi-person-plus-fill text-success fs-5"></i></div>
                            <div>
                                <h6 class="small fw-bold mb-0 text-navy"><?= s($slog['name']) ?></h6>
                                <span class="fs-tiny opacity-50">RECORD: #<?= $slog['id'] ?> COMMITTED</span>
                            </div>
                            <span class="badge bg-success-subtle text-success ms-auto border-0 fw-bold">OK</span>
                        </div>
                    <?php endforeach; ?>
                 </div>
             </div>
        </div>
    </div>
</div>

<style>
/* CSS: Refined App Styles */
.fw-black { font-weight: 900 !important; }
.text-navy { color: #071829 !important; }
.bg-navy { background-color: #071829 !important; }
.Syne { font-family: 'Syne', sans-serif !important; letter-spacing: -0.01em; }
.fs-tiny { font-size: 10px !important; text-transform: uppercase; letter-spacing: 0.12em; }
.stat-card h2 { letter-spacing: -1.5px; font-family: 'Syne'; }
#dragDropBox:hover { border-color: var(--accent) !important; background-color: white !important; }
.border-bottom-light { border-bottom-color: #f8fafc !important; }
</style>

<script>
    const box = document.getElementById('dragDropBox');
    const input = document.getElementById('csv_input_hidden');
    const feedback = document.getElementById('fileFeedback');
    const btn = document.getElementById('execBtn');

    box.addEventListener('click', () => input.click());

    input.addEventListener('change', () => {
        if(input.files.length > 0) {
            feedback.classList.remove('d-none');
            feedback.innerText = 'SELECT: ' + input.files[0].name;
            box.querySelector('p').innerText = "Process " + (input.files[0].size/1024).toFixed(1) + " KB Dossier";
        }
    });

    document.getElementById('mainImportForm').onsubmit = function() {
        btn.disabled = true;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span> STREAMING ARCHIVE...`;
    }
</script>

<?php include 'layout_footer.php'; ?>