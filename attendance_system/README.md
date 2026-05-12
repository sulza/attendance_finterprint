# BioAttend Pro — Student Biometric Attendance System
### Complete Setup & Developer Reference

---

## FILE STRUCTURE

```
biometric_attendance/
├── .htaccess                ← App security rules
├── config.php               ← DB config, helpers, session
├── database.sql             ← Full schema + seed data
├── index.php                ← Login page
├── logout.php               ← Session destroy
├── dashboard.php            ← Main dashboard + charts
├── students.php             ← Register, list, profile, documents
├── attendance.php           ← Mark attendance (hardware + ID)
├── fingerprint.php          ← Biometric enrolment station
├── bulk_import.php          ← CSV bulk student import
├── users.php                ← User management (Director)
├── classes.php              ← Class management (Director)
├── reports.php              ← Reports + CSV export
├── documents.php            ← Document manager
├── profile.php              ← User profile + password change
├── reset_password.php       ← Emergency password reset (delete after use)
├── layout_header.php        ← Shared sidebar + topbar
├── layout_footer.php        ← Shared scripts footer
└── uploads/
    └── .htaccess            ← Blocks PHP execution in uploads
```

---

## QUICK SETUP (XAMPP)

### 1. Import Database
- Open `http://localhost/phpmyadmin`
- Create database: `biometric_attendance`
- Click the database → SQL tab → paste `database.sql` → Go

### 2. Copy Files
```
C:\xampp\htdocs\biometric_attendance\
```

### 3. Configure (if needed)
Edit `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');          // your MySQL password
define('DB_NAME', 'biometric_attendance');
```

### 4. Open in Browser
```
http://localhost/biometric_attendance/
```

---

## DEFAULT LOGIN CREDENTIALS

| Role              | Username     | Password |
|-------------------|--------------|----------|
| Director          | director     | password |
| Admission Officer | admission    | password |
| Class Master      | classmaster  | password |
| Admin Officer     | adminofficer | password |

> **Change all passwords immediately after first login.**

---

## DATABASE — KEY COLUMNS ADDED

The `students` table now includes full school history:

```sql
-- Primary school
primary_school_name      VARCHAR(200)   -- "Govt Primary School, Kano"
primary_school_start     DATE
primary_school_end       DATE

-- Junior secondary
junior_secondary_name    VARCHAR(200)   -- "Govt JSS, Abuja"
junior_secondary_start   DATE
junior_secondary_end     DATE

-- Biometric hardware fields
fingerprint_template     TEXT           -- raw base64 ISO/WSQ template
fingerprint_hash         VARCHAR(64)    -- SHA-256 of raw template (fast lookup)
fp_device_model          VARCHAR(100)   -- "Futronic FS80", "Mantra MFS100" etc.
fp_device_serial         VARCHAR(100)   -- hardware serial number
fp_enrolled_at           TIMESTAMP      -- when enrolment happened
```

### Migration for existing installations
Run this in phpMyAdmin SQL tab if upgrading from an older version:
```sql
ALTER TABLE students ADD COLUMN primary_school_name    VARCHAR(200) DEFAULT NULL AFTER guardian_phone;
ALTER TABLE students ADD COLUMN junior_secondary_name  VARCHAR(200) DEFAULT NULL AFTER primary_school_end;
ALTER TABLE students MODIFY COLUMN fingerprint_template TEXT DEFAULT NULL;
ALTER TABLE students ADD COLUMN fp_device_model        VARCHAR(100) DEFAULT NULL AFTER fingerprint_hash;
ALTER TABLE students ADD COLUMN fp_device_serial       VARCHAR(100) DEFAULT NULL AFTER fp_device_model;
ALTER TABLE students ADD COLUMN fp_enrolled_at         TIMESTAMP NULL DEFAULT NULL AFTER fp_device_serial;
```

---

## REAL FINGERPRINT HARDWARE SETUP

`fingerprint.php` and `attendance.php` both use **BioCapture** — a JavaScript auto-detection
engine that tries each supported SDK in order and uses the first one that responds.

### Detection Priority Order

