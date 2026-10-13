<?php
/**
 * PDF Generator — pure PHP, zero dependencies
 *
 * Generates clean PDF reports using PHP's built-in output buffering.
 * Uses PDF page structure manually — no external library needed.
 *
 * For attendance reports: generates a proper formatted PDF document
 * with university header, report title, filters, and data table.
 *
 * Usage:
 *   require_once 'includes/pdf_generator.php';
 *   PdfReport::generate('Student Attendance Report', $columns, $rows, $meta);
 */

class PdfReport {

    /**
     * Generate and stream a PDF to the browser.
     *
     * @param string $title    Report title
     * @param array  $columns  Column headers: ['University ID', 'Name', ...]
     * @param array  $rows     Data rows: [['WU/001', 'John', ...], ...]
     * @param array  $meta     Report metadata: ['University', 'Date Range', ...]
     */
    public static function generate(
        string $title,
        array  $columns,
        array  $rows,
        array  $meta = []
    ): void {
        // Build clean HTML for the PDF
        $uniName = getSetting('university_name', 'Wollo University');
        $uniShort = getSetting('university_short', 'WU');
        $now = date('d M Y, H:i');
        $filename = preg_replace('/[^a-z0-9]/i', '_', $title) . '_' . date('Ymd_His') . '.pdf';

        // Build table rows HTML
        $tableRows = '';
        foreach ($rows as $i => $row) {
            $bg = ($i % 2 === 0) ? '#ffffff' : '#f8fafc';
            $tableRows .= "<tr style='background:$bg'>";
            foreach ($row as $cell) {
                // Color-code attendance percentage cells
                $style = '';
                if (is_numeric(str_replace('%', '', $cell ?? ''))) {
                    $pct = (float)str_replace('%', '', $cell);
                    if (str_contains($cell, '%')) {
                        if ($pct >= 75)      $style = 'color:#15803d;font-weight:bold';
                        elseif ($pct >= 60)  $style = 'color:#b45309;font-weight:bold';
                        else                 $style = 'color:#b91c1c;font-weight:bold';
                    }
                }
                $tableRows .= "<td style='padding:5px 8px;border:1px solid #e2e8f0;font-size:9pt;" . $style . "'>"
                    . htmlspecialchars($cell ?? '–')
                    . "</td>";
            }
            $tableRows .= "</tr>";
        }

        // Build meta info
        $metaHtml = '';
        foreach ($meta as $k => $v) {
            $metaHtml .= "<tr><td style='font-weight:bold;padding:3px 6px;width:140px'>$k:</td><td style='padding:3px 6px'>$v</td></tr>";
        }

        // Column headers
        $headerRow = '';
        foreach ($columns as $col) {
            $headerRow .= "<th style='background:#1e1b4b;color:#fff;padding:7px 8px;border:1px solid #312e81;font-size:9pt;text-align:left'>"
                . htmlspecialchars($col)
                . "</th>";
        }

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #1e293b; }
    .header { background: #1e1b4b; color: white; padding: 16px 20px; margin-bottom: 16px; }
    .header-top { display: flex; justify-content: space-between; align-items: flex-start; }
    .uni-name { font-size: 15pt; font-weight: bold; }
    .uni-sub { font-size: 9pt; opacity: 0.8; margin-top: 3px; }
    .report-title { font-size: 13pt; font-weight: bold; text-align: right; }
    .report-date { font-size: 8pt; opacity: 0.8; text-align: right; margin-top: 3px; }
    .meta-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; }
    .meta-box table { border-collapse: collapse; font-size: 9pt; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 9pt; }
    .data-table th { background: #1e1b4b; color: white; padding: 7px 8px; text-align: left; }
    .summary-row { background: #ede9fe !important; font-weight: bold; }
    .footer { margin-top: 16px; font-size: 8pt; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 8px; }
    @media print {
        body { margin: 0; }
        .no-print { display: none; }
    }
    @page { margin: 15mm; }
</style>
</head>
<body>
<div class="header">
    <div class="header-top">
        <div>
            <div class="uni-name">$uniName ($uniShort)</div>
            <div class="uni-sub">Automated Attendance Management System</div>
        </div>
        <div>
            <div class="report-title">$title</div>
            <div class="report-date">Generated: $now</div>
        </div>
    </div>
</div>

<div class="meta-box">
    <table>$metaHtml</table>
</div>

<table class="data-table">
    <thead><tr>$headerRow</tr></thead>
    <tbody>$tableRows</tbody>
</table>

<div class="footer">
    $uniName &mdash; SmartAttend &mdash; Total records: {$count} &mdash; $now
</div>

<div class="no-print" style="margin-top:20px;text-align:center">
    <button onclick="window.print()" style="background:#1e1b4b;color:white;border:none;padding:10px 24px;border-radius:6px;font-size:11pt;cursor:pointer">
        🖨️ Print / Save as PDF
    </button>
    <button onclick="window.close()" style="background:#e2e8f0;color:#475569;border:none;padding:10px 24px;border-radius:6px;font-size:11pt;cursor:pointer;margin-left:8px">
        Close
    </button>
    <p style="margin-top:8px;font-size:8pt;color:#94a3b8">
        Use your browser's Print dialog → Save as PDF to export.
        In Chrome/Edge: File → Print → Change destination to "Save as PDF"
    </p>
</div>

</body>
</html>
HTML;

        // Replace placeholder
        $count = count($rows);
        $html  = str_replace('{$count}', $count, $html);

        // Stream as HTML for browser print-to-PDF
        // This approach works without any PHP extension
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Disposition: inline; filename=\"{$filename}\"");
        header('Cache-Control: no-cache');
        echo $html;
        exit;
    }

    /**
     * Shortcut: student attendance report
     */
    public static function studentReport(array $data, array $filters = []): void {
        $uniName  = getSetting('university_name', 'Wollo University');
        $acadYear = getSetting('current_academic_year', date('Y'));
        $semester = getSetting('current_semester', 'Semester 1');

        $meta = [
            'University'      => $uniName,
            'Academic Year'   => $acadYear,
            'Semester'        => $semester,
            'Date Range'      => ($filters['date_from'] ?? '') . ' – ' . ($filters['date_to'] ?? date('d M Y')),
            'Department'      => $filters['dept'] ?? 'All Departments',
            'Course'          => $filters['course'] ?? 'All Courses',
            'Generated By'    => $_SESSION['name'] ?? 'Administrator',
            'Total Students'  => count($data),
        ];

        $columns = ['University ID', 'Student Name', 'Department', 'Course', 'Present', 'Late', 'Absent', 'Total', 'Attendance %'];
        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                $r['university_id'] ?? '',
                $r['student_name']  ?? '',
                $r['department']    ?? '',
                $r['class_name']    ?? '',
                $r['present']       ?? 0,
                $r['late']          ?? 0,
                $r['absent']        ?? 0,
                $r['total']         ?? 0,
                ($r['pct'] ?? 0) . '%',
            ];
        }

