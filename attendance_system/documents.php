<?php
// documents.php
require_once 'config.php';
requireRole(['director','admission_officer']);
$pageTitle = 'Document Manager';
$db = getDB();

$search  = trim($_GET['search'] ?? '');
$docType = $_GET['type'] ?? '';
$where   = 'WHERE 1=1';
if ($search)  $where .= " AND (s.full_name LIKE " . $db->quote("%$search%") . " OR s.admission_number LIKE " . $db->quote("%$search%") . ")";
if ($docType) $where .= " AND d.document_type = " . $db->quote($docType);

$docs = $db->query("
    SELECT d.*, s.full_name, s.admission_number, c.class_name, u.full_name as uploader_name
    FROM student_documents d
    JOIN students s ON s.id=d.student_id
    LEFT JOIN classes c ON c.id=s.class_id
    LEFT JOIN users u ON u.id=d.uploaded_by
    $where ORDER BY d.uploaded_at DESC LIMIT 200
")->fetchAll();

$docTypes = ['JI'=>'JI','NIN'=>'NIN','primary_certificate'=>'Primary Cert','junior_certificate'=>'Junior Cert','medical_report'=>'Medical Report','result'=>'Result','others'=>'Others'];
$totalDocs = $db->query("SELECT COUNT(*) FROM student_documents")->fetchColumn();

include 'layout_header.php';
?>
<div class="page-header">
    <div><div class="page-header-title">Document Manager</div><div class="page-header-sub"><?= $totalDocs ?> total documents</div></div>
</div>
<div class="card mb-3">
    <div class="card-body" style="padding:14px 20px;">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5"><label class="form-label">Search Student</label><input type="text" name="search" class="form-control" placeholder="Name or Admission No" value="<?= htmlspecialchars($search) ?>"></div>
            <div class="col-md-3"><label class="form-label">Document Type</label>
                <select name="type" class="form-select"><option value="">All Types</option><?php foreach ($docTypes as $v=>$l): ?><option value="<?= $v ?>" <?= $docType===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Filter</button></div>
            <div class="col-md-2"><a href="documents.php" class="btn btn-outline-secondary w-100">Reset</a></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table data-table mb-0">
            <thead><tr><th>Student</th><th>Class</th><th>Doc Type</th><th>File Name</th><th>Size</th><th>Uploaded</th><th>By</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($docs as $d):
                $isImg = in_array(strtolower(pathinfo($d['file_name'],PATHINFO_EXTENSION)),['jpg','jpeg','png','gif','webp']);
            ?>
            <tr>
                <td><div style="font-weight:500;font-size:0.85rem;"><?= htmlspecialchars($d['full_name']) ?></div><div style="font-size:0.75rem;color:var(--muted);"><?= htmlspecialchars($d['admission_number']) ?></div></td>
                <td style="font-size:0.82rem;"><?= htmlspecialchars($d['class_name']??'—') ?></td>
                <td><span style="font-size:0.75rem;background:var(--bg);padding:3px 8px;border-radius:6px;"><?= $docTypes[$d['document_type']]??$d['document_type'] ?></span></td>
                <td style="font-size:0.82rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($d['file_name']) ?></td>
                <td style="font-size:0.82rem;"><?= round($d['file_size']/1024,1) ?> KB</td>
                <td style="font-size:0.82rem;color:var(--muted);"><?= formatDate($d['uploaded_at']) ?></td>
                <td style="font-size:0.82rem;"><?= htmlspecialchars($d['uploader_name']??'—') ?></td>
                <td>
                    <a href="<?= UPLOAD_URL . htmlspecialchars($d['file_path']) ?>" target="_blank" class="action-btn view" title="View"><i class="bi bi-eye"></i></a>
                    <a href="<?= UPLOAD_URL . htmlspecialchars($d['file_path']) ?>" download class="action-btn edit ms-1" title="Download"><i class="bi bi-download"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($docs)): ?><tr><td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-folder2 display-6 d-block mb-2"></i>No documents found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include 'layout_footer.php'; ?>