```
1. Futronic       → WebSocket   ws://localhost:8765
2. Mantra MFS100  → HTTP REST   http://localhost:11100
3. DigitalPersona → HTTP bridge http://localhost:15895
4. SecuGen        → JS plugin   window.SGIBIOSDK
5. Custom/Other   → HTTP REST   http://localhost:9000  (uncomment in code)
```

> **WebAuthn / FIDO2 is intentionally excluded.**
> WebAuthn is a browser login standard — it triggers password manager dialogs
> and requires HTTPS. It is not a fingerprint scanner protocol and has no
> place in an attendance capture flow.

The system probes all endpoints simultaneously on page load.
The green dot on the enrolment page shows which device was found.

---

### FUTRONIC FS80 / FS88 / FS90

**What you need:** Futronic WebSocket bridge running locally.

1. Install Futronic driver from the CD/download
2. Download or build the WebSocket bridge:
   - Official: `FutronicWebSocketServer.exe` (available from Futronic partner portal)
   - Open-source bridge: `https://github.com/futronic-dev/ws-bridge`
3. Run the bridge — it listens on `ws://localhost:8765`
4. Plug in the scanner → open the enrolment page → green dot appears

**Bridge message protocol the system uses:**
```json
// Sent by browser:
{ "cmd": "capture", "timeout": 15000 }

// Expected response on success:
{ "status": "ok", "template": "<base64 ISO template>", "device_model": "FS80", "device_serial": "FT0001" }

// Expected response on error:
{ "status": "error", "message": "No finger detected" }
```

---

### MANTRA MFS100 / MFS110

**What you need:** Mantra RD Service installed (free download from Mantra website).

1. Download **MFS100 RD Service** from `https://www.mantratec.com/download`
2. Install it — it auto-starts as a Windows service on port `11100`
3. Plug in the MFS100 → the system detects it automatically

**REST endpoints the system calls:**
```
GET  http://localhost:11100/mfs100/info     → device info
POST http://localhost:11100/mfs100/capture  → trigger capture
     Body: { "timeout": 15000, "quality": 70 }
     Response: { "ErrorCode": 0, "BitmapData": "<base64>", "DeviceName": "MFS100" }
```

---

### DIGITALPERSONA U.are.U 4500 / 4000B

**Option A — HTTP Bridge (recommended, all browsers):**
1. Install DigitalPersona driver + SDK
2. Run the included `DPHttpBridge.exe` — listens on port `15895`
3. System auto-detects it

**Option B — Browser Plugin (legacy, IE/Edge only):**
1. Install the DigitalPersona web component
2. The `window.FingerprintSdkTest` object is injected automatically

**HTTP bridge endpoints:**
```
GET  http://localhost:15895/dp/status       → device status
POST http://localhost:15895/dp/capture      → trigger capture
     Body: { "format": "ISO", "quality": 75, "timeout": 15000 }
     Response: { "Success": true, "Template": "<base64 ISO>", "DeviceName": "U.are.U 4500" }
```

---

### SECUGEN HAMSTER PRO / HAMSTER IV

1. Install SecuGen driver + SGIBIOSDK web plugin
2. The `window.SGIBIOSDK` object is automatically available on pages
3. System detects it and calls `SGIBIOSDK.GetBMPImage(callback)`

---

### ADDING A NEW/CUSTOM SCANNER

Add a new entry to the `SDKS` array in `fingerprint.php` and `attendance.php`:

```javascript
{
    name: "MyScanner",

    // Return true if device/service is available
    async probe() {
        try {
            const r = await fetch("http://localhost:YOUR_PORT/status",
                                  { signal: AbortSignal.timeout(2000) });
            return r.ok;
        } catch(e) { return false; }
    },

    // Perform the actual capture — MUST return { template, model, serial }
    // template = base64-encoded ISO 19794-2 or WSQ or BMP fingerprint data
    async capture() {
        const r = await fetch("http://localhost:YOUR_PORT/capture", {
            method: "POST",
            body: JSON.stringify({ timeout: 15000 }),
            signal: AbortSignal.timeout(18000),
        });
        const d = await r.json();
        return {
            template: d.templateBase64,   // base64 string
            model:    d.deviceName,
            serial:   d.serialNumber,
        };
    }
}
```

