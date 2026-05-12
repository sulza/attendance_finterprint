<?php
// config.php - Database & App Configuration

// 1. Database Credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'biometric_attendance');

// 2. App Metadata
define('APP_NAME', 'BioAttend Pro');
define('APP_SUBTITLE', 'Biometric Attendance System');
define('SCHOOL_NAME', 'Excellence Secondary School');

// 3. Upload Configuration
// Use DIRECTORY_SEPARATOR for Cross-Platform compatibility (Windows/Linux)
define('UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
define('UPLOAD_URL', 'uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

$allowedMimeTypes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

// 4. Session Hardening (Run before session_start)
// Standard alternative:
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    if (isset($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// 5. Create upload directory + Add index.php to prevent folder snooping
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
    file_put_contents(UPLOAD_DIR . 'index.php', '<?php http_response_code(403); ?>');
}

// 6. Improved PDO Connection
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log the error to a file, don't show to user
            error_log($e->getMessage()); 
            // In AJAX/API context:
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Database connection failed. Please try again later.']));
        }
    }
    return $pdo;
}

// --- Auth Helpers ---

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit;
    }
}

function hasRole(array $roles): bool {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles);
}

function requireRole(array $roles): void {
    requireLogin();
    if (!hasRole($roles)) {
        http_response_code(403);
        die('Access Denied: Insufficient permissions.');
    }
}

function currentUser(): array {
    return [
        'id'        => $_SESSION['user_id'] ?? null,
        'name'      => $_SESSION['user_name'] ?? '',
        'username'  => $_SESSION['username'] ?? '',
        'role'      => $_SESSION['role'] ?? '',
        'class_id'  => $_SESSION['class_id'] ?? null,
    ];
}

// --- Utility Helpers ---

/**
 * Enhanced sanitization for display
 */
function s(?string $input): string {
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * FIXED: Uses prepared statements to prevent potential issues
 */
function generateAdmissionNumber(): string {
    $year = date('Y');
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM students WHERE year_of_admission = ?");
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    $seq = str_pad(($row['cnt'] + 1), 4, '0', STR_PAD_LEFT);
    return "ESS/{$year}/{$seq}";
}

/**
 * Improved logic for tokens
 */
function generateSecureToken(): string {
    return bin2hex(random_bytes(32));
}

function formatDate(?string $date): string {
    return $date ? date('M d, Y', strtotime($date)) : '-';
}

function jsonResponse(array $data, int $code = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code($code);
    }
    echo json_encode($data);
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Role Helpers (PHP 8 match)
function roleLabel(string $role): string {
    return match($role) {
        'director'          => 'Director',
        'admission_officer' => 'Admission Officer',
        'class_master'      => 'Class Master',
        'admin_officer'     => 'Admin Officer',
        default             => ucfirst($role),
    };
}