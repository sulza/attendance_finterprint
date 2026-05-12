<?php
// layout.php - Shared Layout (header + sidebar)
requireLogin();
$currentUser = currentUser();
$flash = getFlash();

// Get DB for sidebar stats
$db = getDB();
$todayCount = $db->query("SELECT COUNT(*) FROM attendance WHERE attendance_date = CURDATE() AND status='present'")->fetchColumn();
$totalStudents = $db->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();

// Get classes for class master
$userClasses = [];
if ($currentUser['role'] === 'class_master' && $currentUser['class_id']) {
    $stmt = $db->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$currentUser['class_id']]);
    $userClasses = $stmt->fetchAll();
}

$role = $currentUser['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?? APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css" rel="stylesheet">
<style>
:root {
    --primary: #0a2342;
    --primary-light: #0f2d52;
    --accent: #e8b84b;
    --accent2: #3ecf8e;
    --danger: #e74c5e;
    --sidebar-w: 260px;
    --header-h: 64px;
    --bg: #f0f4f9;
    --card: #ffffff;
    --text: #1a2940;
    --muted: #6b7a8d;
    --border: #e2e8f0;
    --shadow: 0 1px 3px rgba(10,35,66,0.08), 0 4px 12px rgba(10,35,66,0.04);
    --shadow-md: 0 4px 16px rgba(10,35,66,0.12), 0 2px 6px rgba(10,35,66,0.06);
    --radius: 12px;
    --radius-sm: 8px;
}
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DM Sans',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; }

/* ===== SIDEBAR ===== */
.sidebar {
    position: fixed;
    left: 0; top: 0; bottom: 0;
    width: var(--sidebar-w);
    background: var(--primary);
    z-index: 100;
    display: flex; flex-direction: column;
    transition: transform 0.3s ease;
    overflow-y: auto;
}
.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
.sidebar-logo {
    padding: 20px 24px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    display: flex; align-items: center; gap: 12px;
    text-decoration: none;
}
.sidebar-logo-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--accent), #c9962e);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: var(--primary);
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(232,184,75,0.3);
}
.sidebar-logo-text .name {
    font-family: 'Syne', sans-serif;
    font-weight: 700; font-size: 1rem;
    color: #fff; line-height: 1.2;
}
.sidebar-logo-text .sub {
    font-size: 0.7rem; color: rgba(255,255,255,0.4);
    line-height: 1;
}
.sidebar-user {
    padding: 16px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
    display: flex; align-items: center; gap: 10px;
}
.user-avatar {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, var(--accent2), #2aaa74);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; color: #fff; font-weight: 700;
    flex-shrink: 0;
}
.user-info .uname { color: #fff; font-size: 0.88rem; font-weight: 500; }
.user-info .urole {
    font-size: 0.72rem;
    color: var(--accent);
    font-weight: 500;
}
.sidebar-stats {
    padding: 12px 24px;
    display: flex; gap: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.stat-pill {
    flex: 1;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: 8px;
    padding: 8px 10px;
    text-align: center;
}
.stat-pill .num {
    font-family: 'Syne', sans-serif;
    font-size: 1.1rem; font-weight: 700;
    color: var(--accent); display: block;
}
.stat-pill .lbl {
    font-size: 0.65rem; color: rgba(255,255,255,0.4);
    text-transform: uppercase; letter-spacing: 0.5px;
}
.nav-section {
    padding: 16px 24px 4px;
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.25);
}
.nav-item { padding: 2px 12px; }
.nav-link {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    color: rgba(255,255,255,0.55);
    border-radius: 10px;
    text-decoration: none;
    font-size: 0.88rem;
    font-weight: 400;
    transition: all 0.2s;
    position: relative;
}
.nav-link:hover {
    color: #fff;
    background: rgba(255,255,255,0.06);
}
.nav-link.active {
    color: #fff;
    background: rgba(232,184,75,0.12);
    font-weight: 500;
}
.nav-link.active::before {
    content: '';
    position: absolute;
    left: 0; top: 4px; bottom: 4px;
    width: 3px;
    background: var(--accent);
    border-radius: 0 3px 3px 0;
    margin-left: -2px;
}
.nav-link i { font-size: 1rem; width: 20px; text-align: center; }
.nav-badge {
    margin-left: auto;
    background: rgba(232,184,75,0.2);
    color: var(--accent);
    font-size: 0.7rem;
    padding: 2px 7px;
    border-radius: 20px;
    font-weight: 600;
}
.sidebar-footer {
    margin-top: auto;
    padding: 16px 24px;
    border-top: 1px solid rgba(255,255,255,0.06);
}
.logout-btn {
    display: flex; align-items: center; gap: 10px;
    color: rgba(255,255,255,0.45);
    text-decoration: none;
    font-size: 0.88rem;
    padding: 10px 14px;
    border-radius: 10px;
    transition: all 0.2s;
}
.logout-btn:hover { color: var(--danger); background: rgba(231,76,94,0.08); }

/* ===== MAIN LAYOUT ===== */
.main-wrap {
    margin-left: var(--sidebar-w);
    min-height: 100vh;
    display: flex; flex-direction: column;
}
.topbar {
    height: var(--header-h);
    background: var(--card);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center;
    padding: 0 28px;
    position: sticky; top: 0; z-index: 50;
    gap: 16px;
}
.topbar-hamburger {
    display: none;
    background: none; border: none;
    font-size: 1.3rem; color: var(--text);
    cursor: pointer; padding: 4px;
}
.topbar-title {
    font-family: 'Syne', sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--text);
    flex: 1;
}
.topbar-title span { color: var(--muted); font-weight: 400; font-size: 0.85rem; }
.topbar-date {
    font-size: 0.82rem; color: var(--muted);
    background: var(--bg);
    padding: 6px 14px;
    border-radius: 8px;
    display: flex; align-items: center; gap: 6px;
}
.topbar-date i { color: var(--accent); }
.main-content { padding: 28px; flex: 1; }

