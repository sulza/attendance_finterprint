<?php
require_once 'config.php';
requireLogin();

$db        = getDB();
$user      = currentUser();
$action    = $_GET['action'] ?? 'list';
$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Enforce strictly 250KB for security and storage efficiency
$maxKB = 250 * 1024; 

// Generate CSRF Token for all forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* =========================================================
   POST HANDLERS (Hardened Security)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Security Check Failed: Request originated from an untrusted source.');
    }

    $postAction = $_POST['action'] ?? '';

    /* ---------- REGISTER / UPDATE ---------- */
    if (in_array($postAction, ['register', 'update'])) {
        requireRole(['director', 'admission_officer']);

        // Data collection
        $fullName   = trim($_POST['full_name']    ?? '');
        $nin        = trim($_POST['nin']           ?? '');
        $dob        = $_POST['date_of_birth']      ?? '';
        $gender     = $_POST['gender']             ?? '';
        $phone      = trim($_POST['phone_number']  ?? '');
        $address    = trim($_POST['address']       ?? '');
        $guardName  = trim($_POST['guardian_name']  ?? '');
        $guardPhone = trim($_POST['guardian_phone'] ?? '');

        $psName  = trim($_POST['primary_school_name']      ?? '');
        $psStart = $_POST['primary_school_start']           ?? null;
        $psEnd   = $_POST['primary_school_end']             ?? null;
        $jsName  = trim($_POST['junior_secondary_name']    ?? '');
        $jsStart = $_POST['junior_secondary_start']         ?? null;
        $jsEnd   = $_POST['junior_secondary_end']           ?? null;

        $yearAdm = (int)($_POST['year_of_admission'] ?? date('Y'));
        $admNo   = trim($_POST['admission_number']   ?? '');
        $classId = (int)($_POST['class_id'] ?? 0) ?: null;

        $errors = [];
        if (empty($fullName)) $errors[] = 'Full name is mandatory.';
        if (empty($nin))      $errors[] = 'NIN is required for identification.';
        if (empty($dob))      $errors[] = 'Please provide date of birth.';

        // Validation for new registration
        if (empty($errors) && $postAction === 'register') {
            if (empty($admNo)) $admNo = generateAdmissionNumber();
            
            $chk = $db->prepare("SELECT id FROM students WHERE nin=?");
            $chk->execute([$nin]);
            if ($chk->fetch()) $errors[] = 'Conflict: NIN already exists in system.';

            $chk2 = $db->prepare("SELECT id FROM students WHERE admission_number=?");
            $chk2->execute([$admNo]);
            if ($chk2->fetch()) $errors[] = 'Conflict: Admission number is taken.';
        }

        // Photo Upload Handling (Enforcing 250KB)
        $photoPath = null;
        if (empty($errors) && !empty($_FILES['photo']['name'])) {
            $f = $_FILES['photo'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                $errors[] = 'Identity Photo must be JPG, PNG, or WebP.';
            } elseif ($f['size'] > $maxKB) {
                $errors[] = 'Identity Photo exceeds 250KB limit.';
            } else {
                $photoDir = UPLOAD_DIR . 'photos/';
                if (!is_dir($photoDir)) mkdir($photoDir, 0755, true);
                $pName = 'photo_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $photoDir . $pName)) {
                    $photoPath = 'photos/' . $pName;
                }
            }
        }

        if (empty($errors)) {
            try {
                if ($postAction === 'register') {
                    $stmt = $db->prepare("
                        INSERT INTO students
                          (admission_number, full_name, nin, date_of_birth, gender,
                           phone_number, address, guardian_name, guardian_phone,
                           primary_school_name, primary_school_start, primary_school_end,
                           junior_secondary_name, junior_secondary_start, junior_secondary_end,
                           year_of_admission, class_id, photo, registered_by)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $admNo,$fullName,$nin,$dob,$gender,$phone,$address,$guardName,$guardPhone,
                        $psName?:null,$psStart?:null,$psEnd?:null,$jsName?:null,$jsStart?:null,$jsEnd?:null,
                        $yearAdm,$classId,$photoPath,$user['id']
                    ]);
                    $newId = $db->lastInsertId();
                    
                    // Generate initial empty hash
                    $fpHash = hash('sha256', $newId.$nin.random_bytes(16));
                    $db->prepare("UPDATE students SET fingerprint_hash=? WHERE id=?")->execute([$fpHash, $newId]);

                    flash('success', "Account Registered: $fullName (#$admNo). Ensure fingerprint enrolment follows.");
                    header("Location: students.php?id=$newId&action=view"); exit;
                } else {
                    $sid = (int)$_POST['student_id'];
                    $sql = "UPDATE students SET
                              full_name=?, nin=?, date_of_birth=?, gender=?,
                              phone_number=?, address=?, guardian_name=?, guardian_phone=?,
                              primary_school_name=?, primary_school_start=?, primary_school_end=?,
                              junior_secondary_name=?, junior_secondary_start=?, junior_secondary_end=?,
                              year_of_admission=?, class_id=?, admission_number=?"
                          .($photoPath?", photo=?":"")." WHERE id=?";
                    
                    $params = [$fullName,$nin,$dob,$gender,$phone,$address,$guardName,$guardPhone,$psName?:null,$psStart?:null,$psEnd?:null,$jsName?:null,$jsStart?:null,$jsEnd?:null,$yearAdm,$classId,$admNo];
                    if ($photoPath) $params[] = $photoPath;
                    $params[] = $sid;

                    $db->prepare($sql)->execute($params);
                    flash('success', "Student profile successfully updated.");
                    header("Location: students.php?id=$sid&action=view"); exit;
                }
            } catch (PDOException $e) { $errors[] = 'Execution failed: '.$e->getMessage(); }
        }
        if($errors) { flash('error', implode(' ', $errors)); header("Location: students.php?action=".($postAction==='register'?'register':"edit&id=$studentId")); exit; }
    }

    /* ---------- UPLOAD DOCUMENTS (Strict 250KB Logic) ---------- */
    if ($postAction === 'upload_docs') {
        requireRole(['director', 'admission_officer']);
        $sid     = (int)($_POST['student_id'] ?? 0);
        $docType = $_POST['document_type']    ?? 'others';
        $allowed = ['JI','NIN','primary_certificate','junior_certificate','medical_report','result','others'];

        if (!in_array($docType, $allowed)) { 
            flash('error', 'Classification Error: Select a valid category.');
            header("Location: students.php?id=$sid&action=view"); exit; 
        }

        $docDir = UPLOAD_DIR."docs/$sid/";
        if (!is_dir($docDir)) mkdir($docDir, 0755, true);

        $ok = 0; $skipped = [];
        if (!empty($_FILES['documents']['name'][0])) {
            foreach ($_FILES['documents']['name'] as $i => $name) {
                if ($_FILES['documents']['error'][$i] !== 0) continue;
                
                // Enforce 250KB limit per file
                if ($_FILES['documents']['size'][$i] > $maxKB) {
                    $skipped[] = "($name) exceeds 250KB limit";
                    continue;
                }

                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','pdf','doc','docx','webp'])) continue;

                $nPath = $docType . '_' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['documents']['tmp_name'][$i], $docDir . $nPath)) {
                    $stmt = $db->prepare("INSERT INTO student_documents(student_id,document_type,file_name,file_path,file_size,mime_type,uploaded_by) VALUES(?,?,?,?,?,?,?)");
                    $stmt->execute([$sid, $docType, $name, "docs/$sid/$nPath", $_FILES['documents']['size'][$i], mime_content_type($docDir.$nPath), $user['id']]);
                    $ok++;
                }
            }
        }
        
        $msg = $ok . " item(s) secured in vault.";
        if($skipped) $msg .= " Warning: " . implode(", ", $skipped);
        flash($ok > 0 ? 'success' : 'error', $msg);
        header("Location: students.php?id=$sid&action=view#tabDocs"); exit;
    }

    /* ---------- DELETE DOCUMENT ---------- */
    if ($postAction === 'delete_doc') {
        requireRole(['director', 'admission_officer']);
        $docId = (int)$_POST['doc_id'];
        $sid   = (int)$_POST['student_id'];

        $q = $db->prepare("SELECT file_path FROM student_documents WHERE id=? AND student_id=?");
        $q->execute([$docId, $sid]);
        if($r = $q->fetch()) {
            if (file_exists(UPLOAD_DIR . $r['file_path'])) unlink(UPLOAD_DIR . $r['file_path']);
            $db->prepare("DELETE FROM student_documents WHERE id=?")->execute([$docId]);
            flash('success', 'Document permanently purged from registry.');
        }
        header("Location: students.php?id=$sid&action=view#tabDocs"); exit;
    }
}

