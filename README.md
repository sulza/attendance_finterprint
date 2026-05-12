This README is tailored specifically for a professional deployment at **Government Day Arabic Secondary School (GDASS) Aminu Yusuf Hadejia**.

***

# EMS Biometric — Student Attendance & Identity System
### Optimized for GDASS Aminu Yusuf Hadejia, Jigawa State.

**EMS Biometric** is an enterprise-grade attendance management solution designed specifically for modernizing secondary school administration. The system uses high-speed fingerprint authentication to replace manual registers, ensuring accuracy, preventing proxy attendance, and securing student academic dossiers.

![Project License](https://img.shields.io/badge/Status-Active-success)
![Platform](https://img.shields.io/badge/Platform-XAMPP-blue)
![Device Support](https://img.shields.io/badge/Devices-Mantra%20%7C%20DigitalPersona%20%7C%20Futronic-orange)

---

## 🚀 Key Features

*   **Advanced Biometric Authentication**: Full integration with Mantra MLO31/MFS100, DigitalPersona U.are.U, and Futronic FS100 scanners.
*   **Dual Enrollment Engine**: Hardware-based fingerprint capture with a secure "Admin Override" simulation for edge cases.
*   **Digital Dossier Vault**: Student profiles featuring passport photos, NIN validation, and an encrypted certificate vault (max 250KB per file).
*   **Administrative Hierarchy**: Specialized dashboards for the Director, Admission Officers, and Class Masters.
*   **Bulk Data Management**: Mass-ingest student records using CSV templates to handle hundreds of enrollments in seconds.
*   **Real-time Intelligence**: A "Live Access Stream" dashboard showing instant inbound/outbound staff and student movement.
*   **Analytics & Audit**: Automated monthly attendance rates and school population summaries for management oversight.

---

## 🛠️ Technology Stack

- **Backend:** PHP 8.x (using PDO for Database security)
- **Database:** MariaDB/MySQL (via XAMPP)
- **Frontend:** HTML5, CSS3 (Enterprise Dark Mode UI), JS (Fetch API)
- **Styling:** Syne & Inter Typography, Bootstrap 5 Icons
- **Hardware Communication:** Multi-Driver SDK (Web-WebSocket / Local REST)

---

## 📂 System Requirements

### Software
1. **XAMPP Control Panel** (Apache & MySQL).
2. **Scanner Driver Bridge** (Mantra AVDM/RD Service, DigitalPersona Http Bridge, or Futronic WebSocket Server).
3. Modern Web Browser (Chrome recommended for L1 Hardware handshake).

### Hardware
*   Minimum **Mantra MFS100 / MLO31 (Bluetooth/USB)**.
*   Pentium Dual-Core PC or higher with 4GB RAM.

---

## 🔧 Installation Guide

1. **Clone the Project:**
   ```bash
   cd C:/xampp/htdocs
   git clone https://github.com/yourusername/biometric_attendance_gdass.git
   ```

2. **Configure Database:**
   - Open PHPMyAdmin (`localhost/phpmyadmin`).
   - Create a database named `biometric_attendance`.
   - Import the `database_schema.sql` file provided in the `/config` folder.

3. **Configure the System:**
   - Open `config.php` and update the database credentials if necessary:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('DB_NAME', 'biometric_attendance');
   ```

4. **Run Server:**
   - Start Apache and MySQL from XAMPP.
   - Access via `http://localhost/biometric_attendance/`.

---

## 🛡️ Security Protocol

This project has been hardened with:
- **CSRF Handshaking**: Protection against cross-site request forgery.
- **SHA-256 Hashing**: Biometric templates are stored as 256-bit hashes to ensure student privacy.
- **File Validation**: Strict checking of MIME types and a 250KB limit on cloud storage.
- **SQL Protection**: Use of PDO Prepared Statements to block all SQL injection paths.

---

## 🎓 Contact & Support

This system is currently maintained for use by the **Directorate and ICT Office** at GDASS Aminu Yusuf Hadejia.

**For technical assistance or system maintenance, please contact the ICT Dept or open a GitHub Issue.**

*GDASS Hadejia — Excellence through Faith and Knowledge.*

***
