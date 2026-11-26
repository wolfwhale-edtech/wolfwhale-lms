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
 * School, division, and education-authority totals for one term.
 *
 * A top-level category that contains other categories is an education
 * authority. A category that contains other categories under that is a
 * division. A category with no children is a school. A top-level category
 * with no children is a school on its own.
 *
 * Every number is counted from enrolments, attendance marks, and completion
 * records in that category's courses. A total is counted again across those
 * courses. It is not an average of the class rates.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class funder {
    /** @var string Top-level category that contains other categories. */
    public const AUTHORITY = 'authority';

    /** @var string A category that contains schools. */
    public const DIVISION = 'division';

    /** @var string A category with no child categories. */
    public const SCHOOL = 'school';

    /**
     * Categories this person may report on.
     *
     * @param int|null $userid
     * @return array[]
     */
    public static function scopes(?int $userid = null): array {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $ids = \local_wwadmin\scope::visible_ids($userid);
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $cats = $DB->get_records_select('course_categories', "id {$insql}", $params, 'depth ASC, name ASC, id ASC');
        $children = [];
        foreach ($DB->get_records_select('course_categories', 'parent <> 0', null, '', 'id, parent') as $row) {
            $children[(int) $row->parent] = true;
        }
        $scopes = [];
        foreach ($cats as $cat) {
            $kind = self::kind_of($cat, isset($children[(int) $cat->id]));
            $scopes[] = [
                'id' => (int) $cat->id,
                'name' => self::plain($cat->name),
                'kind' => $kind,
                'kindlabel' => get_string($kind, 'local_wwreports'),
                'depth' => (int) $cat->depth,
            ];
        }
        return $scopes;
    }

    /**
     * Refuse unless this person administers this category.
     *
     * @param int $categoryid
     */
    public static function require_category(int $categoryid): void {
        $visible = array_map('intval', \local_wwadmin\scope::visible_ids());
        if (!in_array($categoryid, $visible, true)) {
            throw new \moodle_exception('cannotview', 'local_wwreports');
        }
        \local_wwadmin\scope::require($categoryid, 'local/wwadmin:view');
    }

    /**
     * One scope for the page and the CSV.
     *
     * @param int $categoryid
     * @param array $term
     * @return array
     */
    public static function report(int $categoryid, array $term): array {
        global $DB;

        self::require_category($categoryid);
        $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name, parent, depth', MUST_EXIST);
        $haschildren = $DB->record_exists('course_categories', ['parent' => $categoryid]);
        $kind = self::kind_of($category, $haschildren);
        $counts = self::aggregate($categoryid, (int) $term['from'], (int) $term['until']);
        $showschool = count(array_unique(array_column($counts['classes'], 'school'))) > 1;
        $classes = [];
        foreach ($counts['classes'] as $class) {
            $classes[] = self::class_row($class, $showschool);
        }
        $scopes = [];
        foreach (self::scopes() as $scope) {
            $scopes[] = [
                'name' => $scope['name'],
                'url' => (new \moodle_url('/local/wwreports/funder.php', [
                    'category' => $scope['id'],
                    'term' => $term['id'],
                ]))->out(false),
                'current' => (int) $scope['id'] === $categoryid,
            ];
        }
        $terms = [];
        foreach (terms::for_category($categoryid) as $candidate) {
            $terms[] = [
                'name' => $candidate['short'],
                'url' => (new \moodle_url('/local/wwreports/funder.php', [
                    'category' => $categoryid,
                    'term' => $candidate['id'],
                ]))->out(false),
                'current' => $candidate['id'] === $term['id'],
            ];
        }
        $name = self::plain($category->name);
        $data = [
            'name' => $name,
            'kind' => get_string($kind, 'local_wwreports'),
            'kindkey' => $kind,
            'termid' => (string) $term['id'],
            'termname' => (string) ($term['label'] ?? $term['short'] ?? ''),
            'categoryid' => $categoryid,
            'classes' => $classes,
            'empty' => $classes === [],
            'none' => get_string('none', 'local_wwreports'),
            'total' => self::class_row([
                'name' => get_string('all', 'local_wwreports'),
                'school' => $name,
                'students' => $counts['students'],
                'attendance' => $counts['attendance'],
                'coursecompletion' => $counts['coursecompletion'],
                'activitycompletion' => $counts['activitycompletion'],
            ], false),
            'scopes' => $scopes,
            'terms' => $terms,
            'csvurl' => (new \moodle_url('/local/wwreports/funder.php', [
                'category' => $categoryid,
                'term' => $term['id'],
                'download' => 'csv',
            ]))->out(false),
            'print' => get_string('print', 'local_wwreports'),
            'csv' => get_string('csv', 'local_wwreports'),
            'read' => get_string('read', 'local_wwreports'),
        ];
        $speech = [$data['name'] . '.', $data['kind'] . '.', $data['termname'] . '.'];
        foreach (array_merge($data['classes'], [$data['total']]) as $class) {
            $speech[] = $class['name'] . '.';
            foreach ($class['facts'] as $fact) {
                $speech[] = $fact['word'] . '. ' . $fact['fact'] . '.';
            }
        }
        $data['screenspeech'] = implode(' ', $speech);
        return $data;
    }

    /**
     * Counts for one category between two times.
     *
     * The caller must already be allowed to view the category. This checks again.
     *
     * @param int $categoryid
     * @param int $from
     * @param int $until
     * @return array
     */
    public static function aggregate(int $categoryid, int $from, int $until): array {
        global $DB;

        self::require_category($categoryid);
        $tree = \local_wwadmin\scope::tree_ids($categoryid);
        $courses = \local_wwadmin\scope::courses($tree);
        $classes = [];
        $courseids = [];
        foreach ($courses as $course) {
            if (!terms::overlaps($course, ['from' => $from, 'until' => $until])) {
                continue;
            }
            $courseids[] = (int) $course->id;
            $school = $DB->get_field('course_categories', 'name', ['id' => $course->category]);
            $students = access::students_in_courses([(int) $course->id]);
            $classes[] = [
                'name' => self::plain($course->fullname),
                'school' => self::plain((string) $school),
                'students' => count($students),
                'attendance' => self::attendance_rate([(int) $course->id], $from, $until),
                'coursecompletion' => self::course_rate($course, array_keys($students), $from, $until),
                'activitycompletion' => self::activity_rate((int) $course->id, array_keys($students), $from, $until),
            ];
        }
        usort($classes, static function (array $a, array $b): int {
            return strcasecmp($a['school'], $b['school']) ?: strcasecmp($a['name'], $b['name']);
        });
        return [
            'classes' => $classes,
            'students' => count(access::students_in_courses($courseids)),
            'attendance' => self::attendance_rate($courseids, $from, $until),
            'coursecompletion' => self::course_rate_many($courses, $courseids, $from, $until),
            'activitycompletion' => self::activity_rate_many($courseids, $from, $until),
        ];
    }

    /**
     * CSV rows, including the recounted total.
     *
     * @param array $data
     * @return array[]
     */
    public static function csv_rows(array $data): array {
        $rows = [[
            get_string('school', 'local_wwreports'),
            get_string('kind', 'local_wwreports'),
            get_string('term', 'local_wwreports'),
            get_string('class', 'local_wwreports'),
            get_string('students', 'local_wwreports'),
            get_string('attendance', 'local_wwreports'),
            get_string('coursecompletion', 'local_wwreports'),
            get_string('activities', 'local_wwreports'),
        ]];
        $lines = $data['classes'];
        $lines[] = $data['total'];
        foreach ($lines as $line) {
            $facts = [];
            foreach ($line['facts'] as $fact) {
                $facts[$fact['word']] = $fact['fact'];
            }
            $rows[] = [
                $line['school'],
                $data['kind'],
                $data['termname'],
                $line['name'],
                $facts[get_string('students', 'local_wwreports')] ?? '',
                $facts[get_string('attendance', 'local_wwreports')] ?? '',
                $facts[get_string('coursecompletion', 'local_wwreports')] ?? '',
                $facts[get_string('activities', 'local_wwreports')] ?? '',
            ];
        }
        return $rows;
    }

    /**
     * Send the CSV and stop.
     *
     * @param array $data
     */
    public static function send_csv(array $data): void {
        $writer = new \csv_export_writer();
        $writer->set_filename('funder-report');
        foreach (self::csv_rows($data) as $row) {
            $writer->add_data($row);
        }
        $writer->download_file();
    }

    /**
     * A percentage, or None when there is nothing to count.
     *
     * @param float|null $rate
     * @return string
     */
    public static function format_rate(?float $rate): string {
        if ($rate === null) {
            return get_string('none', 'local_wwreports');
        }
        $rounded = round($rate, 1);
        if (abs($rounded - round($rounded)) < 0.001) {
            return (string) (int) round($rounded) . '%';
        }
        return number_format($rounded, 1, '.', '') . '%';
    }

    /**
     * Authority, division, or school.
     *
     * @param \stdClass $category
     * @param bool $haschildren
     * @return string
     */
    private static function kind_of(\stdClass $category, bool $haschildren): string {
        if (!$haschildren) {
            return self::SCHOOL;
        }
        if ((int) $category->parent === 0) {
            return self::AUTHORITY;
        }
        return self::DIVISION;
    }

    /**
     * One class, as words and facts.
     *
     * @param array $class
     * @param bool $showschool
     * @return array
     */
    private static function class_row(array $class, bool $showschool): array {
        $facts = [];
        if ($showschool && $class['school'] !== '') {
            $facts[] = [
                'word' => get_string('school', 'local_wwreports'),
                'fact' => $class['school'],
            ];
        }
        $facts[] = [
            'word' => get_string('students', 'local_wwreports'),
            'fact' => (string) $class['students'],
        ];
        $facts[] = [
            'word' => get_string('attendance', 'local_wwreports'),
            'fact' => self::format_rate($class['attendance']),
        ];
        $facts[] = [
            'word' => get_string('coursecompletion', 'local_wwreports'),
            'fact' => self::format_rate($class['coursecompletion']),
        ];
        $facts[] = [
            'word' => get_string('activities', 'local_wwreports'),
            'fact' => self::format_rate($class['activitycompletion']),
        ];
        return [
            'name' => $class['name'],
            'school' => $class['school'],
            'showschool' => $showschool,
            'facts' => $facts,
        ];
    }

    /**
     * Present and late marks, as a percentage of every mark in the window.
     *
     * @param int[] $courseids
     * @param int $from
     * @param int $until
     * @return float|null Null when no attendance has been taken.
     */
    private static function attendance_rate(array $courseids, int $from, int $until): ?float {
        global $DB;

        if (!$courseids || !$DB->get_manager()->table_exists('local_wwattendance_log')) {
            return null;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['fromtime'] = $from;
        $params['untiltime'] = $until;
        $rows = $DB->get_records_sql(
            "SELECT l.status, COUNT(1) AS marks
               FROM {local_wwattendance_log} l
               JOIN {local_wwattendance_session} s ON s.id = l.sessionid
              WHERE s.courseid {$insql}
                AND s.sessdate >= :fromtime
                AND s.sessdate <= :untiltime
           GROUP BY l.status",
            $params
        );
        $total = 0;
        $attended = 0;
        foreach ($rows as $row) {
            $total += (int) $row->marks;
            if ($row->status === 'present' || $row->status === 'late') {
                $attended += (int) $row->marks;
            }
        }
        if ($total === 0) {
            return null;
        }
        return 100 * $attended / $total;
    }

    /**
     * Students who completed this course during the window, as a percentage.
     *
     * A course that does not track completion has no rate.
     *
     * @param \stdClass $course
     * @param int[] $studentids
     * @param int $from
     * @param int $until
     * @return float|null
     */
    private static function course_rate(\stdClass $course, array $studentids, int $from, int $until): ?float {
        if (empty($course->enablecompletion) || !$studentids) {
            return null;
        }
        return 100 * self::course_completed((int) $course->id, $studentids, $from, $until) / count($studentids);
    }

    /**
     * Course completions inside the window for these students.
     *
     * @param int $courseid
     * @param int[] $studentids
     * @param int $from
     * @param int $until
     * @return int
     */
    private static function course_completed(int $courseid, array $studentids, int $from, int $until): int {
        global $DB;

        if (!$studentids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $params['fromtime'] = $from;
        $params['untiltime'] = $until;
        return (int) $DB->get_field_sql(
            "SELECT COUNT(1)
               FROM {course_completions}
              WHERE course = :courseid
                AND timecompleted >= :fromtime
                AND timecompleted <= :untiltime
                AND userid {$insql}",
            $params
        );
    }

    /**
     * Course completions across the courses that track them.
     *
     * @param \stdClass[] $courses Keyed by course id.
     * @param int[] $courseids Courses that overlap the term.
     * @param int $from
     * @param int $until
     * @return float|null
     */
    private static function course_rate_many(array $courses, array $courseids, int $from, int $until): ?float {
        $den = 0;
        $num = 0;
        foreach ($courseids as $courseid) {
            $course = $courses[$courseid] ?? null;
            if (!$course || empty($course->enablecompletion)) {
                continue;
            }
            $students = array_keys(access::students_in_courses([$courseid]));
            if (!$students) {
                continue;
            }
            $den += count($students);
            $num += self::course_completed($courseid, $students, $from, $until);
        }
        if ($den === 0) {
            return null;
        }
        return 100 * $num / $den;
    }

    /**
     * Completed activities during the window, as a percentage of tracked work.
     *
     * The denominator is tracked activities multiplied by active students.
     * A completion outside the window is not counted. A course with no tracked
     * activities has no rate.
     *
     * @param int $courseid
     * @param int[] $studentids
     * @param int $from
     * @param int $until
     * @return float|null
     */
    private static function activity_rate(int $courseid, array $studentids, int $from, int $until): ?float {
        $tracked = self::tracked_modules($courseid);
        if (!$tracked || !$studentids) {
            return null;
        }
        $done = self::activities_completed($tracked, $studentids, $from, $until);
        return 100 * $done / (count($tracked) * count($studentids));
    }

    /**
     * Completed activity rows inside the window.
     *
     * @param int[] $moduleids
     * @param int[] $studentids
     * @param int $from
     * @param int $until
     * @return int
     */
    private static function activities_completed(array $moduleids, array $studentids, int $from, int $until): int {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');

        if (!$moduleids || !$studentids) {
            return 0;
        }
        [$cmsql, $cmparams] = $DB->get_in_or_equal($moduleids, SQL_PARAMS_NAMED, 'cm');
        [$usql, $uparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'us');
        $params = $cmparams + $uparams + [
            'complete' => COMPLETION_COMPLETE,
            'pass' => COMPLETION_COMPLETE_PASS,
            'fromtime' => $from,
            'untiltime' => $until,
        ];
        return (int) $DB->get_field_sql(
            "SELECT COUNT(1)
               FROM {course_modules_completion}
              WHERE coursemoduleid {$cmsql}
                AND userid {$usql}
                AND completionstate IN (:complete, :pass)
                AND timemodified >= :fromtime
                AND timemodified <= :untiltime",
            $params
        );
    }

    /**
     * Activity completion across every overlapping course.
     *
     * @param int[] $courseids
     * @param int $from
     * @param int $until
     * @return float|null
     */
    private static function activity_rate_many(array $courseids, int $from, int $until): ?float {
        $den = 0;
        $num = 0;
        foreach ($courseids as $courseid) {
            $students = array_keys(access::students_in_courses([$courseid]));
            $tracked = self::tracked_modules($courseid);
            if (!$students || !$tracked) {
                continue;
            }
            $slots = count($tracked) * count($students);
            $den += $slots;
            $num += self::activities_completed($tracked, $students, $from, $until);
        }
        if ($den === 0) {
            return null;
        }
        return 100 * $num / $den;
    }

    /**
     * Visible activities that track completion.
     *
     * @param int $courseid
     * @return int[]
     */
    private static function tracked_modules(int $courseid): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'course_modules',
            'id',
            'course = :courseid AND completion <> 0 AND visible = 1 AND deletioninprogress = 0',
            ['courseid' => $courseid]
        );
        return array_map('intval', $ids);
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
}