/* =========================================================
   GET ROUTING & FETCH
   ========================================================= */
if ($action === 'delete' && $studentId) {
    requireRole(['director']);
    $db->prepare("UPDATE students SET status='deleted' WHERE id=?")->execute([$studentId]);
    flash('success', 'Student record archived as deleted.');
    header('Location: students.php'); exit;
}

$classes = $db->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();

if ($studentId && in_array($action, ['view', 'edit'])) {
    $stmt = $db->prepare("SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.id=? LIMIT 1");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
    if (!$student) { flash('error','Invalid Request: ID does not match any entry.'); header('Location: students.php'); exit; }
    
    $studentDocs = $db->prepare("SELECT * FROM student_documents WHERE student_id=? ORDER BY uploaded_at DESC");
    $studentDocs->execute([$studentId]);
    $studentDocs = $studentDocs->fetchAll();
}

$pageTitle = match($action) {
    'register' => 'New Admission',
    'edit'     => 'Edit Profile',
    'view'     => 'Student Folder',
    default    => 'Manage Students'
};

include 'layout_header.php';

/* =========================================================
   VIEW 1: REGISTRY (LIST VIEW)
   ========================================================= */
if ($action === 'list'):
    $where = ["s.status != 'deleted'"];
    $p = [];

    // Filter by Search Query
    $sq = trim($_GET['search'] ?? '');
    if ($sq) {
        $where[] = "(s.full_name LIKE ? OR s.admission_number LIKE ? OR s.nin LIKE ?)";
        array_push($p, "%$sq%", "%$sq%", "%$sq%");
    }
    
    // Filter by Role Assignment
    if ($user['role'] === 'class_master' && $user['class_id']) {
        $where[] = "s.class_id = ?";
        $p[] = (int)$user['class_id'];
    }

    // Filter by specific dropdown
    $cf = (int)($_GET['class_id'] ?? 0);
    if ($cf) {
        $where[] = "s.class_id = ?";
        $p[] = $cf;
    }

    $qStr = "SELECT s.*, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE " . implode(" AND ", $where) . " ORDER BY s.full_name ASC";
    $list = $db->prepare($qStr);
    $list->execute($p);
    $students = $list->fetchAll();
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
    <div>
        <h1 class="fw-black Syne mb-0"><?= $pageTitle ?></h1>
        <p class="text-muted small"><i class="bi bi-person-badge"></i> Data Hub for Egyptian Modern Students (<?= count($students) ?> Found)</p>
    </div>
    <?php if(hasRole(['director','admission_officer'])): ?>
    <a href="students.php?action=register" class="btn btn-accent rounded-pill px-4 shadow-sm"><i class="bi bi-person-plus-fill me-2"></i>Create New ID</a>
    <?php endif; ?>
