# SmartAttend — Smart Attendance System

A web-based smart attendance system built with PHP and MySQL, featuring QR code scanning, face recognition, offline mode, and role-based access for Admin, Teacher, and Student.

## Features

- **3 Roles**: Admin, Teacher, Student
- **Smart Check-in**: QR Code, Face Recognition (server-side verified), Manual
- **Offline Mode**: Service Worker + IndexedDB sync with conflict resolution
- **Security**: CSRF protection, brute-force login protection, server priority rules, timestamp validation
- **Analytics**: Daily/weekly/monthly reports, department comparison, low attendance alerts
- **Email Notifications**: PHPMailer integration for attendance, leave, and alerts
- **Admin Tools**: CSV import, bulk enrollment, schedule conflict detection, database backup

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB
- Apache (XAMPP recommended for local)
- Composer

## Installation

### 1. Clone the repository
```bash
git clone https://github.com/yourusername/attendance-system.git
```

### 2. Copy to web server root
```
C:\xampp\htdocs\attendance-system\
```

### 3. Configure database
```bash
cp config/db.example.php config/db.php
# Edit config/db.php with your credentials
```

### 4. Import database schema
```bash
mysql -u root -p attendance_system < db/schema.sql
```

Or via phpMyAdmin:
- Create database `attendance_system`
- Import `db/schema.sql`

### 5. Install PHP dependencies
```bash
composer install
```

### 6. Create uploads directory
```bash
mkdir assets/uploads
```

### 7. Open in browser
```
http://localhost/attendance-system/
```

## Default Login

| Role  | University ID       | Password |
|-------|---------------------|----------|
| Admin | WU/ADMIN/0001/00    | password |

> Change the default password immediately after first login.

## Project Structure

```
attendance-system/
├── admin/          # Admin pages
├── teacher/        # Teacher pages
├── student/        # Student pages
├── api/            # API endpoints (face verify, sync)
├── auth/           # Login, logout
├── assets/         # CSS, JS, uploads
├── config/         # Database config
├── db/             # SQL schema
├── includes/       # Shared: header, footer, functions, auth
├── vendor/         # Composer dependencies (not in git)
├── sw.js           # Service worker (offline mode)
└── index.php       # Entry point
```

## Security Notes

- `config/db.php` is excluded from git — never commit credentials
- All forms are CSRF protected
- Login is rate-limited (5 attempts per 15 min)
- Face recognition verified server-side
- Offline sync uses timestamp validation and server priority rules

## License

MIT
