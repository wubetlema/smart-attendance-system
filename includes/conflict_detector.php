<?php
/**
 * Conflict Detector
 *
 * Centralised engine for all timetable conflict detection.
 * Used by:
 *   - admin/schedule.php          (on save + visual highlighting)
 *   - admin/timetable_engine.php  (bulk scan)
 *   - api/check_conflict.php      (real-time AJAX)
 *
 * Detects:
 *   1. Teacher conflict  — same teacher, overlapping time on same day
 *   2. Room conflict     — same room, overlapping time on same day
 *   3. Student group conflict — same department/year/section, overlapping time
 *
 * All public methods return a structured array:
 *   [
 *     'type'        => 'teacher' | 'room' | 'student_group',
 *     'severity'    => 'error' | 'warning',
 *     'message'     => human-readable description,
 *     'day'         => 'Monday',
 *     'start'       => '09:00',
 *     'end'         => '10:30',
 *     'class_a'     => ['id', 'name', 'subject', 'teacher'],
 *     'class_b'     => ['id', 'name', 'subject', 'teacher'],
 *     'schedule_ids'=> [id1, id2],
 *   ]
 */
class ConflictDetector {

    private static \mysqli $conn;

    public static function init(\mysqli $conn): void {
        self::$conn = $conn;
    }

