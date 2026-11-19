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

namespace local_wwadmin;

/**
 * One school's current counts.
 *
 * Attendance is read from the attendance tables. When a term is passed, the
 * attendance rate and the overdue count use that term's dates. Student,
 * teacher, and course counts are the people and courses there now.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class snapshot {
    /**
     * Counts for one school.
     *
     * @param int $categoryid
     * @param int|null $from Include attendance and overdue work from this time.
     * @param int|null $to Include attendance and overdue work up to this time.
     * @return \stdClass students, teachers, courses, attendance (float|null), overdue.
     */
    public static function for_category(int $categoryid, ?int $from = null, ?int $to = null): \stdClass {
        scope::require($categoryid, 'local/wwadmin:view');
        $tree = scope::tree_ids($categoryid);
        return (object) [
            'students' => self::count_archetypes($tree, ['student']),
            'teachers' => self::count_archetypes($tree, ['teacher', 'editingteacher']),
            'courses' => count(scope::courses($tree)),
            'attendance' => self::attendance_rate($tree, $from, $to),
            'overdue' => self::overdue($tree, $from, $to),
        ];
    }

    /**
     * A percentage, or the word for none when no attendance has been taken.
     *
     * @param float|null $rate
     * @return string
     */
    public static function format_rate(?float $rate): string {
        if ($rate === null) {
            return get_string('none', 'local_wwadmin');
        }
        return get_string('percent', 'local_wwadmin', self::rate_text($rate));
    }

    /**
     * Active enrolled people with one of these role types.
     *
     * @param int[] $categoryids
     * @param string[] $archetypes
     * @return int
     */
    public static function count_archetypes(array $categoryids, array $archetypes): int {
        global $DB;

        if (!$categoryids || !$archetypes) {
            return 0;
        }
        [$catsql, $catparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        [$archsql, $archparams] = $DB->get_in_or_equal($archetypes, SQL_PARAMS_NAMED, 'arch');
        $params = array_merge($catparams, $archparams, [
            'level' => CONTEXT_COURSE,
            'active' => ENROL_USER_ACTIVE,
            'enabled' => ENROL_INSTANCE_ENABLED,
            'deleted' => 0,
            'suspended' => 0,
        ]);
        $sql = "SELECT COUNT(DISTINCT u.id)
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = :active
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.status = :enabled
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :level
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype {$archsql}
                 WHERE u.deleted = :deleted
                   AND u.suspended = :suspended
                   AND c.category {$catsql}";
        return (int) $DB->get_field_sql($sql, $params);
    }

    /**
     * Present and late marks, as a percentage of every mark.
     *
     * @param int[] $categoryids
     * @param int|null $from
     * @param int|null $to
     * @return float|null Null when there are no marks.
     */
    public static function attendance_rate(array $categoryids, ?int $from = null, ?int $to = null): ?float {
        global $DB;

        if (!$categoryids || !$DB->get_manager()->table_exists('local_wwattendance_log')) {
            return null;
        }
        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $where = "c.category {$insql}";
        if ($from !== null) {
            $where .= ' AND s.sessdate >= :datefrom';
            $params['datefrom'] = $from;
        }
        if ($to !== null) {
            $where .= ' AND s.sessdate <= :dateto';
            $params['dateto'] = $to;
        }
        $sql = "SELECT l.status, COUNT(1) AS marks
                  FROM {local_wwattendance_log} l
                  JOIN {local_wwattendance_session} s ON s.id = l.sessionid
                  JOIN {course} c ON c.id = s.courseid
                 WHERE {$where}
              GROUP BY l.status";
        $rows = $DB->get_records_sql($sql, $params);
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
        return round(100 * $attended / $total, 1);
    }

    /**
     * Overdue assignment and quiz work for active students.
     *
     * A submitted assignment, or a finished quiz, is not counted. An extension
     * that moves the date into the future is not counted. Work outside the
     * optional date range is not counted.
     *
     * @param int[] $categoryids
     * @param int|null $from
     * @param int|null $to
     * @param int|null $now
     * @return int
     */
    public static function overdue(array $categoryids, ?int $from = null, ?int $to = null, ?int $now = null): int {
        $now = $now ?? time();
        $courses = scope::courses($categoryids);
        if (!$courses) {
            return 0;
        }
        $courseids = array_map('intval', array_keys($courses));
        $students = self::students_by_course($courseids);
        return self::overdue_assigns($courseids, $students, $from, $to, $now)
            + self::overdue_quizzes($courseids, $students, $from, $to, $now);
    }

    /**
     * Active students in each course.
     *
     * @param int[] $courseids
     * @return array<int, array<int, bool>>
     */
    private static function students_by_course(array $courseids): array {
        global $DB;

        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['level'] = CONTEXT_COURSE;
        $params['student'] = 'student';
        $params['active'] = ENROL_USER_ACTIVE;
        $params['enabled'] = ENROL_INSTANCE_ENABLED;
        $params['deleted'] = 0;
        $params['suspended'] = 0;
        $sql = "SELECT DISTINCT c.id AS courseid, u.id AS userid
                  FROM {user} u
                  JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = :active
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.status = :enabled
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :level
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = :student
                 WHERE u.deleted = :deleted
                   AND u.suspended = :suspended
                   AND c.id {$insql}";
        $map = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $row) {
            $map[(int) $row->courseid][(int) $row->userid] = true;
        }
        $records->close();
        return $map;
    }

    /**
     * Overdue individual assignments.
     *
     * @param int[] $courseids
     * @param array<int, array<int, bool>> $students
     * @param int|null $from
     * @param int|null $to
     * @param int $now
     * @return int
     */
    private static function overdue_assigns(array $courseids, array $students, ?int $from, ?int $to, int $now): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['assign'] = 'assign';
        $assigns = $DB->get_records_sql(
            "SELECT a.id, a.course, a.duedate
               FROM {assign} a
               JOIN {course_modules} cm ON cm.instance = a.id AND cm.course = a.course
                    AND cm.visible = 1 AND cm.deletioninprogress = 0
               JOIN {modules} m ON m.id = cm.module AND m.name = :assign
              WHERE a.teamsubmission = 0
                AND a.course {$insql}",
            $params
        );
        if (!$assigns) {
            return 0;
        }
        [$asql, $aparams] = $DB->get_in_or_equal(array_keys($assigns), SQL_PARAMS_NAMED, 'asg');
        $flags = [];
        foreach ($DB->get_records_select('assign_user_flags', "assignment {$asql}", $aparams, '', 'id, assignment, userid, extensionduedate') as $flag) {
            $flags[(int) $flag->assignment][(int) $flag->userid] = (int) $flag->extensionduedate;
        }
        $submitted = [];
        $params = $aparams;
        $params['submitted'] = 'submitted';
        foreach ($DB->get_records_select(
            'assign_submission',
            "assignment {$asql} AND latest = 1 AND status = :submitted",
            $params,
            '',
            'id, assignment, userid'
        ) as $submission) {
            $submitted[(int) $submission->assignment][(int) $submission->userid] = true;
        }

        $count = 0;
        foreach ($assigns as $assign) {
            foreach ($students[(int) $assign->course] ?? [] as $userid => $unused) {
                $due = (int) $assign->duedate;
                $extension = $flags[(int) $assign->id][$userid] ?? 0;
                if ($extension > $due) {
                    $due = $extension;
                }
                if (!self::counts_as_overdue($due, $from, $to, $now)) {
                    continue;
                }
                if (!empty($submitted[(int) $assign->id][$userid])) {
                    continue;
                }
                $count++;
            }
        }
        return $count;
    }

    /**
     * Overdue quizzes.
     *
     * @param int[] $courseids
     * @param array<int, array<int, bool>> $students
     * @param int|null $from
     * @param int|null $to
     * @param int $now
     * @return int
     */
    private static function overdue_quizzes(array $courseids, array $students, ?int $from, ?int $to, int $now): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['quiz'] = 'quiz';
        $quizzes = $DB->get_records_sql(
            "SELECT q.id, q.course, q.duedate, q.timeclose
               FROM {quiz} q
               JOIN {course_modules} cm ON cm.instance = q.id AND cm.course = q.course
                    AND cm.visible = 1 AND cm.deletioninprogress = 0
               JOIN {modules} m ON m.id = cm.module AND m.name = :quiz
              WHERE q.course {$insql}",
            $params
        );
        if (!$quizzes) {
            return 0;
        }
        [$qsql, $qparams] = $DB->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'qz');
        $overrides = [];
        foreach ($DB->get_records_select(
            'quiz_overrides',
            "quiz {$qsql} AND userid IS NOT NULL",
            $qparams,
            '',
            'id, quiz, userid, duedate, timeclose'
        ) as $override) {
            $overrides[(int) $override->quiz][(int) $override->userid] = $override;
        }
        $finished = [];
        $params = $qparams;
        $params['finished'] = 'finished';
        foreach ($DB->get_records_select(
            'quiz_attempts',
            "quiz {$qsql} AND state = :finished AND preview = 0",
            $params,
            '',
            'id, quiz, userid'
        ) as $attempt) {
            $finished[(int) $attempt->quiz][(int) $attempt->userid] = true;
        }

        $count = 0;
        foreach ($quizzes as $quiz) {
            foreach ($students[(int) $quiz->course] ?? [] as $userid => $unused) {
                $due = (int) $quiz->duedate;
                $close = (int) $quiz->timeclose;
                $override = $overrides[(int) $quiz->id][$userid] ?? null;
                if ($override) {
                    if ($override->duedate !== null) {
                        $due = (int) $override->duedate;
                    }
                    if ($override->timeclose !== null) {
                        $close = (int) $override->timeclose;
                    }
                }
                $deadline = self::quiz_deadline($due, $close);
                if (!self::counts_as_overdue($deadline, $from, $to, $now)) {
                    continue;
                }
                if (!empty($finished[(int) $quiz->id][$userid])) {
                    continue;
                }
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether a due time is overdue and inside an optional range.
     *
     * @param int $due
     * @param int|null $from
     * @param int|null $to
     * @param int $now
     * @return bool
     */
    private static function counts_as_overdue(int $due, ?int $from, ?int $to, int $now): bool {
        if ($due <= 0 || $due >= $now) {
            return false;
        }
        if ($from !== null && $due < $from) {
            return false;
        }
        if ($to !== null && $due > $to) {
            return false;
        }
        return true;
    }

    /**
     * The due date, the close date, or the earlier of the two.
     *
     * @param int $due
     * @param int $close
     * @return int
     */
    private static function quiz_deadline(int $due, int $close): int {
        if ($due > 0 && $close > 0) {
            return min($due, $close);
        }
        return max($due, $close);
    }

    /**
     * One decimal, without a trailing zero.
     *
     * @param float $rate
     * @return string
     */
    private static function rate_text(float $rate): string {
        $text = number_format($rate, 1, '.', '');
        if (str_ends_with($text, '.0')) {
            return substr($text, 0, -2);
        }
        return $text;
    }
}