/* ===== CARDS ===== */
.card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
}
.card-header {
    padding: 18px 24px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    background: transparent;
}
.card-header-title {
    font-family: 'Syne', sans-serif;
    font-size: 1rem; font-weight: 700;
    color: var(--text);
    display: flex; align-items: center; gap: 10px;
}
.card-header-title i { color: var(--accent); }
.card-body { padding: 24px; }

/* ===== STAT CARDS ===== */
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow);
    position: relative;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-card::after {
    content: '';
    position: absolute;
    top: 0; right: 0;
    width: 80px; height: 80px;
    border-radius: 0 0 0 80px;
    opacity: 0.06;
}
.stat-card.blue::after  { background: #2563eb; }
.stat-card.green::after { background: #10b981; }
.stat-card.amber::after { background: #f59e0b; }
.stat-card.red::after   { background: #ef4444; }
.stat-card.purple::after { background: #8b5cf6; }
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    margin-bottom: 16px;
}
.stat-icon.blue   { background:#eff6ff; color:#2563eb; }
.stat-icon.green  { background:#f0fdf4; color:#10b981; }
.stat-icon.amber  { background:#fffbeb; color:#f59e0b; }
.stat-icon.red    { background:#fff1f2; color:#ef4444; }
.stat-icon.purple { background:#f5f3ff; color:#8b5cf6; }
.stat-value {
    font-family: 'Syne', sans-serif;
    font-size: 2.2rem; font-weight: 800;
    color: var(--text); line-height: 1;
}
.stat-label { font-size: 0.82rem; color: var(--muted); margin-top: 4px; }
.stat-trend {
    margin-top: 12px;
    font-size: 0.78rem;
    display: flex; align-items: center; gap: 4px;
}
.stat-trend.up { color: #10b981; }
.stat-trend.down { color: #ef4444; }

/* ===== BADGES ===== */
.badge-director   { background: #dbeafe; color: #1d4ed8; }
.badge-admission  { background: #dcfce7; color: #15803d; }
.badge-class      { background: #fef3c7; color: #b45309; }
.badge-admin      { background: #ede9fe; color: #7c3aed; }
.badge-present    { background: #dcfce7; color: #15803d; }
.badge-absent     { background: #fee2e2; color: #dc2626; }
.badge-late       { background: #fef3c7; color: #d97706; }
.badge-excused    { background: #dbeafe; color: #1d4ed8; }

/* ===== TABLES ===== */
.table { font-size: 0.88rem; }
.table th {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.6px;
    text-transform: uppercase;
    color: var(--muted);
    background: var(--bg);
    border-bottom: 1px solid var(--border);
    padding: 10px 16px;
}
.table td { padding: 13px 16px; vertical-align: middle; }
.table tbody tr:hover { background: #f8fafc; }

/* ===== FORMS ===== */
.form-label { font-size: 0.82rem; font-weight: 500; color: #555; margin-bottom: 5px; }
.form-control, .form-select {
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: 0.9rem;
    padding: 9px 12px;
    transition: all 0.2s;
}
.form-control:focus, .form-select:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(10,35,66,0.06);
}

/* ===== BUTTONS ===== */
.btn-primary {
    background: var(--primary);
    border-color: var(--primary);
    font-weight: 500;
    border-radius: 8px;
}
.btn-primary:hover { background: #0f2d52; border-color: #0f2d52; }
.btn-accent {
    background: var(--accent);
    border-color: var(--accent);
    color: var(--primary);
    font-weight: 600;
    border-radius: 8px;
}
.btn-accent:hover { background: #d4a53e; color: var(--primary); }
.btn-sm { padding: 5px 12px; font-size: 0.8rem; }
.action-btn {
    width: 32px; height: 32px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    transition: all 0.15s;
    text-decoration: none;
    font-size: 0.85rem;
}
.action-btn.edit   { background:#eff6ff; color:#2563eb; }
.action-btn.delete { background:#fff1f2; color:#ef4444; }
.action-btn.view   { background:#f0fdf4; color:#10b981; }
.action-btn:hover  { filter: brightness(0.9); }

/* ===== MODALS ===== */
.modal-header {
    background: var(--primary);
    color: #fff;
    border-radius: var(--radius) var(--radius) 0 0;
    padding: 18px 24px;
}
.modal-title { font-family:'Syne',sans-serif; font-weight:700; }
.btn-close-white { filter: invert(1) brightness(2); }
.modal-footer { background: #f8fafc; border-top: 1px solid var(--border); }

/* ===== PAGE HEADER ===== */
.page-header {
    margin-bottom: 24px;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 12px;
}
.page-header-title {
    font-family: 'Syne', sans-serif;
    font-size: 1.5rem; font-weight: 800;
    color: var(--text);
}
.page-header-sub { font-size: 0.85rem; color: var(--muted); margin-top: 2px; }

/* ===== FINGERPRINT ===== */
.fingerprint-scan {
    width: 140px; height: 140px;
    border: 2px dashed var(--border);
    border-radius: 50%;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    cursor: pointer;
    transition: all 0.3s;
    margin: 0 auto;
    position: relative;
    overflow: hidden;
}
.fingerprint-scan:hover { border-color: var(--accent); }
.fingerprint-scan.scanning {
    border-color: var(--accent2);
    animation: scanPulse 1.5s infinite;
}
.fingerprint-scan.success { border-color: var(--accent2); border-style: solid; }
.fingerprint-scan.success i { color: var(--accent2); }
@keyframes scanPulse {
    0%,100% { box-shadow: 0 0 0 0 rgba(62,207,142,0.2); }
    50% { box-shadow: 0 0 0 20px rgba(62,207,142,0); }
}
.scan-ripple {
    position: absolute; inset: 0;
    background: radial-gradient(circle, rgba(62,207,142,0.15) 0%, transparent 70%);
    display: none;
}
.fingerprint-scan.scanning .scan-ripple { display: block; animation: ripple 1.5s infinite; }
@keyframes ripple { 0% { transform: scale(0); opacity: 1; } 100% { transform: scale(2); opacity: 0; } }

/* ===== RESPONSIVE ===== */
@media (max-width: 992px) {
    .sidebar { transform: translateX(-100%); }
    .sidebar.open { transform: translateX(0); }
    .main-wrap { margin-left: 0; }
    .topbar-hamburger { display: block; }
    .sidebar-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.4);
        z-index: 99;
    }
    .sidebar-overlay.active { display: block; }
}
@media (max-width: 576px) {
    .main-content { padding: 16px; }
    .stat-value { font-size: 1.8rem; }
}

/* DataTables override */
div.dataTables_wrapper div.dataTables_paginate .paginate_button.current {
    background: var(--primary) !important;
    border-color: var(--primary) !important;
    color: #fff !important;
    border-radius: 6px;
}
div.dataTables_wrapper div.dataTables_filter input {
    border: 1.5px solid var(--border);
    border-radius: 8px;
    padding: 6px 12px;
}
</style>
</head>
<body>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<nav class="sidebar" id="sidebar">
    <a class="sidebar-logo" href="dashboard.php">
        <div class="sidebar-logo-icon"><i class="bi bi-fingerprint"></i></div>
        <div class="sidebar-logo-text">
            <div class="name"><?= APP_NAME ?></div>
            <div class="sub">Attendance System</div>
        </div>
    </a>
    <div class="sidebar-user">
        <div class="user-avatar"><?= strtoupper(substr($currentUser['name'], 0, 1)) ?></div>
        <div class="user-info">
            <div class="uname"><?= htmlspecialchars($currentUser['name']) ?></div>
            <div class="urole"><?= roleLabel($currentUser['role']) ?></div>
        </div>
    </div>
    <div class="sidebar-stats">
        <div class="stat-pill">
            <span class="num"><?= number_format($totalStudents) ?></span>
            <span class="lbl">Students</span>
        </div>
        <div class="stat-pill">
            <span class="num"><?= number_format($todayCount) ?></span>
            <span class="lbl">Present</span>
        </div>
    </div>

    <!-- Main Navigation -->
    <div class="nav-section">Main</div>
    <div class="nav-item">
        <a href="dashboard.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='dashboard.php'?'active':'') ?>">
            <i class="bi bi-grid-1x2"></i> Dashboard
        </a>
    </div>

    <?php if (hasRole(['director','admission_officer'])): ?>
    <div class="nav-section">Students</div>
    <div class="nav-item">
        <a href="students.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='students.php'?'active':'') ?>">
            <i class="bi bi-people"></i> All Students
        </a>
    </div>
    <div class="nav-item">
        <a href="students.php?action=register" class="nav-link <?= (isset($_GET['action'])&&$_GET['action']=='register'&&basename($_SERVER['PHP_SELF'])=='students.php'?'active':'') ?>">
            <i class="bi bi-person-plus"></i> Register Student
        </a>
    </div>
    <div class="nav-item">
        <a href="bulk_import.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='bulk_import.php'?'active':'') ?>">
            <i class="bi bi-file-earmark-spreadsheet"></i> Bulk Import
        </a>
    </div>
    <div class="nav-item">
        <a href="fingerprint.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='fingerprint.php'?'active':'') ?>">
            <i class="bi bi-fingerprint"></i> Fingerprint Enroll
        </a>
    </div>
    <div class="nav-item">
        <a href="documents.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='documents.php'?'active':'') ?>">
            <i class="bi bi-folder2-open"></i> Documents
        </a>
    </div>
    <?php elseif (hasRole(['admin_officer'])): ?>
    <div class="nav-section">Students</div>
    <div class="nav-item">
        <a href="students.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='students.php'?'active':'') ?>">
            <i class="bi bi-people"></i> Students
        </a>
    </div>
    <?php elseif (hasRole(['class_master'])): ?>
    <div class="nav-section">Students</div>
    <div class="nav-item">
        <a href="students.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='students.php'?'active':'') ?>">
            <i class="bi bi-people"></i> My Class Students
        </a>
    </div>
    <?php endif; ?>

    <!-- Attendance -->
    <div class="nav-section">Attendance</div>
    <div class="nav-item">
        <a href="attendance.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='attendance.php'?'active':'') ?>">
            <i class="bi bi-calendar-check"></i> Mark Attendance
        </a>
    </div>
    <div class="nav-item">
        <a href="attendance.php?view=history" class="nav-link <?= (isset($_GET['view'])&&$_GET['view']=='history'?'active':'') ?>">
            <i class="bi bi-clock-history"></i> History
        </a>
    </div>

    <?php if (hasRole(['director'])): ?>
    <!-- Management -->
    <div class="nav-section">Management</div>
    <div class="nav-item">
        <a href="users.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='users.php'?'active':'') ?>">
            <i class="bi bi-shield-person"></i> Users
        </a>
    </div>
    <div class="nav-item">
        <a href="classes.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='classes.php'?'active':'') ?>">
            <i class="bi bi-mortarboard"></i> Classes
        </a>
    </div>
    <div class="nav-item">
        <a href="reports.php" class="nav-link <?= (basename($_SERVER['PHP_SELF'])=='reports.php'?'active':'') ?>">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>
    </div>
    <?php endif; ?>

    <div class="sidebar-footer">
        <a href="profile.php" class="logout-btn" style="color:rgba(255,255,255,0.45);margin-bottom:4px;">
            <i class="bi bi-person-gear"></i> My Profile
        </a>
        <a href="logout.php" class="logout-btn"><i class="bi bi-box-arrow-left"></i> Sign Out</a>
    </div>
</nav>

<!-- MAIN WRAP -->
<div class="main-wrap">
    <div class="topbar">
        <button class="topbar-hamburger" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
        <div class="topbar-title">
            <?= $pageTitle ?? APP_NAME ?>
            <?php if (isset($pageSubtitle)): ?><br><span><?= $pageSubtitle ?></span><?php endif; ?>
        </div>
        <div class="topbar-date">
            <i class="bi bi-calendar3"></i>
            <?= date('l, F j, Y') ?>
        </div>
    </div>

    <div class="main-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'error' ? 'danger' : $flash['type']) ?> alert-dismissible fade show" role="alert" style="border-radius:10px;font-size:0.9rem;">
        <i class="bi bi-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?>-fill me-2"></i>
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