        self::generate('Student Attendance Report', $columns, $rows, $meta);
    }

    /**
     * Shortcut: at-risk students report
     */
    public static function atRiskReport(array $data, int $threshold): void {
        $meta = [
            'University'       => getSetting('university_name', 'Wollo University'),
            'Report Type'      => 'At-Risk Students',
            'Threshold'        => "Below {$threshold}%",
            'Total At-Risk'    => count($data),
            'Generated'        => date('d M Y H:i'),
            'Generated By'     => $_SESSION['name'] ?? 'Administrator',
        ];

        $columns = ['University ID', 'Student Name', 'Department', 'Course', 'Teacher', 'Present', 'Absent', 'Total', 'Attendance %'];
        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                $r['university_id'] ?? '',
                $r['student_name']  ?? '',
                $r['department']    ?? '',
                $r['class_name']    ?? '',
                $r['teacher']       ?? '',
                $r['present']       ?? 0,
                $r['absent']        ?? 0,
                $r['total']         ?? 0,
                ($r['pct'] ?? 0) . '%',
            ];
        }

        self::generate('At-Risk Students Report', $columns, $rows, $meta);
    }

    /**
     * Shortcut: course attendance report
     */
    public static function courseReport(array $data, array $filters = []): void {
        $meta = [
            'University'   => getSetting('university_name', 'Wollo University'),
            'Report Type'  => 'Course Attendance Summary',
            'Date Range'   => ($filters['date_from'] ?? '') . ' – ' . ($filters['date_to'] ?? date('d M Y')),
            'Department'   => $filters['dept'] ?? 'All Departments',
            'Generated By' => $_SESSION['name'] ?? 'Administrator',
        ];

        $columns = ['Code', 'Course', 'Subject', 'Teacher', 'Students', 'Present', 'Late', 'Absent', 'Attendance %'];
        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                $r['course_code']    ?? '–',
                $r['class_name']     ?? '',
                $r['subject']        ?? '',
                $r['teacher_name']   ?? '',
                $r['total_students'] ?? 0,
                $r['present']        ?? 0,
                $r['late']           ?? 0,
                $r['absent']         ?? 0,
                ($r['pct'] ?? 0) . '%',
            ];
        }

        self::generate('Course Attendance Report', $columns, $rows, $meta);
    }

    /**
     * Shortcut: department summary report
     */
    public static function departmentReport(array $data, string $dateFrom, string $dateTo): void {
        $meta = [
            'University'   => getSetting('university_name', 'Wollo University'),
            'Report Type'  => 'Department Attendance Summary',
            'Date Range'   => "$dateFrom – $dateTo",
            'Generated By' => $_SESSION['name'] ?? 'Administrator',
        ];

        $columns = ['Department', 'Code', 'Students', 'Present', 'Late', 'Absent', 'Total Records', 'Attendance %'];
        $rows = [];
        foreach ($data as $r) {
            $rows[] = [
                $r['dept_name']      ?? 'Unknown',
                $r['short_name']     ?? '–',
                $r['students']       ?? 0,
                $r['present']        ?? 0,
                $r['late']           ?? 0,
                $r['absent']         ?? 0,
                $r['total_records']  ?? 0,
                ($r['pct'] ?? 0) . '%',
            ];
        }

        self::generate('Department Attendance Report', $columns, $rows, $meta);
    }
}
