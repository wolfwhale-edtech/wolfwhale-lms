<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_wwreports;

/**
 * One student's term report card.
 *
 * Course marks come from the gradebook. A hidden mark is left out, and a
 * course total that still contains a hidden mark is not shown. The real
 * total is never shown when a hidden mark is part of it. Attendance is read
 * from the attendance register. Badges are the ones the student has not
 * hidden. Points and level come from the points ledger. Comments are the
 * rows stored for this term plus feedback on marks that are themselves visible.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class card {
    /**
     * Template data for one viewer, one student, and one term.
     *
     * @param int $viewer
     * @param int $student
     * @param array $term
     * @return array
     */
    public static function build(int $viewer, int $student, array $term): array {
        global $DB;

        access::require_view($viewer, $student);
        $user = $DB->get_record('user', ['id' => $student, 'deleted' => 0],
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename', MUST_EXIST);
        $courses = [];
        foreach (access::courses_for($viewer, $student) as $course) {
            if (terms::overlaps($course, $term)) {
                $courses[] = $course;
            }
        }
        $courseids = array_map(static function (\stdClass $course): int {
            return (int) $course->id;
        }, $courses);
        $stored = comment::for_courses($student, $courseids, (string) $term['id']);
        $attendance = self::attendance_counts($student, $courseids, (int) $term['from'], (int) $term['until']);
        $rows = [];
        foreach ($courses as $course) {
            $marks = self::marks($student, $course);
            $saved = $stored[(int) $course->id] ?? [];
            $comments = self::merge_comments($saved, $marks['feedback']);
            $mine = '';
            foreach ($saved as $note) {
                if ((int) ($note['commenterid'] ?? 0) === $viewer) {
                    $mine = $note['text'];
                }
            }
            $rows[] = [
                'courseid' => (int) $course->id,
                'name' => self::plain($course->fullname),
                'mark' => $marks['mark'],
                'items' => $marks['items'],
                'attendance' => self::attendance_text($attendance[(int) $course->id] ?? null),
                'comments' => $comments,
                'cancomment' => access::can_comment($viewer, $student, (int) $course->id),
                'draft' => $mine,
                'studentid' => $student,
                'termid' => (string) $term['id'],
                'formurl' => (new \moodle_url('/local/wwreports/card.php', [
                    'student' => $student,
                    'term' => $term['id'],
                ]))->out(false),
                'save' => get_string('save', 'local_wwreports'),
            ];
        }
        $badges = self::badges($student);
        $points = \local_wwpoints\points::total($student);
        $level = \local_wwpoints\levels::for_points($points);
        $studentname = self::plain(fullname($user));
        $termname = (string) ($term['label'] ?? $term['short'] ?? '');
        $base = new \moodle_url('/local/wwreports/card.php', [
            'student' => $student,
            'term' => $term['id'],
        ]);
        $htmlurl = new \moodle_url($base, ['download' => 'html']);
        $csvurl = new \moodle_url($base, ['download' => 'csv']);
        $terms = [];
        foreach (terms::for_courses(access::courses_for($viewer, $student)) as $candidate) {
            $terms[] = [
                'name' => $candidate['short'],
                'url' => (new \moodle_url('/local/wwreports/card.php', [
                    'student' => $student,
                    'term' => $candidate['id'],
                ]))->out(false),
                'current' => $candidate['id'] === $term['id'],
            ];
        }
        $people = [];
        $roster = access::roster($viewer);
        if (count($roster) > 1) {
            foreach ($roster as $person) {
                $people[] = [
                    'name' => self::plain(fullname($person)),
                    'url' => (new \moodle_url('/local/wwreports/card.php', [
                        'student' => $person->id,
                        'term' => $term['id'],
                    ]))->out(false),
                    'current' => (int) $person->id === $student,
                ];
            }
        }
        $data = [
            'studentid' => $student,
            'studentname' => $studentname,
            'termid' => (string) $term['id'],
            'termname' => $termname,
            'courses' => $rows,
            'empty' => $rows === [],
            'none' => get_string('none', 'local_wwreports'),
            'badges' => $badges,
            'badgesempty' => $badges === [],
            'points' => (string) $points,
            'level' => (string) $level,
            'pointsword' => get_string('points', 'local_wwreports'),
            'levelword' => get_string('level', 'local_wwreports'),
            'terms' => $terms,
            'people' => $people,
            'several' => $people !== [],
            'formurl' => $base->out(false),
            'htmlurl' => $htmlurl->out(false),
            'csvurl' => $csvurl->out(false),
            'print' => get_string('print', 'local_wwreports'),
            'html' => get_string('html', 'local_wwreports'),
            'csv' => get_string('csv', 'local_wwreports'),
            'save' => get_string('save', 'local_wwreports'),
            'read' => get_string('read', 'local_wwreports'),
        ];
        $data['screenspeech'] = self::speech($data);
        return $data;
    }

    /**
     * People buttons when no student has been chosen yet.
     *
     * @param \stdClass[] $roster
     * @return array
     */
    public static function picker(array $roster): array {
        $people = [];
        foreach ($roster as $person) {
            $people[] = [
                'name' => self::plain(fullname($person)),
                'url' => (new \moodle_url('/local/wwreports/card.php', ['student' => $person->id]))->out(false),
                'current' => false,
            ];
        }
        return [
            'picker' => true,
            'people' => $people,
            'several' => $people !== [],
            'empty' => true,
            'none' => get_string('none', 'local_wwreports'),
            'courses' => [],
            'badges' => [],
            'terms' => [],
            'studentname' => '',
            'termname' => '',
            'points' => '',
            'level' => '',
            'pointsword' => '',
            'levelword' => '',
            'screenspeech' => $people ? implode('. ', array_column($people, 'name')) . '.' : get_string('none', 'local_wwreports'),
            'read' => get_string('read', 'local_wwreports'),
            'print' => get_string('print', 'local_wwreports'),
            'html' => get_string('html', 'local_wwreports'),
            'csv' => get_string('csv', 'local_wwreports'),
            'save' => get_string('save', 'local_wwreports'),
            'htmlurl' => '',
            'csvurl' => '',
            'formurl' => '',
        ];
    }

    /**
     * CSV rows for a card. The same fields as the page, and nothing else.
     *
     * @param array $data
     * @return array[]
     */
    public static function csv_rows(array $data): array {
        $badges = [];
        foreach ($data['badges'] as $badge) {
            $badges[] = $badge['name'];
        }
        $rows = [[
            get_string('student', 'local_wwreports'),
            get_string('term', 'local_wwreports'),
            get_string('course', 'local_wwreports'),
            get_string('mark', 'local_wwreports'),
            get_string('items', 'local_wwreports'),
            get_string('attendance', 'local_wwreports'),
            get_string('badges', 'local_wwreports'),
            get_string('points', 'local_wwreports'),
            get_string('level', 'local_wwreports'),
            get_string('comments', 'local_wwreports'),
        ]];
        $badgecell = implode('; ', $badges);
        $courses = $data['courses'] ?: [[
            'name' => '',
            'mark' => '',
            'items' => [],
            'attendance' => '',
            'comments' => [],
        ]];
        foreach ($courses as $course) {
            $items = [];
            foreach ($course['items'] as $item) {
                $items[] = $item['name'] . ' ' . $item['grade'];
            }
            $notes = [];
            foreach ($course['comments'] as $note) {
                $notes[] = $note['author'] . ': ' . $note['text'];
            }
            $rows[] = [
                (string) $data['studentname'],
                (string) $data['termname'],
                (string) $course['name'],
                (string) $course['mark'],
                implode('; ', $items),
                (string) $course['attendance'],
                $badgecell,
                (string) $data['points'],
                (string) $data['level'],
                implode('; ', $notes),
            ];
        }
        return $rows;
    }

    /**
     * A standalone printable page. It does not load the site theme.
     *
     * @param array $data
     * @return string
     */
    public static function html(array $data): string {
        $e = static function (string $value): string {
            return s($value);
        };
        $courses = '';
        foreach ($data['courses'] as $course) {
            $items = '';
            foreach ($course['items'] as $item) {
                $items .= '<p><span class="word">' . $e($item['name']) . '</span> '
                    . '<span class="fact">' . $e($item['grade']) . '</span></p>';
            }
            $notes = '';
            foreach ($course['comments'] as $note) {
                $notes .= '<p class="word">' . $e($note['author']) . '</p>'
                    . '<p class="fact">' . $e($note['text']) . '</p>';
            }
            $courses .= '<article class="block"><h2>' . $e($course['name']) . '</h2>'
                . '<p class="fact">' . $e($course['mark']) . '</p>'
                . $items
                . '<p class="fact">' . $e($course['attendance']) . '</p>'
                . $notes
                . '</article>';
        }
        if ($courses === '') {
            $courses = '<p class="fact">' . $e((string) $data['none']) . '</p>';
        }
        $badges = '';
        foreach ($data['badges'] as $badge) {
            $badges .= '<p class="word">' . $e($badge['name']) . '</p>';
        }
        $summary = '<article class="block">' . $badges
            . '<p class="word">' . $e((string) $data['pointsword']) . '</p>'
            . '<p class="fact">' . $e((string) $data['points']) . '</p>'
            . '<p class="word">' . $e((string) $data['levelword']) . '</p>'
            . '<p class="fact">' . $e((string) $data['level']) . '</p></article>';
        $title = $e(trim($data['studentname'] . ' ' . $data['termname']));
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $title . '</title>'
            . '<style>' . self::print_css() . '</style></head><body>'
            . '<h1>' . $e((string) $data['studentname']) . '</h1>'
            . '<p class="term">' . $e((string) $data['termname']) . '</p>'
            . $courses . $summary . '</body></html>';
    }

    /**
     * Send the CSV and stop.
     *
     * @param array $data
     */
    public static function send_csv(array $data): void {
        self::load_csv_library();
        $writer = new \csv_export_writer();
        $writer->set_filename('report-card');
        foreach (self::csv_rows($data) as $row) {
            $writer->add_data($row);
        }
        $writer->download_file();
    }

    /**
     * Load the CSV writer. It is not registered for autoloading.
     */
    public static function load_csv_library(): void {
        global $CFG;

        require_once($CFG->libdir . '/csvlib.class.php');
    }

    /**
     * Send the HTML file and stop.
     *
     * @param array $data
     */
    public static function send_html(array $data): void {
        $html = self::html($data);
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="report-card.html"');
        header('Content-Length: ' . strlen($html));
        echo $html;
        exit;
    }

    /**
     * Visible marks for one course.
     *
     * @param int $student
     * @param \stdClass $course
     * @return array{mark: string, items: array[], feedback: array[]}
     */
    private static function marks(int $student, \stdClass $course): array {
        global $CFG;

        $none = get_string('none', 'local_wwreports');
        $empty = ['mark' => $none, 'items' => [], 'feedback' => []];
        if (empty($course->showgrades)) {
            return $empty;
        }
        require_once($CFG->libdir . '/gradelib.php');
        $items = \grade_item::fetch_all(['courseid' => $course->id]);
        if (!$items) {
            return $empty;
        }
        $grades = [];
        foreach ($items as $itemid => $item) {
            $grade = \grade_grade::fetch(['itemid' => $itemid, 'userid' => $student]);
            if (!$grade) {
                $grade = new \grade_grade(['itemid' => $itemid, 'userid' => $student], false);
                $grade->finalgrade = null;
            }
            $grade->grade_item = $item;
            $grades[$itemid] = $grade;
        }
        $affected = ['altered' => [], 'unknown' => [], 'unknowngrades' => []];
        try {
            $affected = \grade_grade::get_hiding_affected($grades, $items);
        } catch (\Throwable $e) {
            $affected = ['altered' => [], 'unknown' => [], 'unknowngrades' => []];
        }
        // A calculated mark that reads a hidden mark cannot be shown. The raw
        // value is kept in unknowngrades, and unknown lists those same items.
        $unsafe = [];
        foreach (['unknown', 'unknowngrades'] as $key) {
            foreach (array_keys($affected[$key] ?? []) as $itemid) {
                $unsafe[(int) $itemid] = true;
            }
        }
        $hiddengraded = false;
        $lines = [];
        $feedback = [];
        $courseitem = null;
        foreach ($items as $itemid => $item) {
            $grade = $grades[$itemid];
            if ($item->is_course_item()) {
                $courseitem = $item;
                continue;
            }
            if ($item->is_category_item()) {
                continue;
            }
            $hidden = $item->is_hidden() || $grade->is_hidden() || isset($unsafe[(int) $itemid]);
            if ($hidden) {
                if ($grade->finalgrade !== null && !$grade->is_excluded()) {
                    $hiddengraded = true;
                }
                continue;
            }
            if ($grade->finalgrade !== null && !$grade->is_excluded()) {
                $lines[] = [
                    'name' => self::plain($item->get_name(false, false)),
                    'grade' => self::format_grade((float) $grade->finalgrade, $item),
                ];
            }
            $note = trim(strip_tags((string) ($grade->feedback ?? '')));
            $note = self::plain($note);
            if ($note !== '') {
                $feedback[] = [
                    'author' => self::author((int) ($grade->usermodified ?? 0)),
                    'text' => $note,
                    'commenterid' => (int) ($grade->usermodified ?? 0),
                ];
            }
        }
        $mark = $none;
        if ($courseitem) {
            $coursegrade = $grades[$courseitem->id];
            $raw = $coursegrade->finalgrade;
            if ($raw === null || $courseitem->is_hidden() || $coursegrade->is_hidden()) {
                $mark = $none;
            } else if ($hiddengraded) {
                $default = $CFG->grade_report_overview_showtotalsifcontainhidden ?? GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN;
                $setting = (int) grade_get_setting(
                    $course->id,
                    'report_overview_showtotalsifcontainhidden',
                    $default
                );
                $altered = $affected['altered'][$courseitem->id] ?? null;
                if ($setting === GRADE_REPORT_SHOW_TOTAL_IF_CONTAINS_HIDDEN
                        && $altered !== null
                        && abs((float) $altered - (float) $raw) > 0.001) {
                    $mark = self::format_grade((float) $altered, $courseitem);
                } else {
                    $mark = $none;
                }
            } else {
                $mark = self::format_grade((float) $raw, $courseitem);
            }
        }
        return ['mark' => $mark, 'items' => $lines, 'feedback' => $feedback];
    }

    /**
     * Present, absent, and late counts for one student in these courses.
     *
     * @param int $student
     * @param int[] $courseids
     * @param int $from
     * @param int $until
     * @return array<int, array<string, int>|null> Null when the register is not installed.
     */
    private static function attendance_counts(int $student, array $courseids, int $from, int $until): array {
        global $DB;

        $out = [];
        foreach ($courseids as $courseid) {
            $out[$courseid] = ['present' => 0, 'absent' => 0, 'late' => 0];
        }
        if (!$courseids || !$DB->get_manager()->table_exists('local_wwattendance_log')) {
            foreach ($courseids as $courseid) {
                $out[$courseid] = null;
            }
            return $out;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['userid'] = $student;
        $params['fromtime'] = $from;
        $params['untiltime'] = $until;
        $records = $DB->get_records_sql(
            "SELECT l.id, l.status, s.courseid
               FROM {local_wwattendance_log} l
               JOIN {local_wwattendance_session} s ON s.id = l.sessionid
              WHERE l.userid = :userid
                AND s.courseid {$insql}
                AND s.sessdate >= :fromtime
                AND s.sessdate <= :untiltime",
            $params
        );
        foreach ($records as $record) {
            $courseid = (int) $record->courseid;
            if (!isset($out[$courseid][$record->status])) {
                continue;
            }
            $out[$courseid][$record->status]++;
        }
        return $out;
    }

    /**
     * Present, absent, and late counts, or None when attendance is not installed.
     *
     * @param array<string, int>|null $counts
     * @return string
     */
    private static function attendance_text(?array $counts): string {
        if ($counts === null) {
            return get_string('none', 'local_wwreports');
        }
        return get_string('present', 'local_wwreports') . ' ' . $counts['present'] . ' '
            . get_string('absent', 'local_wwreports') . ' ' . $counts['absent'] . ' '
            . get_string('late', 'local_wwreports') . ' ' . $counts['late'];
    }

    /**
     * Badges this student has earned that they have not hidden.
     *
     * @param int $student
     * @return array[]
     */
    private static function badges(int $student): array {
        global $CFG;

        if (empty($CFG->enablebadges)) {
            return [];
        }
        require_once($CFG->libdir . '/badgeslib.php');
        $rows = [];
        foreach (badges_get_user_badges($student, 0, 0, 0, '', true) as $badge) {
            $rows[] = ['name' => self::plain($badge->name)];
        }
        return $rows;
    }

    /**
     * Stored comments first, then visible grade feedback, without a repeat.
     *
     * @param array[] $stored
     * @param array[] $feedback
     * @return array[]
     */
    private static function merge_comments(array $stored, array $feedback): array {
        $rows = [];
        $seen = [];
        foreach (array_merge($stored, $feedback) as $note) {
            $key = $note['author'] . "\n" . $note['text'];
            if (isset($seen[$key]) || $note['text'] === '') {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $note;
        }
        return $rows;
    }

    /**
     * The name of the person who wrote a grade comment.
     *
     * @param int $userid
     * @return string
     */
    private static function author(int $userid): string {
        global $DB;

        if ($userid <= 0) {
            return get_string('comment', 'local_wwreports');
        }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
        if (!$user) {
            return get_string('comment', 'local_wwreports');
        }
        return self::plain(fullname($user));
    }

    /**
     * A real mark, formatted the way the gradebook shows a raw value.
     *
     * @param float $value
     * @param \grade_item $item
     * @return string
     */
    private static function format_grade(float $value, \grade_item $item): string {
        $text = self::plain(grade_format_gradevalue($value, $item, true, GRADE_DISPLAY_TYPE_REAL));
        return $text === '' ? get_string('none', 'local_wwreports') : $text;
    }

    /**
     * Words read aloud. Only fields already on the card.
     *
     * @param array $data
     * @return string
     */
    private static function speech(array $data): string {
        $parts = [$data['studentname'] . '.', $data['termname'] . '.'];
        if (!$data['courses']) {
            $parts[] = $data['none'] . '.';
        }
        foreach ($data['courses'] as $course) {
            $parts[] = $course['name'] . '. ' . $course['mark'] . '. ' . $course['attendance'] . '.';
            foreach ($course['items'] as $item) {
                $parts[] = $item['name'] . '. ' . $item['grade'] . '.';
            }
            foreach ($course['comments'] as $note) {
                $parts[] = $note['author'] . '. ' . $note['text'] . '.';
            }
        }
        foreach ($data['badges'] as $badge) {
            $parts[] = $badge['name'] . '.';
        }
        $parts[] = $data['pointsword'] . '. ' . $data['points'] . '.';
        $parts[] = $data['levelword'] . '. ' . $data['level'] . '.';
        return implode(' ', $parts);
    }

    /**
     * Plain text with no tags.
     *
     * @param string $value
     * @return string
     */
    private static function plain(string $value): string {
        return \local_wwpoints\points::plain($value);
    }

    /**
     * Print rules for the downloaded page.
     *
     * @return string
     */
    private static function print_css(): string {
        return 'body{margin:1.5cm;color:#16324a;font-family:sans-serif;background:#fff}'
            . 'h1,h2{margin:0 0 .4rem}h1{font-size:2rem}h2{font-size:1.4rem}'
            . '.term{font-size:1.3rem;margin:0 0 1rem}'
            . '.block{margin:0 0 1rem;padding:1rem;border:1px solid #c5d7e6;border-radius:1rem;break-inside:avoid}'
            . '.word{font-weight:700}.fact{font-size:1.2rem}'
            . '@media print{body{margin:1.2cm}.block{box-shadow:none}}';
    }
}
