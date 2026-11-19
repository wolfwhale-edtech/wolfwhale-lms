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

namespace local_wwpoints;

/**
 * The points ledger.
 *
 * A source key is unique, so the same event cannot write a second row.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class points {
    /**
     * Points configured for one kind of event.
     *
     * @param string $key assign, completion, present, or badge.
     * @return int
     */
    public static function amount(string $key): int {
        $value = get_config('local_wwpoints', 'points_' . $key);
        if ($value === false || $value === null || $value === '') {
            $defaults = [
                'assign' => 10,
                'completion' => 5,
                'present' => 5,
                'badge' => 15,
            ];
            $value = $defaults[$key] ?? 0;
        }
        return (int) $value;
    }

    /**
     * Write one ledger row. A repeated source key writes nothing.
     *
     * @param int $userid
     * @param int $courseid Zero when the event is not in a course.
     * @param int $amount
     * @param string $reason
     * @param string $eventname
     * @param string $sourcekey
     * @param int $awardedby
     * @return bool True when a new row was stored.
     */
    public static function record(
        int $userid,
        int $courseid,
        int $amount,
        string $reason,
        string $eventname,
        string $sourcekey,
        int $awardedby = 0
    ): bool {
        global $DB;

        $reason = trim($reason);
        $sourcekey = trim($sourcekey);
        if ($userid <= 0 || $amount === 0 || $reason === '' || $sourcekey === '') {
            return false;
        }
        if ($DB->record_exists('local_wwpoints_ledger', ['sourcekey' => $sourcekey])) {
            return false;
        }
        $reason = \core_text::substr($reason, 0, 255);
        $eventname = \core_text::substr($eventname, 0, 255);
        $sourcekey = \core_text::substr($sourcekey, 0, 255);
        try {
            $DB->insert_record('local_wwpoints_ledger', (object) [
                'userid' => $userid,
                'courseid' => max(0, $courseid),
                'amount' => $amount,
                'reason' => $reason,
                'eventname' => $eventname,
                'sourcekey' => $sourcekey,
                'awardedby' => max(0, $awardedby),
                'timecreated' => time(),
            ]);
        } catch (\dml_exception $error) {
            if ($DB->record_exists('local_wwpoints_ledger', ['sourcekey' => $sourcekey])) {
                return false;
            }
            throw $error;
        }
        self::reached_level($userid, $courseid, $amount);
        return true;
    }

    /**
     * Tell the site when this award moves the student to a higher level.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $amount
     */
    private static function reached_level(int $userid, int $courseid, int $amount): void {
        global $DB;

        $total = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0) FROM {local_wwpoints_ledger} WHERE userid = ?",
            [$userid]
        );
        $before = levels::for_points($total - $amount);
        $after = levels::for_points($total);
        if ($after <= $before) {
            return;
        }
        $context = \context_user::instance($userid);
        if ($courseid > 0) {
            $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
            if ($coursecontext) {
                $context = $coursecontext;
            }
        }
        \local_wwpoints\event\level_reached::create([
            'context' => $context,
            'relateduserid' => $userid,
            'other' => [
                'level' => $after,
                'points' => $total,
            ],
        ])->trigger();
    }

    /**
     * Give or take points in a course this person teaches.
     *
     * @param int $courseid
     * @param int $studentid
     * @param int $amount A positive number. Taking points stores the negative.
     * @param string $reason
     * @param bool $take
     */
    public static function adjust(int $courseid, int $studentid, int $amount, string $reason, bool $take): void {
        global $USER;

        self::require_award($courseid);
        $reason = trim(clean_param($reason, PARAM_TEXT));
        if ($amount < 1 || $reason === '' || !self::is_student($courseid, $studentid)) {
            throw new \moodle_exception('invalidaward', 'local_wwpoints');
        }
        $key = 'manual:' . (int) $USER->id . ':' . $studentid . ':' . $courseid . ':' . bin2hex(random_bytes(8));
        self::record(
            $studentid,
            $courseid,
            $take ? -$amount : $amount,
            $reason,
            'manual',
            $key,
            (int) $USER->id
        );
    }

    /**
     * Refuse unless this person may award points in this course.
     *
     * @param int $courseid
     * @return \context_course
     */
    public static function require_award(int $courseid): \context_course {
        global $USER;

        $context = \context_course::instance($courseid);
        require_capability('local/wwpoints:award', $context);
        if (!is_siteadmin() && !in_array($courseid, self::teaching_ids((int) $USER->id), true)) {
            throw new \moodle_exception('invalidaward', 'local_wwpoints');
        }
        return $context;
    }

    /**
     * Courses where the logged-in person may use a course capability.
     *
     * A teacher role on the whole site is not included.
     *
     * @param string $capability
     * @return \stdClass[]
     */
    public static function courses_for(string $capability): array {
        global $DB, $USER;

        $ids = self::teaching_ids((int) $USER->id);
        if (!$ids) {
            return [];
        }
        $courses = [];
        foreach ($ids as $id) {
            $context = \context_course::instance($id, IGNORE_MISSING);
            if (!$context || !has_capability($capability, $context)) {
                continue;
            }
            $course = $DB->get_record('course', ['id' => $id], 'id, fullname, category, visible', IGNORE_MISSING);
            if (!$course || (int) $course->id === (int) SITEID) {
                continue;
            }
            if (empty($course->visible) && !has_capability('moodle/course:viewhiddencourses', $context)) {
                continue;
            }
            $courses[] = $course;
        }
        usort($courses, static function (\stdClass $a, \stdClass $b): int {
            return strcasecmp($a->fullname, $b->fullname);
        });
        return $courses;
    }

    /**
     * Course ids where this person holds a teacher role on the course or its category.
     *
     * @param int $userid
     * @return int[]
     */
    public static function teaching_ids(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }
        $assigns = $DB->get_records_sql(
            "SELECT ctx.id, ctx.contextlevel, ctx.instanceid, ctx.path
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid
                AND (r.archetype = :editing OR r.archetype = :teacher)
                AND ctx.contextlevel IN (:courselevel, :catlevel)",
            [
                'userid' => $userid,
                'editing' => 'editingteacher',
                'teacher' => 'teacher',
                'courselevel' => CONTEXT_COURSE,
                'catlevel' => CONTEXT_COURSECAT,
            ]
        );
        $ids = [];
        foreach ($assigns as $ctx) {
            if ((int) $ctx->contextlevel === CONTEXT_COURSE) {
                if ((int) $ctx->instanceid !== (int) SITEID) {
                    $ids[(int) $ctx->instanceid] = true;
                }
                continue;
            }
            $like = $DB->sql_like('child.path', ':path');
            $childids = $DB->get_fieldset_sql(
                "SELECT child.instanceid
                   FROM {context} child
                  WHERE child.contextlevel = :courselevel
                    AND child.instanceid <> :siteid
                    AND {$like}",
                [
                    'path' => $DB->sql_like_escape($ctx->path) . '/%',
                    'courselevel' => CONTEXT_COURSE,
                    'siteid' => SITEID,
                ]
            );
            foreach ($childids as $id) {
                $ids[(int) $id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * Enrolled students in a course this person may award.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function students(int $courseid): array {
        global $DB;

        self::require_award($courseid);
        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic,
                            u.lastnamephonetic, u.middlename, u.alternatename
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :level
                AND ctx.instanceid = :courseid
                AND r.archetype = :student
                AND u.deleted = 0
                AND ue.status = :active
                AND e.status = :enabled
           ORDER BY u.lastname ASC, u.firstname ASC",
            [
                'level' => CONTEXT_COURSE,
                'courseid' => $courseid,
                'student' => 'student',
                'active' => ENROL_USER_ACTIVE,
                'enabled' => ENROL_INSTANCE_ENABLED,
            ]
        );
    }

    /**
     * Whether this person is an active student in the course.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function is_student(int $courseid, int $userid): bool {
        global $DB;

        if ($courseid <= 0 || $userid <= 0) {
            return false;
        }
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user_enrolments} ue ON ue.userid = ra.userid
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ctx.instanceid
               JOIN {user} u ON u.id = ra.userid
              WHERE ctx.contextlevel = :level
                AND ctx.instanceid = :courseid
                AND ra.userid = :userid
                AND r.archetype = :student
                AND u.deleted = 0
                AND ue.status = :active
                AND e.status = :enabled",
            [
                'level' => CONTEXT_COURSE,
                'courseid' => $courseid,
                'userid' => $userid,
                'student' => 'student',
                'active' => ENROL_USER_ACTIVE,
                'enabled' => ENROL_INSTANCE_ENABLED,
            ]
        );
    }

    /**
     * All points for one person.
     *
     * @param int $userid
     * @return int
     */
    public static function total(int $userid): int {
        global $DB;

        if ($userid <= 0) {
            return 0;
        }
        return (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0) FROM {local_wwpoints_ledger} WHERE userid = ?",
            [$userid]
        );
    }

    /**
     * Points for one person in one course.
     *
     * @param int $userid
     * @param int $courseid
     * @return int
     */
    public static function course_total(int $userid, int $courseid): int {
        global $DB;

        return (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0)
               FROM {local_wwpoints_ledger}
              WHERE userid = ? AND courseid = ?",
            [$userid, $courseid]
        );
    }

    /**
     * Points earned in these courses up to a time.
     *
     * @param int[] $courseids
     * @param int $until
     * @return int
     */
    public static function sum_courses(array $courseids, int $until): int {
        global $DB;

        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        if (!$courseids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['until'] = $until;
        return (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0)
               FROM {local_wwpoints_ledger}
              WHERE courseid {$insql}
                AND timecreated <= :until",
            $params
        );
    }

    /**
     * Ledger rows for a school export.
     *
     * A row is included when its course is in the school. A row with no course
     * is included only for a person who is in the school. Another school's
     * course is never included.
     *
     * @param int[] $courseids
     * @param int[] $userids
     * @return array[]
     */
    public static function export_rows(array $courseids, array $userids): array {
        global $DB;

        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$courseids && !$userids) {
            return [];
        }
        $where = [];
        $params = [];
        if ($courseids) {
            [$csql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
            $where[] = "courseid {$csql}";
            $params += $cparams;
        }
        if ($userids) {
            [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $where[] = "(courseid = 0 AND userid {$usql})";
            $params += $uparams;
        }
        $sql = "SELECT id, userid, courseid, amount, reason, eventname, sourcekey, awardedby, timecreated
                  FROM {local_wwpoints_ledger}
                 WHERE " . implode(' OR ', $where) . "
              ORDER BY timecreated ASC, id ASC";
        $rows = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $rows[] = [
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'amount' => (int) $record->amount,
                'reason' => $record->reason,
                'eventname' => $record->eventname,
                'sourcekey' => $record->sourcekey,
                'awardedby' => (int) $record->awardedby,
                'timecreated' => (int) $record->timecreated,
            ];
        }
        $records->close();
        return $rows;
    }

    /**
     * A name with no HTML.
     *
     * @param string $value
     * @return string
     */
    public static function plain(string $value): string {
        $text = format_string($value, true, ['escape' => false, 'filter' => false]);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }
}