</div>

<div class="card mb-4 border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-body p-4 bg-light">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="action" value="list">
            <div class="col-md-5">
                <label class="small fw-black text-navy opacity-75 ms-2 mb-1">UNIVERSAL IDENTITY SEARCH</label>
                <input type="text" name="search" class="form-control rounded-pill border-0 shadow-sm px-4" placeholder="Name, ADM No, or NIN Card..." value="<?=htmlspecialchars($sq)?>">
            </div>
            <div class="col-md-3">
                <label class="small fw-black text-navy opacity-75 ms-2 mb-1">GRADE CLASSIFICATION</label>
                <select name="class_id" class="form-select rounded-pill border-0 shadow-sm px-4">
                    <option value="">Show All Classes...</option>
                    <?php foreach($classes as $c): ?>
                    <option value="<?=$c['id']?>" <?=$cf==$c['id']?'selected':''?>><?=s($c['class_name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100 rounded-pill py-2 shadow fw-bold">EXECUTE</button></div>
            <div class="col-md-2"><a href="students.php" class="btn btn-link btn-sm w-100 text-muted">Clear all Filters</a></div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="table-responsive">
        <table class="table align-middle data-table mb-0">
            <thead class="bg-navy text-white text-uppercase" style="font-size:11px">
                <tr>
                    <th class="ps-4 py-3">Legal Entity</th>
                    <th>Official Code</th>
                    <th>Level</th>
                    <th class="text-center">Biometrics</th>
                    <th class="text-center">Presence Status</th>
                    <th class="pe-4 text-end">Action File</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($students as $s): ?>
                <tr>
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                            <div class="rounded-circle overflow-hidden bg-white d-flex align-items-center justify-content-center border border-2 border-accent" style="width:40px; height:40px">
                                <?php if($s['photo']): ?>
                                    <img src="<?=UPLOAD_URL.$s['photo']?>" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <span class="text-navy fw-black small"><?=strtoupper(substr($s['full_name'],0,1))?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="fw-black text-navy mb-0" style="font-size:13.5px;"><?=s($s['full_name'])?></div>
                                <div class="small text-muted fs-tiny"><?=formatDate($s['date_of_birth'])?> · <b><?=strtoupper($s['gender'])?></b></div>
                            </div>
                        </div>
                    </td>
                    <td><code><?=s($s['nin'])?></code></td>
                    <td><div class="small fw-black text-primary"><?=s($s['class_name'] ?? 'PENDING')?></div></td>
                    <td class="text-center">
                        <?php if($s['fingerprint_template']): ?>
                            <i class="bi bi-fingerprint fs-5 text-success"></i><br><small class="fw-bold" style="font-size:8px">ENROLLED</small>
                        <?php else: ?>
                            <i class="bi bi-fingerprint fs-5 text-danger opacity-25"></i><br><small class="fw-bold opacity-25" style="font-size:8px">MISSING</small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge badge-present px-3"><?=strtoupper($s['status'])?></span></td>
                    <td class="pe-4 text-end">
                         <div class="d-flex justify-content-end gap-1">
                             <a href="students.php?action=view&id=<?=$s['id']?>" class="btn btn-sm btn-outline-navy rounded-circle"><i class="bi bi-folder-fill"></i></a>
                             <a href="students.php?action=edit&id=<?=$s['id']?>" class="btn btn-sm btn-outline-warning rounded-circle"><i class="bi bi-pencil-fill"></i></a>
                             <?php if(hasRole(['director'])): ?>
                             <a href="javascript:void(0)" onclick="confirmDelete('students.php?action=delete&id=<?=$s['id']?>','Delete Record #<?=s($s['admission_number'])?>?')" class="btn btn-sm btn-outline-danger rounded-circle"><i class="bi bi-trash3-fill"></i></a>
                             <?php endif; ?>
                         </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$students): ?><tr><td colspan="6" class="text-center p-5 text-muted small fw-bold">NO MATCHING PROFILES DISCOVERED</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php 
/* =========================================================
   VIEW 2: THE MULTI-FIELD ENROLMENT FORM
   ========================================================= */
elseif(in_array($action,['register','edit'])):
    $s = $student ?? [];
?>

<div class="page-header d-flex align-items-center justify-content-between">
    <h2 class="fw-black Syne text-navy"><?=$action === 'register' ? 'ENROLMENT' : 'MODIFICATION' ?></h2>
    <a href="students.php" class="btn btn-navy btn-sm rounded-pill px-4"><i class="bi bi-chevron-double-left me-1"></i>Registry Dashboard</a>
</div>

<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
    <input type="hidden" name="action" value="<?=$action === 'register' ? 'register' : 'update'?>">
    <?php if($action==='edit'): ?><input type="hidden" name="student_id" value="<?=$s['id']?>"><?php endif; ?>

    <div class="row g-4 mt-1">
        <div class="col-lg-8">
            <!-- I. BIOGRAPHIC -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-navy text-white fw-black small">I. PERSONAL LEGAL ENTITY DATA</div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-9"><label class="form-label small fw-black opacity-75">FULL LEGAL NAME (OFFICIAL)</label>
                        <input type="text" name="full_name" class="form-control rounded-3 py-2" value="<?=s($s['full_name']??'')?>" required></div>
                        
                        <div class="col-md-3"><label class="form-label small fw-black opacity-75">GENDER</label>
                        <select name="gender" class="form-select rounded-3 py-2" required>
                            <option value="male" <?=($s['gender']??'')==='male'?'selected':''?>>MALE</option>
                            <option value="female" <?=($s['gender']??'')==='female'?'selected':''?>>FEMALE</option>
                        </select></div>

                        <div class="col-md-5"><label class="form-label small fw-black opacity-75">GOV. IDENTITY NUMBER (NIN)</label>
                        <input type="text" name="nin" class="form-control rounded-3" placeholder="Verify with Card" value="<?=s($s['nin']??'')?>" required></div>

                        <div class="col-md-4"><label class="form-label small fw-black opacity-75">BORN DATE</label>
                        <input type="date" name="date_of_birth" class="form-control rounded-3" value="<?=$s['date_of_birth']??''?>" required></div>

                        <div class="col-md-3"><label class="form-label small fw-black opacity-75">MOBILE PATH</label>
                        <input type="tel" name="phone_number" class="form-control rounded-3" value="<?=s($s['phone_number']??'')?>" placeholder="Personal Mobile"></div>

                        <div class="col-12"><label class="form-label small fw-black opacity-75">AUTHENTICATED HOME RESIDENCE</label>
                        <textarea name="address" class="form-control rounded-3" rows="2" placeholder="Full Home Landmark Address"><?=s($s['address']??'')?></textarea></div>
                    </div>
                </div>
            </div>

            <!-- II. PARENTAL -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-primary text-white fw-black small">II. PARENTAL / GUARDIAN PATHWAY</div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-7"><label class="form-label small fw-black opacity-75">GUARDIAN LEGAL NAME</label>
                        <input type="text" name="guardian_name" class="form-control" value="<?=s($s['guardian_name']??'')?>"></div>
                        <div class="col-md-5"><label class="form-label small fw-black opacity-75">PRIMARY CONTACT (EMERGENCY)</label>
                        <input type="tel" name="guardian_phone" class="form-control" value="<?=s($s['guardian_phone']??'')?>" placeholder="Parent Phone"></div>
                    </div>
                </div>
            </div>

            <!-- III. SCHOLASTIC BACKGROUND (Retention of Original Coloring) -->
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-navy text-white fw-black small">III. SCHOLASTIC PROGRESSION ARCHIVE</div>
                <div class="card-body p-4 bg-light">
                    <!-- BLUE PRIMARY -->
                    <div class="p-4 rounded-4 mb-4 shadow-sm border-start border-4 border-primary" style="background: linear-gradient(135deg,#f0f7ff 0%,#dbeafe 100%)">
                        <div class="d-flex align-items-center mb-3 text-navy fw-black Syne small"><i class="bi bi-award-fill me-2"></i>PRIMARY COMPLETION DATA</div>
                        <div class="row g-3">
                            <div class="col-12"><label class="fs-tiny fw-bold">LAST ATTENDED PRIMARY SCHOOL NAME</label>
                            <input type="text" name="primary_school_name" class="form-control bg-white border-0 py-2 rounded-3 shadow-sm" value="<?=s($s['primary_school_name']??'')?>"></div>
                            <div class="col-6"><label class="fs-tiny fw-bold opacity-50">DATE ADMITTED</label>
                            <input type="date" name="primary_school_start" class="form-control border-0 rounded-3" value="<?=$s['primary_school_start']??''?>"></div>
                            <div class="col-6"><label class="fs-tiny fw-bold opacity-50">DATE LEFT/GRADUATED</label>
                            <input type="date" name="primary_school_end" class="form-control border-0 rounded-3" value="<?=$s['primary_school_end']??''?>"></div>
                        </div>
                    </div>
                    <!-- AMBER JSS -->
                    <div class="p-4 rounded-4 shadow-sm border-start border-4 border-warning" style="background: linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%)">
                        <div class="d-flex align-items-center mb-3 text-warning-dark fw-black Syne small"><i class="bi bi-building-add me-2 text-warning"></i>JUNIOR SECONDARY PLACEMENT (IF APPLICABLE)</div>
                        <div class="row g-3">
                            <div class="col-12"><label class="fs-tiny fw-bold">FORMER JSS COLLEGE NAME</label>
                            <input type="text" name="junior_secondary_name" class="form-control bg-white border-0 py-2 rounded-3 shadow-sm" value="<?=s($s['junior_secondary_name']??'')?>"></div>
                            <div class="col-6"><label class="fs-tiny fw-bold opacity-50">COMMENCEMENT DATE</label>
                            <input type="date" name="junior_secondary_start" class="form-control border-0 rounded-3" value="<?=$s['junior_secondary_start']??''?>"></div>
                            <div class="col-6"><label class="fs-tiny fw-bold opacity-50">TERMINATION/LEAVING DATE</label>
                            <input type="date" name="junior_secondary_end" class="form-control border-0 rounded-3" value="<?=$s['junior_secondary_end']??''?>"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
             <div class="sticky-top" style="top:90px; z-index: 10">
                 <!-- IDENTITY VISUAL -->
                 <div class="card border-0 shadow-sm rounded-4 text-center p-4 mb-4">
                    <div class="mb-2"><span class="badge badge-present fs-tiny px-3">E-ENTITY VERIFIED</span></div>
                    <div class="rounded-4 mx-auto mb-3 border border-4 border-light shadow overflow-hidden bg-light d-flex align-items-center justify-content-center" style="width:160px; height:180px">
                        <?php if($s['photo']): ?><img src="<?=UPLOAD_URL.$s['photo']?>" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?><i class="bi bi-camera-fill text-muted opacity-25 display-2"></i><?php endif; ?>
                    </div>
                    <label class="btn btn-outline-navy btn-sm rounded-pill shadow-sm"><i class="bi bi-camera me-1"></i>IDENTITY PIC
                        <input type="file" name="photo" class="d-none" accept="image/*">
                    </label>
                    <p class="fs-tiny text-muted mt-2">ENFORCING STRICT MAX <b>250KB</b> JPEG/PNG</p>
                 </div>

                 <!-- ADMIN CONTROLS -->
                 <div class="card bg-navy border-0 rounded-4 shadow p-4 text-white">
                    <label class="fs-tiny fw-black text-accent opacity-50 ms-1 mb-1 Syne">ARCHIVE NUMBER</label>
                    <input type="text" name="admission_number" class="form-control border-0 bg-white bg-opacity-10 text-white rounded-pill mb-3" placeholder="LEAVE BLANK FOR SYNC" value="<?=s($s['admission_number']??'')?>">

                    <label class="fs-tiny fw-black text-accent opacity-50 ms-1 mb-1 Syne">PLACEMENT CATEGORY</label>
                    <select name="class_id" class="form-select border-0 bg-white bg-opacity-10 text-white rounded-pill mb-3">
                        <option value="">-- PENDING ARCHIVAL --</option>
                        <?php foreach($classes as $c): ?><option value="<?=$c['id']?>" <?=($s['class_id']??'')==$c['id']?'selected':''?> class="text-navy"><?=s($c['class_name'])?></option><?php endforeach; ?>
                    </select>

                    <label class="fs-tiny fw-black text-accent opacity-50 ms-1 mb-1 Syne">FISCAL ENROLMENT YEAR</label>
                    <input type="number" name="year_of_admission" class="form-control border-0 bg-white bg-opacity-10 text-white rounded-pill mb-4" value="<?=$s['year_of_admission']??date('Y')?>" min="2000" max="2050">

                    <button class="btn btn-accent btn-lg w-100 rounded-pill fw-black text-navy py-3 shadow mb-3">FINALIZE RECORD</button>
                    <a href="students.php" class="btn btn-link btn-sm w-100 text-decoration-none text-white-50">DESTROY DRAFT</a>
                 </div>
             </div>
        </div>
    </div>
</form>

<?php 
/* =========================================================
   VIEW 3: THE GOLDEN DOSSIER (STUDENT PROFILE VIEW)
   ========================================================= */
elseif($action === 'view' && $student):
    // Stats retained
    $at=$db->prepare("SELECT COUNT(*) FROM attendance WHERE student_id=?"); $at->execute([$student['id']]); $totalAtt=$at->fetchColumn();
    $ap=$db->prepare("SELECT COUNT(*) FROM attendance WHERE student_id=? AND status='present'"); $ap->execute([$student['id']]); $pAtt=$ap->fetchColumn();
    $rate=$totalAtt>0 ? round($pAtt/$totalAtt*100) : 0;
    
    $recLogs=$db->prepare("SELECT * FROM attendance WHERE student_id=? ORDER BY attendance_date DESC LIMIT 5"); $recLogs->execute([$student['id']]); $recLogs=$recLogs->fetchAll();
    
    // Original Categories for Vault
    $types=['JI'=>'JOINT INDUCTION FORM','NIN'=>'NAT. IDENTITY COPY','primary_certificate'=>'PRIMARY DIPLOMA','junior_certificate'=>'JSS ARCHIVE CERT.','medical_report'=>'HEALTH AUDIT','result'=>'RESULT RECORD','others'=>'SUPPLEMENTARY DOCUMENTS'];
    $vault=[]; foreach($studentDocs as $d) $vault[$d['document_type']][]=$d;
?>

<div class="page-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
    <div class="d-flex align-items-center gap-3">
        <div class="bg-navy rounded-circle overflow-hidden shadow-sm" style="width:70px; height:70px; border:3px solid var(--accent)">
            <?php if($student['photo']): ?><img src="<?=UPLOAD_URL.$student['photo']?>" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?><div class="w-100 h-100 d-flex align-items-center justify-content-center fs-1 fw-black Syne text-white"><?=strtoupper(substr($student['full_name'],0,1))?></div><?php endif; ?>
        </div>
        <div>
            <h2 class="fw-black Syne text-navy mb-0"><?=strtoupper($student['full_name'])?></h2>
            <div class="small"><span class="badge bg-accent text-navy Syne px-3"><?=s($student['admission_number'])?></span> · <b><?=strtoupper($student['class_name'] ?? 'PENDING CLASS')?></b></div>
        </div>
    </div>
    <div class="d-flex gap-2 py-3">
        <a href="students.php?action=edit&id=<?=$student['id']?>" class="btn btn-navy rounded-pill px-4 fw-bold shadow-sm">EDIT MASTER FILE</a>
        <a href="students.php" class="btn btn-outline-secondary rounded-pill shadow-sm"><i class="bi bi-back me-1"></i></a>
    </div>
</div>

<div class="row g-4 mt-2">
    <!-- PANEL: ENTITY CARDS -->
    <div class="col-md-4">
        <!-- 1. SUMMARY -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
            <div class="p-3 bg-light px-4 border-bottom text-center Syne small fw-black opacity-50">DIGITAL MASTER INDEX</div>
            <ul class="list-group list-group-flush" style="font-size:13px">
                <li class="list-group-item d-flex justify-content-between p-3"><span>GENDER TRACE</span><b class="text-navy Syne"><?=strtoupper($student['gender'])?></b></li>
                <li class="list-group-item d-flex justify-content-between p-3"><span>AUTHENTICATED DOB</span><b class="text-navy Syne"><?=formatDate($student['date_of_birth'])?></b></li>
                <li class="list-group-item d-flex justify-content-between p-3"><span>LEGAL IDENTITY (NIN)</span><code class="fw-bold"><?=s($student['nin'])?></code></li>
                <li class="list-group-item d-flex justify-content-between p-3"><span>ADMISSION POINT</span><b class="text-navy Syne"><?=s($student['year_of_admission'])?></b></li>
                <li class="list-group-item p-3 border-top bg-light bg-opacity-50">
                    <span class="fs-tiny opacity-50 fw-black d-block mb-1">REGISTERED HOME RESIDENCE</span>
                    <span class="text-navy fw-bold" style="line-height:1.4"><?=s($student['address'])?></span>
                </li>
            </ul>
        </div>

        <!-- 2. PARENTING -->
        <div class="card border-0 bg-navy shadow-sm rounded-4 p-4 text-white mb-4 position-relative overflow-hidden shadow-lg">
             <div class="position-relative z-1">
                <h6 class="Syne fw-black text-accent border-bottom border-white border-opacity-10 pb-2 mb-3">LEGAL GUARDIANSHIP LINK</h6>
                <div class="small fw-black fs-4"><?=strtoupper($student['guardian_name'] ?: 'NO RECORDED PARENT')?></div>
                <div class="small mt-2"><i class="bi bi-phone-fill text-accent fs-4"></i> <?=s($student['guardian_phone'] ?: 'No active path.')?></div>
                <button class="btn btn-accent btn-sm rounded-pill mt-3 w-100 fw-black"><i class="bi bi-whatsapp me-2"></i>SECURE MESSENGER</button>
             </div>
             <div class="bg-pattern opacity-10" style="inset:0"></div>
        </div>

        <!-- 3. BIOMETRICS RETENTION (Detail retention requested) -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
             <div class="p-3 bg-light px-4 border-bottom d-flex justify-content-between">
                <span class="Syne small fw-black opacity-75">IDENTITY VERIFICATION</span>
                <i class="bi bi-fingerprint <?=($student['fingerprint_template']?'text-success pulse':'text-danger opacity-25')?>"></i>
             </div>
             <div class="card-body">
                <?php if($student['fingerprint_template']): ?>
                    <div class="alert bg-success bg-opacity-10 text-success border-success border-opacity-25 small fw-bold">SECURED IDENTITY · VERIFIED SCAN</div>
                    <div style="font-size:11px; line-height:2" class="opacity-75 text-navy">
                         <div>MASTER KEY: <span class="badge bg-light text-navy fw-bold shadow-sm">L1 SHA256 AES-CRYPT</span></div>
                         <div>CAPTURE PORT: <?=s($student['fp_device_model'] ?: 'BIO-GATEWAY A1')?></div>
                         <div>SERIAL REF: <?=s($student['fp_device_serial'] ?: 'BIO-SYS-SEC')?></div>
                    </div>
                <?php else: ?>
                    <div class="text-center p-3">
                         <h6 class="fw-black opacity-25">FINGERPRINT VOID</h6>
                         <a href="fingerprint.php" class="btn btn-navy btn-sm rounded-pill mt-2">COMMENCE SCAN</a>
                    </div>
                <?php endif; ?>
             </div>
        </div>
    </div>

    <!-- PANEL: DOCUMENT VAULT & HISTORY -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm rounded-5 bg-white mb-4">
             <ul class="nav nav-pills px-4 py-3 bg-light border-bottom rounded-top-5">
                 <li class="nav-item"><a class="nav-link active small fw-black" data-bs-toggle="tab" href="#vTabHistory">ARCHIVE HISTORY</a></li>
                 <li class="nav-item"><a class="nav-link small fw-black" data-bs-toggle="tab" href="#vTabDocs">DIGITAL VAULT (<?=$studentDocs ? count($studentDocs) : 0 ?>)</a></li>
                 <li class="nav-item"><a class="nav-link small fw-black text-danger" data-bs-toggle="tab" href="#vTabPresence">INTEGRITY TALLY</a></li>
             </ul>

             <div class="tab-content p-4">
                <!-- 1. ACADEMIC ARCHIVE DISPLAY -->
                <div class="tab-pane fade show active" id="vTabHistory">
                     <div class="row g-4">
                         <div class="col-12">
                             <div class="p-4 rounded-4 shadow-sm h-100 border" style="background:#f4f9ff; border:2px dashed #007bff44 !important">
                                <h6 class="text-primary fw-black border-bottom mb-3 pb-2 Syne small"><i class="bi bi-patch-check-fill me-2"></i>PRIMARY EDUCATIONAL BASE</h6>
                                <p class="h5 fw-bold text-navy mb-1"><?=s($student['primary_school_name'] ?: 'IDENTITY ARCHIVE IS EMPTY.')?></p>
                                <span class="small fw-bold text-muted opacity-75 text-uppercase">ENROLMENT POINT: <?=formatDate($student['primary_school_start'])?> · COMPLETION: <?=formatDate($student['primary_school_end'])?></span>
                             </div>
                         </div>
                         <div class="col-12">
                             <div class="p-4 rounded-4 shadow-sm h-100 border" style="background:#fffaf4; border:2px dashed #ffc10744 !important">
                                <h6 class="text-warning-dark fw-black border-bottom mb-3 pb-2 Syne small"><i class="bi bi-stars me-2"></i>JUNIOR SECONDARY TRACKING</h6>
                                <p class="h5 fw-bold text-navy mb-1"><?=s($student['junior_secondary_name'] ?: 'NO JUNIOR SECONDARY TRANSFER RECORD.')?></p>
                                <span class="small fw-bold text-muted opacity-75 text-uppercase">TRANSFER ORIGIN ADM: <?=formatDate($student['junior_secondary_start'])?> · LEAVING POINT: <?=formatDate($student['junior_secondary_end'])?></span>
                             </div>
                         </div>
                     </div>
                </div>

                <!-- 2. DOCUMENT VAULT (Multi Dropdown Content Retained) -->
                <div class="tab-pane fade" id="vTabDocs">
                     <?php if(hasRole(['director','admission_officer'])): ?>
                     <div class="card p-3 rounded-4 bg-navy shadow mb-4">
                        <label class="Syne small fw-black text-accent opacity-50 ms-2 mb-1">QUICK SECURE ARCHIVE (STRICT 250KB LIMIT)</label>
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                            <input type="hidden" name="action" value="upload_docs"><input type="hidden" name="student_id" value="<?=$student['id']?>">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-5">
                                     <select name="document_type" class="form-select border-0 shadow-sm" required>
                                         <option value="others" selected>Classify Entry...</option>
                                         <?php foreach($types as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
                                     </select>
                                </div>
                                <div class="col-md-4"><input type="file" name="documents[]" class="form-control rounded-pill border-0 shadow-sm py-2" multiple required></div>
                                <div class="col-md-3"><button class="btn btn-accent btn-sm rounded-pill fw-black w-100 shadow">ARCHIVE FILE</button></div>
                            </div>
                        </form>
                     </div>
                     <?php endif; ?>

                     <div class="list-group list-group-flush rounded-4 overflow-hidden border">
                         <?php foreach($types as $key => $title): if(!empty($vault[$key])): ?>
                            <div class="bg-light p-2 ps-3 fs-tiny fw-black text-muted tracking-wide"><?=$title?></div>
                            <?php foreach($vault[$key] as $f): 
                                $isImg = in_array(strtolower(pathinfo($f['file_path'], PATHINFO_EXTENSION)), ['jpg','png','jpeg','webp']);
                                ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="rounded-4 bg-navy text-accent d-flex align-items-center justify-content-center p-2"><i class="bi <?=$isImg?'bi-file-earmark-image':'bi-file-pdf'?> fs-5"></i></div>
                                        <div><div class="small fw-black Syne text-navy"><?=s($f['file_name'])?></div><div class="opacity-50" style="font-size:10px">VERIFIED STORAGEID #<?=s($f['id'])?> · <?=round($f['file_size']/1024)?>KB</div></div>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <a href="<?=UPLOAD_URL.$f['file_path']?>" target="_blank" class="btn btn-sm btn-light border rounded-pill shadow-sm"><i class="bi bi-box-arrow-up-right me-1"></i></a>
                                        <?php if(hasRole(['director','admission_officer'])): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('ERASE FILE FROM VAULT?')">
                                             <input type="hidden" name="csrf_token" value="<?=$_SESSION['csrf_token']?>">
                                             <input type="hidden" name="action" value="delete_doc"><input type="hidden" name="doc_id" value="<?=$f['id']?>">
                                             <input type="hidden" name="student_id" value="<?=$student['id']?>">
                                             <button class="btn btn-sm btn-light text-danger border rounded-pill"><i class="bi bi-trash-fill"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                         <?php endif; endforeach; ?>
                         <?php if(!$studentDocs): ?><div class="p-5 text-center small text-muted italic fw-black opacity-25">DIGITAL CRYPT VAULT EMPTY.</div><?php endif; ?>
                     </div>
                </div>

                <!-- 3. PRESENCE TAB RETENTION -->
                <div class="tab-pane fade" id="vTabPresence">
                     <div class="d-flex justify-content-between mb-2"><span>STATION INTEGRITY</span><strong class="Syne text-danger fs-2 fw-black"><?=$rate?>%</strong></div>
                     <div class="progress rounded-pill bg-light border" style="height:10px"><div class="progress-bar bg-danger" style="width:<?=$rate?>%"></div></div>
                     
                     <div class="mt-4 table-responsive">
                         <table class="table table-sm" style="font-size:12px">
                             <thead class="bg-navy text-white text-uppercase"><tr><th class="ps-3 py-2">Cycle Date</th><th>InBound</th><th>ExitPath</th><th class="pe-3">Identification</th></tr></thead>
                             <tbody>
                                 <?php foreach($recLogs as $l): ?>
                                 <tr><td class="ps-3"><?=formatDate($l['attendance_date'])?></td><td><?=date('H:i:A', strtotime($l['time_in']))?></td><td>—</td><td class="text-uppercase fw-bold"><?=str_replace('_',' ',$l['method'])?></td></tr>
                                 <?php endforeach; ?>
                                 <?php if(!$recLogs): ?><tr><td colspan="4" class="p-3 text-center small fw-bold opacity-25">NULL LOGS DISCOVERED</td></tr><?php endif; ?>
                             </tbody>
                         </table>
                     </div>
                </div>
             </div>
        </div>
    </div>
</div>

<?php endif; ?>

<style>
/* Refined Style Components - Single File UI Design Layer */
:root { --accent-dark: #b58d34; --navy-soft: #0a2342; --navy: #071829; --warning-dark: #451a03; }
body { background: #f0f2f5; font-family: 'DM Sans', sans-serif; letter-spacing: -0.01em; }
.Syne { font-family: 'Syne', sans-serif !important; }
.fw-black { font-weight: 900 !important; }
.bg-navy { background-color: var(--navy) !important; }
.text-navy { color: var(--navy) !important; }
.badge-present { background: rgba(16, 185, 129, 0.1); color: #10b981; border: 1px solid #10b98144; font-weight: 800; font-size: 11px; border-radius: 12px; }
.fs-tiny { font-size: 10px !important; letter-spacing: 0.12em; text-transform: uppercase; font-weight: 900; }
.form-control, .form-select { border-radius: 12px; font-size: 14.5px; border: 1.5px solid #f1f5f9; padding: 10px 18px; color: var(--navy-soft); font-weight: 600; }
.form-control:focus { box-shadow: 0 0 0 3px #f4be3822; border-color: var(--accent); }
.card { border-radius: 20px; transition: 0.3s; }
.btn-navy { background: var(--navy); color: white; border: none; font-family: 'Syne'; letter-spacing: 1px; font-weight: 800; }
.btn-accent { background: var(--accent); color: var(--navy); border: none; font-family: 'Syne'; letter-spacing: 0.5px; }
.btn-accent:hover { transform: translateY(-2px); filter: brightness(1.1); color: var(--navy); }
.data-table thead th { border-bottom: none !important; font-family: 'Syne'; }
.pulse { animation: bio-pulse 2s infinite; }
@keyframes bio-pulse { 0% { transform: scale(0.9); opacity: 0.7; } 50% { transform: scale(1.1); opacity: 1; } 100% { transform: scale(0.9); opacity: 0.7; } }
.text-warning-dark { color: var(--warning-dark); }
.rounded-5 { border-radius: 24px !important; }
.fs-tiny.tracking-wide { letter-spacing: 0.2em; border-radius: 8px 8px 0 0; }
.form-label.fw-black { margin-left: 12px; margin-bottom: 4px; display: inline-block; color: var(--navy); opacity: 0.6; }
</style>

<script>
// Logic retention for Delete prompts
function confirmDelete(url, label) {
    if (confirm('Critical Record Removal Warning: ' + label)) {
        window.location.href = url;
    }
}
// Logic for File Limit visualization on Frontend (UX Enhancement)
document.querySelectorAll('input[type="file"]').forEach(fileInput => {
    fileInput.addEventListener('change', function() {
        for (const file of this.files) {
            if (file.size > (250 * 1024)) {
                alert('REJECTED: ' + file.name + ' exceeds 250KB restriction.');
                this.value = ""; return;
            }
        }
    });
});
</script>

<?php include 'layout_footer.php'; ?>