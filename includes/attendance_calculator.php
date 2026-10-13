<?php
/**
 * Attendance Calculator
 *
 * Centralized engine for all attendance percentage calculations.
 * Thresholds are configurable from admin settings — never hard-coded.
 *
 * Usage:
 *   require_once 'includes/attendance_calculator.php';
 *
 *   // Per-student across all classes
 *   $summary = AttendanceCalculator::studentSummary($studentId);
 *
 *   // Per-student per-class
 *   $row = AttendanceCalculator::studentClassStats($studentId, $classId);
 *
 *   // All students in a class
 *   $roster = AttendanceCalculator::classSummary($classId);
 *
 *   // Status label and color
 *   $status = AttendanceCalculator::status(72); // ['label'=>'Warning','color'=>'#d97706','icon'=>'🟡','class'=>'warning']
 */

class AttendanceCalculator {

    // ── Threshold cache ───────────────────────────────────────────────────────
    private static ?array $_thresholds = null;

    public static function thresholds(): array {
        if (self::$_thresholds !== null) return self::$_thresholds;

        global $conn;
        $rows = [];
        if (isset($conn)) {
            $result = $conn->query("
                SELECT setting_key, setting_value FROM settings
                WHERE setting_key IN (
                    'attendance_threshold_good',
                    'attendance_threshold_warning',
                    'attendance_late_counts'
                )
            ");
            if ($result) {
                foreach ($result->fetch_all(MYSQLI_ASSOC) as $r) {
                    $rows[$r['setting_key']] = $r['setting_value'];
                }
            }
        }

        self::$_thresholds = [
            'good'        => (float)($rows['attendance_threshold_good']    ?? 75),
            'warning'     => (float)($rows['attendance_threshold_warning'] ?? 60),
            'late_counts' => (float)($rows['attendance_late_counts']       ?? 1),
        ];

        return self::$_thresholds;
    }

    // ── Reset cache (call after settings update) ──────────────────────────────
    public static function resetCache(): void {
        self::$_thresholds = null;
    }

    // ── Status label, color, icon from a percentage ───────────────────────────
    public static function status(float|int|null $pct): array {
        if ($pct === null) {
            return ['label' => 'No Data', 'color' => '#94a3b8', 'bg' => '#f1f5f9', 'icon' => '⚪', 'class' => 'nodata'];
        }

        $t = self::thresholds();

        if ($pct >= $t['good']) {
            return ['label' => 'Good',     'color' => '#15803d', 'bg' => '#dcfce7', 'icon' => '🟢', 'class' => 'good'];
        } elseif ($pct >= $t['warning']) {
            return ['label' => 'Warning',  'color' => '#b45309', 'bg' => '#fef3c7', 'icon' => '🟡', 'class' => 'warning'];
        } else {
            return ['label' => 'Critical', 'color' => '#b91c1c', 'bg' => '#fee2e2', 'icon' => '🔴', 'class' => 'critical'];
        }
    }

    // ── Calculate percentage from raw counts ──────────────────────────────────
    public static function calculate(int $total, int $present, int $late, int $absent): array {
        if ($total === 0) {
            return [
                'total'   => 0, 'present' => 0, 'late' => 0, 'absent' => 0,
                'attended' => 0, 'pct' => null, 'pct_display' => '–',
            ];
        }

        $t           = self::thresholds();
        $lateWeight  = (float)$t['late_counts'];
        $attended    = $present + ($late * $lateWeight);
        $pct         = round(($attended / $total) * 100, 1);

        return [
            'total'       => $total,
            'present'     => $present,
            'late'        => $late,
            'absent'      => $absent,
            'attended'    => $attended,
            'pct'         => $pct,
            'pct_display' => $pct . '%',
        ];
    }

    // ── Per-student, per-class stats ──────────────────────────────────────────
    public static function studentClassStats(int $studentId, int $classId): array {
        global $conn;

        $stmt = $conn->prepare("
            SELECT
                COUNT(*)                        AS total,
                SUM(a.status = 'present')        AS present,
                SUM(a.status = 'late')           AS late,
                SUM(a.status = 'absent')         AS absent,
                MAX(a.marked_at)                 AS last_seen,
                c.name                           AS class_name,
                c.subject,
                c.course_code,
                c.credits,
                u.name                           AS teacher_name
            FROM attendance a
            JOIN attendance_sessions s ON a.session_id = s.id
            JOIN classes c             ON s.class_id   = c.id
            JOIN users u               ON c.teacher_id = u.id
            WHERE a.student_id = ?
              AND s.class_id   = ?
        ");
        $stmt->bind_param('ii', $studentId, $classId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || $row['total'] == 0) {
            return array_merge(
                self::calculate(0, 0, 0, 0),
                ['class_name' => '', 'subject' => '', 'course_code' => '',
                 'credits' => 0, 'teacher_name' => '', 'last_seen' => null,
                 'status' => self::status(null)]
            );
        }

        $calc = self::calculate(
            (int)$row['total'],
            (int)$row['present'],
            (int)$row['late'],
            (int)$row['absent']
        );

        return array_merge($calc, [
            'class_name'   => $row['class_name'],
            'subject'      => $row['subject'],
            'course_code'  => $row['course_code'],
            'credits'      => (int)$row['credits'],
            'teacher_name' => $row['teacher_name'],
            'last_seen'    => $row['last_seen'],
            'status'       => self::status($calc['pct']),
        ]);
    }

    // ── Per-student, all enrolled classes ─────────────────────────────────────
    public static function studentSummary(int $studentId): array {
        global $conn;

        // Get all enrolled classes
        $stmt = $conn->prepare("
            SELECT c.id AS class_id, c.name AS class_name, c.subject,
                   c.course_code, c.credits, u.name AS teacher_name,
                   prog.name AS program_name,
                   d.name AS department_name
            FROM class_students cs
            JOIN classes c  ON cs.class_id   = c.id
            JOIN users u    ON c.teacher_id  = u.id
            LEFT JOIN departments d  ON c.department_id = d.id
            LEFT JOIN programs prog  ON c.program_id    = prog.id
            WHERE cs.student_id = ?
              AND cs.status     = 'active'
              AND c.is_active   = 1
            ORDER BY c.name
        ");
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($classes)) return [];

        // Fetch attendance counts for all classes in one query
        $classIds = implode(',', array_column($classes, 'class_id'));
        $counts   = $conn->query("
            SELECT s.class_id,
                   COUNT(*)                  AS total,
                   SUM(a.status='present')   AS present,
                   SUM(a.status='late')      AS late,
                   SUM(a.status='absent')    AS absent,
                   MAX(a.marked_at)          AS last_seen
            FROM attendance a
            JOIN attendance_sessions s ON a.session_id = s.id
            WHERE a.student_id = $studentId
              AND s.class_id   IN ($classIds)
            GROUP BY s.class_id
        ")->fetch_all(MYSQLI_ASSOC);

        // Index by class_id
        $countMap = [];
        foreach ($counts as $c) $countMap[$c['class_id']] = $c;

        $result = [];
        foreach ($classes as $cls) {
            $cid = $cls['class_id'];
            $raw = $countMap[$cid] ?? ['total'=>0,'present'=>0,'late'=>0,'absent'=>0,'last_seen'=>null];
            $calc = self::calculate(
                (int)$raw['total'],
                (int)$raw['present'],
                (int)$raw['late'],
                (int)$raw['absent']
            );
            $result[] = array_merge($cls, $calc, [
                'last_seen' => $raw['last_seen'],
                'status'    => self::status($calc['pct']),
            ]);
        }

        return $result;
    }

    // ── Overall stats for a student (aggregate across all classes) ─────────────
    public static function studentOverall(int $studentId): array {
        global $conn;

        $stmt = $conn->prepare("
            SELECT
                COUNT(*)                AS total,
                SUM(a.status='present') AS present,
                SUM(a.status='late')    AS late,
                SUM(a.status='absent')  AS absent
            FROM attendance a
            WHERE a.student_id = ?
        ");
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $calc = self::calculate(
            (int)$row['total'],
            (int)$row['present'],
            (int)$row['late'],
            (int)$row['absent']
        );

        return array_merge($calc, ['status' => self::status($calc['pct'])]);
    }

    // ── All students in a class ────────────────────────────────────────────────
    public static function classSummary(int $classId): array {
        global $conn;

        $rows = $conn->query("
            SELECT u.id AS student_id, u.name, u.university_id, u.email,
                   cs.section,
                   COUNT(a.id)                   AS total,
                   SUM(a.status='present')        AS present,
                   SUM(a.status='late')           AS late,
                   SUM(a.status='absent')         AS absent,
                   MAX(a.marked_at)               AS last_seen
            FROM class_students cs
            JOIN users u ON cs.student_id = u.id
            LEFT JOIN attendance a ON a.student_id = u.id
                AND a.session_id IN (
                    SELECT id FROM attendance_sessions WHERE class_id = $classId
                )
            WHERE cs.class_id = $classId
              AND cs.status   = 'active'
            GROUP BY u.id, cs.section
            ORDER BY cs.section, u.name
        ")->fetch_all(MYSQLI_ASSOC);

        return array_map(function ($r) {
            $calc = self::calculate(
                (int)$r['total'],
                (int)$r['present'],
                (int)$r['late'],
                (int)$r['absent']
            );
            return array_merge($r, $calc, ['status' => self::status($calc['pct'])]);
        }, $rows);
    }

    // ── Department-level aggregate ────────────────────────────────────────────
    public static function departmentSummary(int $departmentId): array {
        global $conn;

        $row = $conn->query("
            SELECT
                COUNT(DISTINCT a.student_id)     AS total_students,
                COUNT(*)                         AS total_records,
                SUM(a.status='present')          AS present,
                SUM(a.status='late')             AS late,
                SUM(a.status='absent')           AS absent
            FROM attendance a
            JOIN attendance_sessions s ON a.session_id = s.id
            JOIN classes c             ON s.class_id   = c.id
            WHERE c.department_id = $departmentId
        ")->fetch_assoc();

        $calc = self::calculate(
            (int)$row['total_records'],
            (int)$row['present'],
            (int)$row['late'],
            (int)$row['absent']
        );

        return array_merge($calc, [
            'total_students' => (int)$row['total_students'],
            'status'         => self::status($calc['pct']),
        ]);
    }

    // ── Render a progress bar HTML snippet ───────────────────────────────────
    public static function progressBar(float|int|null $pct, string $extraStyle = ''): string {
        $st    = self::status($pct);
        $width = $pct !== null ? min(100, max(0, $pct)) : 0;
        return sprintf(
            '<div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;%s">
               <div style="height:100%%;width:%s%%;background:%s;border-radius:4px;transition:width .5s"></div>
             </div>',
            $extraStyle,
            $width,
            $st['color']
        );
    }

    // ── Render threshold legend HTML ──────────────────────────────────────────
    public static function legend(): string {
        $t = self::thresholds();
        return sprintf(
            '<div style="display:flex;gap:1rem;flex-wrap:wrap;font-size:.82rem;color:#475569">
               <span>%s Good ≥ %d%%</span>
               <span>%s Warning %d–%d%%</span>
               <span>%s Critical &lt; %d%%</span>
             </div>',
            '🟢', (int)$t['good'],
            '🟡', (int)$t['warning'], (int)$t['good'] - 1,
            '🔴', (int)$t['warning']
        );
    }
}
