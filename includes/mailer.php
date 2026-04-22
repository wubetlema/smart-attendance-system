<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send an email using SMTP settings from the database.
 *
 * @param string $toEmail    Recipient email
 * @param string $toName     Recipient name
 * @param string $subject    Email subject
 * @param string $body       HTML email body
 * @return array ['success' => bool, 'message' => string]
 */
function sendMail($toEmail, $toName, $subject, $body) {
    $host    = getSetting('smtp_host', '');
    $user    = getSetting('smtp_user', '');
    $pass    = getSetting('smtp_pass', '');
    $port    = (int) getSetting('smtp_port', 587);
    $enabled = getSetting('email_notifications', '0');

    if (!$enabled || !$host || !$user) {
        return ['success' => false, 'message' => 'Email notifications are disabled or SMTP not configured.'];
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $user;
        $mail->Password   = $pass;
        $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;

        $mail->setFrom($user, 'SmartAttend System');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = emailTemplate($subject, $body);
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return ['success' => true, 'message' => 'Email sent.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Mail error: ' . $mail->ErrorInfo];
    }
}

/**
 * Wrap email body in a clean HTML template.
 */
function emailTemplate($title, $content) {
    return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><style>
body{font-family:Arial,sans-serif;background:#f8fafc;margin:0;padding:0}
.wrap{max-width:600px;margin:2rem auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.header{background:#4f46e5;color:#fff;padding:1.5rem 2rem}
.header h2{margin:0;font-size:1.3rem}
.body{padding:2rem;color:#1e293b;line-height:1.6}
.footer{background:#f1f5f9;padding:1rem 2rem;font-size:.8rem;color:#64748b;text-align:center}
.btn{display:inline-block;background:#4f46e5;color:#fff;padding:.6rem 1.2rem;border-radius:6px;text-decoration:none;margin-top:1rem}
</style></head>
<body>
<div class="wrap">
    <div class="header"><h2>SmartAttend &mdash; ' . htmlspecialchars($title) . '</h2></div>
    <div class="body">' . $content . '</div>
    <div class="footer">SmartAttend &copy; ' . date('Y') . ' &mdash; This is an automated message, please do not reply.</div>
</div>
</body></html>';
}

/**
 * Notify a student about their attendance status.
 */
function notifyAttendance($studentEmail, $studentName, $courseName, $status, $date) {
    $color  = $status === 'present' ? '#16a34a' : ($status === 'late' ? '#d97706' : '#dc2626');
    $body   = "<p>Dear <strong>" . htmlspecialchars($studentName) . "</strong>,</p>
               <p>Your attendance for <strong>" . htmlspecialchars($courseName) . "</strong> on <strong>$date</strong> has been recorded as:</p>
               <p style='font-size:1.3rem;font-weight:700;color:$color'>" . strtoupper($status) . "</p>
               <p>Log in to SmartAttend to view your full attendance history.</p>";
    return sendMail($studentEmail, $studentName, "Attendance Recorded - $courseName", $body);
}

/**
 * Send low attendance warning to a student.
 */
function notifyLowAttendance($studentEmail, $studentName, $courseName, $percentage) {
    $body = "<p>Dear <strong>" . htmlspecialchars($studentName) . "</strong>,</p>
             <p>This is an automated warning regarding your attendance in <strong>" . htmlspecialchars($courseName) . "</strong>.</p>
             <p>Your current attendance rate is: <strong style='color:#dc2626;font-size:1.2rem'>$percentage%</strong></p>
             <p>The minimum required attendance is <strong>75%</strong>. Please attend classes regularly to avoid academic penalties.</p>";
    return sendMail($studentEmail, $studentName, "Low Attendance Warning - $courseName", $body);
}

/**
 * Notify teacher about a new leave request.
 */
function notifyLeaveRequest($teacherEmail, $teacherName, $studentName, $courseName, $date, $reason) {
    $body = "<p>Dear <strong>" . htmlspecialchars($teacherName) . "</strong>,</p>
             <p><strong>" . htmlspecialchars($studentName) . "</strong> has submitted a leave request for your course <strong>" . htmlspecialchars($courseName) . "</strong>.</p>
             <p><strong>Date:</strong> $date<br><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>
             <p>Please log in to SmartAttend to approve or reject this request.</p>";
    return sendMail($teacherEmail, $teacherName, "New Leave Request - $courseName", $body);
}

/**
 * Notify student about leave approval/rejection.
 */
function notifyLeaveStatus($studentEmail, $studentName, $courseName, $date, $status) {
    $color = $status === 'approved' ? '#16a34a' : '#dc2626';
    $body  = "<p>Dear <strong>" . htmlspecialchars($studentName) . "</strong>,</p>
              <p>Your leave request for <strong>" . htmlspecialchars($courseName) . "</strong> on <strong>$date</strong> has been:</p>
              <p style='font-size:1.2rem;font-weight:700;color:$color'>" . strtoupper($status) . "</p>";
    return sendMail($studentEmail, $studentName, "Leave Request $status - $courseName", $body);
}