Then add it to the PHP save handler in `fingerprint.php` — no changes needed there,
it already accepts any base64 template and stores the SHA-256 hash.

---

## HOW MATCHING WORKS

```
Enrolment:
  Scanner → raw template (base64) → PHP → SHA-256 hash stored in fingerprint_hash

Attendance:
  Scanner → raw template (base64) → PHP → SHA-256 hash → SELECT WHERE fingerprint_hash = ? → student found
```

This is a **deterministic hash match** — it works because the same physical
template produces the same bytes each time it is read from the database.

For **1:N fuzzy matching** (where scanner returns a new live scan that must
be compared against all stored templates), you need the SDK's server-side
matcher (e.g. DigitalPersona DPFJ, Suprema BioStar API, Neurotechnology MegaMatcher).
In that case, the SDK returns the matched student ID directly — map it to the `students` table.

---

## ROLE PERMISSIONS

| Feature                 | Director | Admission | Class Master | Admin |
|-------------------------|----------|-----------|--------------|-------|
| Register Students       | ✅       | ✅        | ❌           | ❌    |
| Upload Documents        | ✅       | ✅        | ❌           | ❌    |
| Fingerprint Enrolment   | ✅       | ✅        | ❌           | ❌    |
| Bulk Import             | ✅       | ✅        | ❌           | ❌    |
| View All Students       | ✅       | ✅        | Own class    | ✅    |
| Mark Attendance         | ✅       | ❌        | ✅           | ✅    |
| View Attendance History | ✅       | ❌        | ✅           | ✅    |
| Manage Users            | ✅       | ❌        | ❌           | ❌    |
| Manage Classes          | ✅       | ❌        | ❌           | ❌    |
| Reports + CSV Export    | ✅       | ❌        | ❌           | ❌    |

---

## STUDENT REGISTRATION FIELDS

**Personal:**
Full Name, NIN, Date of Birth, Gender, Phone, Address

**Guardian:**
Guardian Name, Guardian Phone

**Primary School History:**
- Name of Primary School ← NEW
- Start Date, End/Graduation Date

**Junior Secondary History:**
- Name of Junior Secondary School ← NEW
- Start Date, End/Graduation Date

**Admission:**
Admission Number (auto-gen), Year of Admission, Class

**Biometric:**
Auto-enrolled after registration via Fingerprint Enrolment page

---

## SECURITY CHECKLIST

- [x] Bcrypt password hashing (cost factor 10)
- [x] PDO prepared statements on every query
- [x] Role-based access control on every page and AJAX endpoint
- [x] Input sanitization with `htmlspecialchars` on all output
- [x] File upload validation: extension, MIME type, size limit (5 MB)
- [x] `.htaccess` blocks direct access to `config.php`, layout files, `.sql` files
- [x] `uploads/.htaccess` prevents PHP execution inside upload folder
- [x] Session-based authentication with `requireLogin()` / `requireRole()`
- [x] CSRF protection recommended — add Laravel CSRF tokens or custom nonce for production

---

## PHP REQUIREMENTS

- PHP 7.4+ (PHP 8.1+ recommended)
- PDO + PDO_MySQL extension
- GD extension (image uploads)
- fileinfo extension
- OpenSSL extension (random_bytes)
- All enabled by default in XAMPP

---

## PRODUCTION DEPLOYMENT NOTES

1. Set a strong MySQL password and update `config.php`
2. Move `config.php` above the web root or restrict via `.htaccess`
3. Delete `reset_password.php` immediately
4. Enable HTTPS (Let's Encrypt is free)
5. Set `session.cookie_secure = 1` and `session.cookie_httponly = 1` in `php.ini`
6. Set `APP_ENV=production` and disable error display in `php.ini`
7. Increase `upload_max_filesize` and `post_max_size` in `php.ini` if needed

---

## SUPPORT

For fingerprint SDK downloads:
- Futronic: `https://www.futronic-tech.com`
- Mantra:   `https://www.mantratec.com/download`
- DigitalPersona: `https://crossmatch.com` / HID Global
- SecuGen:  `https://secugen.com/download`