    // ── Check a single proposed slot for conflicts ─────────────────────────────
    /**
     * @param int    $classId    The class being scheduled
     * @param string $day        e.g. 'Monday'
     * @param string $startTime  'HH:MM:SS' or 'HH:MM'
     * @param string $endTime    'HH:MM:SS' or 'HH:MM'
     * @param string $room       Room name (optional)
     * @param int    $excludeId  Schedule ID to exclude (for edits)
     * @return array             List of conflict objects (empty = no conflicts)
     */
    public static function checkSlot(
        int    $classId,
        string $day,
        string $startTime,
        string $endTime,
        string $room       = '',
        int    $excludeId  = 0
    ): array {
        $conflicts = [];
        $ex = $excludeId ? "AND s.id != $excludeId" : '';
        $c  = self::$conn;

        // Fetch class info
        $cls = $c->query("
            SELECT c.id, c.name, c.subject, c.teacher_id, c.department,
                   c.section, c.semester, c.year_of_study,
                   u.name AS teacher_name
            FROM classes c JOIN users u ON c.teacher_id=u.id
            WHERE c.id=$classId
        ")->fetch_assoc();

        if (!$cls) return [];

        $esc_day   = $c->real_escape_string($day);
        $esc_start = $c->real_escape_string($startTime);
        $esc_end   = $c->real_escape_string($endTime);

        // ── 1. Teacher conflict ────────────────────────────────────────────────
        $teacherRows = $c->query("
            SELECT s.id, s.start_time, s.end_time, s.room,
                   cl.id AS class_id, cl.name AS class_name, cl.subject,
                   u.name AS teacher_name
            FROM schedules s
            JOIN classes cl ON s.class_id=cl.id
            JOIN users u ON cl.teacher_id=u.id
            WHERE cl.teacher_id={$cls['teacher_id']}
              AND s.day_of_week='$esc_day'
              AND s.start_time < '$esc_end'
              AND s.end_time   > '$esc_start'
              AND s.class_id  != $classId
              $ex
        ")->fetch_all(MYSQLI_ASSOC);

        foreach ($teacherRows as $tr) {
            $conflicts[] = [
                'type'         => 'teacher',
                'severity'     => 'error',
                'message'      => "❌ Teacher conflict: {$cls['teacher_name']} is already teaching \"{$tr['class_name']}\" ({$tr['subject']}) on $day " . substr($tr['start_time'],0,5) . "–" . substr($tr['end_time'],0,5) . ".",
                'day'          => $day,
                'start'        => substr($startTime,0,5),
                'end'          => substr($endTime,0,5),
                'teacher'      => $cls['teacher_name'],
                'class_a'      => ['id'=>$classId, 'name'=>$cls['name'], 'subject'=>$cls['subject']],
                'class_b'      => ['id'=>$tr['class_id'], 'name'=>$tr['class_name'], 'subject'=>$tr['subject']],
                'schedule_ids' => [$excludeId ?: 0, (int)$tr['id']],
            ];
        }

        // ── 2. Room conflict ───────────────────────────────────────────────────
        if (!empty(trim($room))) {
            $esc_room = $c->real_escape_string(trim($room));
            $roomRows = $c->query("
                SELECT s.id, s.start_time, s.end_time,
                       cl.id AS class_id, cl.name AS class_name, cl.subject,
                       u.name AS teacher_name
                FROM schedules s
                JOIN classes cl ON s.class_id=cl.id
                JOIN users u ON cl.teacher_id=u.id
                WHERE s.room='$esc_room'
                  AND s.day_of_week='$esc_day'
                  AND s.start_time < '$esc_end'
                  AND s.end_time   > '$esc_start'
                  AND s.class_id  != $classId
                  $ex
            ")->fetch_all(MYSQLI_ASSOC);

            foreach ($roomRows as $rr) {
                $conflicts[] = [
                    'type'         => 'room',
                    'severity'     => 'error',
                    'message'      => "❌ Room conflict: \"$room\" is already booked for \"{$rr['class_name']}\" ({$rr['subject']}) on $day " . substr($rr['start_time'],0,5) . "–" . substr($rr['end_time'],0,5) . ".",
                    'day'          => $day,
                    'start'        => substr($startTime,0,5),
                    'end'          => substr($endTime,0,5),
                    'room'         => $room,
                    'class_a'      => ['id'=>$classId, 'name'=>$cls['name'], 'subject'=>$cls['subject']],
                    'class_b'      => ['id'=>$rr['class_id'], 'name'=>$rr['class_name'], 'subject'=>$rr['subject']],
                    'schedule_ids' => [$excludeId ?: 0, (int)$rr['id']],
                ];
            }
        }

        // ── 3. Student group conflict (same dept + section + semester) ──────────
        if (!empty($cls['department']) && !empty($cls['section'])) {
            $dept = $c->real_escape_string($cls['department']);
            $sec  = $c->real_escape_string($cls['section']);
            $sem  = $c->real_escape_string($cls['semester'] ?? '');
            $semWhere = $sem ? "AND cl2.semester='$sem'" : '';

            $groupRows = $c->query("
                SELECT s.id, s.start_time, s.end_time,
                       cl2.id AS class_id, cl2.name AS class_name, cl2.subject,
                       u.name AS teacher_name
                FROM schedules s
                JOIN classes cl2 ON s.class_id=cl2.id
                JOIN users u ON cl2.teacher_id=u.id
                WHERE cl2.department='$dept'
                  AND cl2.section='$sec'
                  $semWhere
                  AND s.day_of_week='$esc_day'
                  AND s.start_time < '$esc_end'
                  AND s.end_time   > '$esc_start'
                  AND cl2.id != $classId
                  $ex
            ")->fetch_all(MYSQLI_ASSOC);

            foreach ($groupRows as $gr) {
                $conflicts[] = [
                    'type'         => 'student_group',
                    'severity'     => 'warning',
                    'message'      => "⚠️ Student group conflict: {$cls['department']} / Section {$cls['section']} students are already scheduled for \"{$gr['class_name']}\" at this time.",
                    'day'          => $day,
                    'start'        => substr($startTime,0,5),
                    'end'          => substr($endTime,0,5),
                    'group'        => "{$cls['department']} / Section {$cls['section']}",
                    'class_a'      => ['id'=>$classId, 'name'=>$cls['name'], 'subject'=>$cls['subject']],
                    'class_b'      => ['id'=>$gr['class_id'], 'name'=>$gr['class_name'], 'subject'=>$gr['subject']],
                    'schedule_ids' => [$excludeId ?: 0, (int)$gr['id']],
                ];
            }
        }

        return $conflicts;
    }

    // ── Scan all schedules for every conflict type ─────────────────────────────
    public static function detectAll(): array {
        $c         = self::$conn;
        $conflicts = [];
        $seen      = []; // deduplicate by sorted pair

        // ── Teacher conflicts ──────────────────────────────────────────────────
        $rows = $c->query("
            SELECT
                s1.id AS sid1, s2.id AS sid2,
                s1.day_of_week, s1.start_time, s1.end_time,
                c1.id AS cid1, c1.name AS cn1, c1.subject AS cs1,
                c2.id AS cid2, c2.name AS cn2, c2.subject AS cs2,
                u.name AS teacher, u.id AS teacher_id
            FROM schedules s1
            JOIN schedules s2
                ON s1.id < s2.id
                AND s1.day_of_week = s2.day_of_week
                AND s1.start_time  < s2.end_time
                AND s1.end_time    > s2.start_time
            JOIN classes c1 ON s1.class_id = c1.id
            JOIN classes c2 ON s2.class_id = c2.id
            JOIN users u    ON c1.teacher_id = u.id
            WHERE c1.teacher_id = c2.teacher_id
            ORDER BY s1.day_of_week, s1.start_time
        ")->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $r) {
            $key = "t_{$r['sid1']}_{$r['sid2']}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $conflicts[] = [
                'type'     => 'teacher',
                'severity' => 'error',
                'message'  => "❌ Teacher conflict: <strong>{$r['teacher']}</strong> is scheduled for both \"{$r['cn1']}\" and \"{$r['cn2']}\" on {$r['day_of_week']} " . substr($r['start_time'],0,5) . "–" . substr($r['end_time'],0,5) . ".",
                'day'      => $r['day_of_week'],
                'start'    => substr($r['start_time'],0,5),
                'end'      => substr($r['end_time'],0,5),
                'teacher'  => $r['teacher'],
                'class_a'  => ['id'=>$r['cid1'],'name'=>$r['cn1'],'subject'=>$r['cs1']],
                'class_b'  => ['id'=>$r['cid2'],'name'=>$r['cn2'],'subject'=>$r['cs2']],
                'schedule_ids' => [(int)$r['sid1'],(int)$r['sid2']],
            ];
        }

        // ── Room conflicts ─────────────────────────────────────────────────────
        $rows = $c->query("
            SELECT
                s1.id AS sid1, s2.id AS sid2, s1.room,
                s1.day_of_week, s1.start_time, s1.end_time,
                c1.id AS cid1, c1.name AS cn1, c1.subject AS cs1,
                c2.id AS cid2, c2.name AS cn2, c2.subject AS cs2,
                u1.name AS t1, u2.name AS t2
            FROM schedules s1
            JOIN schedules s2
                ON s1.id < s2.id
                AND s1.day_of_week = s2.day_of_week
                AND s1.start_time  < s2.end_time
                AND s1.end_time    > s2.start_time
                AND s1.room        = s2.room
            JOIN classes c1 ON s1.class_id = c1.id
            JOIN classes c2 ON s2.class_id = c2.id
            JOIN users u1 ON c1.teacher_id = u1.id
            JOIN users u2 ON c2.teacher_id = u2.id
            WHERE s1.room IS NOT NULL AND s1.room != ''
            ORDER BY s1.room, s1.day_of_week, s1.start_time
        ")->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $r) {
            $key = "r_{$r['sid1']}_{$r['sid2']}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $conflicts[] = [
                'type'     => 'room',
                'severity' => 'error',
                'message'  => "❌ Room conflict: <strong>{$r['room']}</strong> is double-booked for \"{$r['cn1']}\" ({$r['t1']}) and \"{$r['cn2']}\" ({$r['t2']}) on {$r['day_of_week']} " . substr($r['start_time'],0,5) . "–" . substr($r['end_time'],0,5) . ".",
                'day'      => $r['day_of_week'],
                'start'    => substr($r['start_time'],0,5),
                'end'      => substr($r['end_time'],0,5),
                'room'     => $r['room'],
                'class_a'  => ['id'=>$r['cid1'],'name'=>$r['cn1'],'subject'=>$r['cs1'],'teacher'=>$r['t1']],
                'class_b'  => ['id'=>$r['cid2'],'name'=>$r['cn2'],'subject'=>$r['cs2'],'teacher'=>$r['t2']],
                'schedule_ids' => [(int)$r['sid1'],(int)$r['sid2']],
            ];
        }

        // ── Student group conflicts ────────────────────────────────────────────
        $rows = $c->query("
            SELECT
                s1.id AS sid1, s2.id AS sid2,
                s1.day_of_week, s1.start_time, s1.end_time,
                c1.id AS cid1, c1.name AS cn1, c1.subject AS cs1,
                c2.id AS cid2, c2.name AS cn2, c2.subject AS cs2,
                c1.department, c1.section, c1.semester
            FROM schedules s1
            JOIN schedules s2
                ON s1.id < s2.id
                AND s1.day_of_week = s2.day_of_week
                AND s1.start_time  < s2.end_time
                AND s1.end_time    > s2.start_time
            JOIN classes c1 ON s1.class_id = c1.id
            JOIN classes c2 ON s2.class_id = c2.id
            WHERE c1.department IS NOT NULL AND c1.department != ''
              AND c1.department = c2.department
              AND c1.section    = c2.section
              AND c1.section IS NOT NULL AND c1.section != ''
              AND c1.teacher_id != c2.teacher_id
            ORDER BY s1.day_of_week, s1.start_time
        ")->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $r) {
            $key = "g_{$r['sid1']}_{$r['sid2']}";
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $group = trim("{$r['department']} / Section {$r['section']}" . ($r['semester'] ? " / {$r['semester']}" : ''));
            $conflicts[] = [
                'type'     => 'student_group',
                'severity' => 'warning',
                'message'  => "⚠️ Student group conflict: <strong>$group</strong> students have two classes at the same time: \"{$r['cn1']}\" and \"{$r['cn2']}\" on {$r['day_of_week']} " . substr($r['start_time'],0,5) . "–" . substr($r['end_time'],0,5) . ".",
                'day'      => $r['day_of_week'],
                'start'    => substr($r['start_time'],0,5),
                'end'      => substr($r['end_time'],0,5),
                'group'    => $group,
                'class_a'  => ['id'=>$r['cid1'],'name'=>$r['cn1'],'subject'=>$r['cs1']],
                'class_b'  => ['id'=>$r['cid2'],'name'=>$r['cn2'],'subject'=>$r['cs2']],
                'schedule_ids' => [(int)$r['sid1'],(int)$r['sid2']],
            ];
        }

        return $conflicts;
    }

    // ── Get set of schedule IDs that have any conflict ─────────────────────────
    public static function conflictIdSet(): array {
        $conflicts = self::detectAll();
        $ids = [];
        foreach ($conflicts as $c) {
            foreach ($c['schedule_ids'] as $id) {
                if ($id) $ids[$id] = $c['type'];
            }
        }
        return $ids;
    }

    // ── Render a conflict card (HTML) ─────────────────────────────────────────
    public static function renderCard(array $conflict, bool $showResolve = true): string {
        $colors = [
            'teacher'       => ['#fef2f2','#dc2626','#b91c1c'],
            'room'          => ['#fff7ed','#f97316','#c2410c'],
            'student_group' => ['#fffbeb','#d97706','#b45309'],
        ];
        [$bg, $border, $text] = $colors[$conflict['type']] ?? $colors['teacher'];

        $typeLabels = ['teacher'=>'Teacher Conflict','room'=>'Room Conflict','student_group'=>'Student Group Conflict'];
        $typeLabel  = $typeLabels[$conflict['type']] ?? 'Conflict';

        $html = "<div style='padding:.85rem 1rem;background:$bg;border-left:4px solid $border;border-radius:0 8px 8px 0;margin-bottom:.6rem'>";
        $html .= "<div style='display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem'>";
        $html .= "<div style='flex:1'>";
        $html .= "<span style='display:inline-block;background:$border;color:#fff;padding:.15rem .6rem;border-radius:12px;font-size:.72rem;font-weight:700;margin-bottom:.4rem'>$typeLabel</span>";
        $html .= "<div style='font-size:.88rem;color:$text'>{$conflict['message']}</div>";
        $html .= "<div style='margin-top:.35rem;display:flex;gap:1rem;flex-wrap:wrap;font-size:.8rem;color:#64748b'>";
        $html .= "<span>📅 {$conflict['day']}</span>";
        $html .= "<span>🕐 {$conflict['start']} – {$conflict['end']}</span>";
        if (!empty($conflict['room']))    $html .= "<span>📍 {$conflict['room']}</span>";
        if (!empty($conflict['teacher'])) $html .= "<span>👤 {$conflict['teacher']}</span>";
        if (!empty($conflict['group']))   $html .= "<span>👥 {$conflict['group']}</span>";
        $html .= "</div>";
        $html .= "</div>";

        if ($showResolve && !empty($conflict['schedule_ids'])) {
            $html .= "<div style='display:flex;gap:.3rem;flex-wrap:wrap'>";
            foreach ($conflict['schedule_ids'] as $sid) {
                if (!$sid) continue;
                $html .= "<a href='schedule.php?edit=$sid' class='btn btn-sm btn-secondary' style='font-size:.75rem'>Edit #$sid</a>";
                $html .= "<form method='POST' action='schedule.php' style='display:inline' onsubmit='return confirm(\"Delete schedule #$sid?\")'>";
                $html .= "<input type='hidden' name='action' value='delete'>";
                $html .= "<input type='hidden' name='id' value='$sid'>";
                $html .= "<button class='btn btn-sm btn-danger' style='font-size:.75rem'>Delete #$sid</button>";
                $html .= "</form>";
            }
            $html .= "</div>";
        }

        $html .= "</div></div>";
        return $html;
    }
}
