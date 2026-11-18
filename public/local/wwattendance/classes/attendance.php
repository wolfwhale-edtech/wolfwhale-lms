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

namespace local_wwattendance;

/**
 * Take attendance for one course on one day.
 *
 * @package    local_wwattendance
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance {
    /** @var string */
    public const PRESENT = 'present';

    /** @var string */
    public const ABSENT = 'absent';

    /** @var string */
    public const LATE = 'late';

    /** @var string[] */
    public const STATUSES = [self::PRESENT, self::ABSENT, self::LATE];

    /**
     * Whether the logged-in person may take attendance in this course.
     *
     * @param int $courseid
     * @return bool
     */
    public static function can_take(int $courseid): bool {
        if ($courseid <= 0) {
            return false;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return has_capability('local/wwattendance:take', $context);
    }

    /**
     * Refuse the page unless the logged-in person may take attendance here.
     *
     * @param int $courseid
     * @return \context_course
     */
    public static function require_take(int $courseid): \context_course {
        $context = \context_course::instance($courseid);
        require_capability('local/wwattendance:take', $context);
        return $context;
    }

    /**
     * Start of the site's day for a moment.
     *
     * The site timezone is shared by every teacher. A profile timezone must not
     * open a second sheet for the same course on the same day.
     *
     * @param int|null $time
     * @return int
     */
    public static function day_start(?int $time = null): int {
        return usergetmidnight($time ?? time(), \core_date::get_server_timezone());
    }

    /**
     * Enrolled students in the course. People who can take attendance are left out.
     *
     * @param int $courseid
     * @return \stdClass[] Keyed by user id.
     */
    public static function students(int $courseid): array {
        global $DB;

        $context = self::require_take($courseid);
        $users = $DB->get_records_sql(
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
        $kept = [];
        foreach ($users as $user) {
            if (has_capability('local/wwattendance:take', $context, $user->id)) {
                continue;
            }
            $kept[$user->id] = $user;
        }
        return $kept;
    }

    /**
     * Saved statuses for one day, keyed by user id.
     *
     * @param int $courseid
     * @param int|null $sessdate
     * @return array<int, string>
     */
    public static function marks(int $courseid, ?int $sessdate = null): array {
        global $DB;

        self::require_take($courseid);
        $sessdate = $sessdate ?? self::day_start();
        $session = $DB->get_record('local_wwattendance_session', [
            'courseid' => $courseid,
            'sessdate' => $sessdate,
        ]);
        if (!$session) {
            return [];
        }
        $logs = $DB->get_records('local_wwattendance_log', ['sessionid' => $session->id], '', 'id, userid, status');
        $marks = [];
        foreach ($logs as $log) {
            $marks[(int) $log->userid] = $log->status;
        }
        return $marks;
    }

    /**
     * Store today's choices. A person who is not a student in this course is ignored.
     *
     * An empty list does not create a session. A second save on the same day updates
     * the same session.
     *
     * @param int $courseid
     * @param array $statuses User id => present, absent, or late.
     * @param int|null $sessdate
     * @return int Session id, or 0 when nothing was stored.
     */
    public static function save(int $courseid, array $statuses, ?int $sessdate = null): int {
        global $DB, $USER;

        $context = self::require_take($courseid);
        $sessdate = $sessdate ?? self::day_start();
        $clean = [];
        foreach ($statuses as $userid => $status) {
            $status = (string) $status;
            if (!in_array($status, self::STATUSES, true)) {
                throw new \core\exception\moodle_exception('invalidstatus', 'local_wwattendance');
            }
            $clean[(int) $userid] = $status;
        }
        if (!$clean) {
            return 0;
        }

        $students = [];
        foreach (self::student_ids($courseid, $context) as $id) {
            $students[$id] = true;
        }
        $clean = array_intersect_key($clean, $students);
        if (!$clean) {
            return 0;
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $session = $DB->get_record('local_wwattendance_session', [
            'courseid' => $courseid,
            'sessdate' => $sessdate,
        ]);
        if ($session) {
            $session->takenby = (int) $USER->id;
            $session->timemodified = $now;
            $DB->update_record('local_wwattendance_session', $session);
            $sessionid = (int) $session->id;
        } else {
            $sessionid = (int) $DB->insert_record('local_wwattendance_session', (object) [
                'courseid' => $courseid,
                'sessdate' => $sessdate,
                'takenby' => (int) $USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        foreach ($clean as $userid => $status) {
            $existing = $DB->get_record('local_wwattendance_log', [
                'sessionid' => $sessionid,
                'userid' => $userid,
            ]);
            if ($existing) {
                $existing->status = $status;
                $existing->timemodified = $now;
                $DB->update_record('local_wwattendance_log', $existing);
            } else {
                $DB->insert_record('local_wwattendance_log', (object) [
                    'sessionid' => $sessionid,
                    'userid' => $userid,
                    'status' => $status,
                    'timemodified' => $now,
                ]);
            }
        }
        $transaction->allow_commit();
        return $sessionid;
    }

    /**
     * Student user ids in the course, excluding people who can take attendance.
     *
     * @param int $courseid
     * @param \context_course $context
     * @return int[]
     */
    private static function student_ids(int $courseid, \context_course $context): array {
        $ids = [];
        foreach (self::students_unchecked($courseid) as $user) {
            if (has_capability('local/wwattendance:take', $context, $user->id)) {
                continue;
            }
            $ids[] = (int) $user->id;
        }
        return $ids;
    }

    /**
     * Enrolled students without a permission check. Callers must already have checked.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    private static function students_unchecked(int $courseid): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT DISTINCT u.id
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
                AND e.status = :enabled",
            [
                'level' => CONTEXT_COURSE,
                'courseid' => $courseid,
                'student' => 'student',
                'active' => ENROL_USER_ACTIVE,
                'enabled' => ENROL_INSTANCE_ENABLED,
            ]
        );
    }
}
