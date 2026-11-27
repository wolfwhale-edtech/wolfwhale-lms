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
 * Who may open a report card, and which courses they may see on it.
 *
 * A parent may open a linked child. A teacher may open a student in a course
 * they teach, and only that course is included. An administrator may open a
 * student enrolled in a school they administer, and only courses in that
 * school are included.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * Name fields needed by fullname().
     *
     * @var string
     */
    private const USER_FIELDS = 'u.id, u.firstname, u.lastname, u.firstnamephonetic, '
        . 'u.lastnamephonetic, u.middlename, u.alternatename';

    /**
     * Refuse unless this person may open this student's report card.
     *
     * @param int $viewer
     * @param int $student
     */
    public static function require_view(int $viewer, int $student): void {
        if (!self::can_view($viewer, $student)) {
            throw new \moodle_exception('cannotview', 'local_wwreports');
        }
    }

    /**
     * True when this person may open this student's report card.
     *
     * @param int $viewer
     * @param int $student
     * @return bool
     */
    public static function can_view(int $viewer, int $student): bool {
        if (!self::real_person($viewer) || !self::real_person($student) || $viewer === $student) {
            return false;
        }
        if (self::is_parent_of($viewer, $student)) {
            return true;
        }
        if (self::shared_course_ids($viewer, $student)) {
            return true;
        }
        return self::administered_course_ids($viewer, $student) !== [];
    }

    /**
     * True when this teacher may store a comment for this student in this course.
     *
     * @param int $viewer
     * @param int $student
     * @param int $courseid
     * @return bool
     */
    public static function can_comment(int $viewer, int $student, int $courseid): bool {
        if ($courseid <= 0 || !self::can_view($viewer, $student)) {
            return false;
        }
        $taught = \local_wolfwhale\teacher::course_ids_for($viewer);
        if (!in_array($courseid, $taught, true)) {
            return false;
        }
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if (!has_capability('local/wwreports:comment', $context, $viewer)) {
            return false;
        }
        return is_enrolled($context, $student, '', true);
    }

    /**
     * Courses on this student's card for this viewer.
     *
     * @param int $viewer
     * @param int $student
     * @return \stdClass[]
     */
    public static function courses_for(int $viewer, int $student): array {
        if (!self::can_view($viewer, $student)) {
            return [];
        }
        $enrolled = enrol_get_users_courses(
            $student,
            true,
            'id, fullname, shortname, showgrades, visible, startdate, enddate, category, enablecompletion'
        );
        $parent = self::is_parent_of($viewer, $student);
        $taught = array_fill_keys(\local_wolfwhale\teacher::course_ids_for($viewer), true);
        $admin = array_fill_keys(self::administered_course_ids($viewer, $student), true);
        $kept = [];
        foreach ($enrolled as $course) {
            if ((int) $course->id === (int) SITEID) {
                continue;
            }
            if (empty($course->visible)) {
                $context = \context_course::instance($course->id);
                if (!has_capability('moodle/course:viewhiddencourses', $context, $viewer)) {
                    continue;
                }
            }
            $id = (int) $course->id;
            if ($parent || isset($taught[$id]) || isset($admin[$id])) {
                $kept[] = $course;
            }
        }
        return $kept;
    }

    /**
     * People this viewer may switch between on the report card.
     *
     * @param int $viewer
     * @return \stdClass[] Keyed by user id.
     */
    public static function roster(int $viewer): array {
        $users = [];
        foreach (self::linked_children($viewer) as $user) {
            $users[(int) $user->id] = $user;
        }
        foreach (self::students_taught($viewer) as $user) {
            $users[(int) $user->id] = $user;
        }
        if (\local_wwadmin\scope::is_admin($viewer)) {
            foreach (self::students_administered($viewer) as $user) {
                $users[(int) $user->id] = $user;
            }
        }
        uasort($users, static function (\stdClass $a, \stdClass $b): int {
            return strcasecmp($a->lastname . ' ' . $a->firstname, $b->lastname . ' ' . $b->firstname)
                ?: ((int) $a->id <=> (int) $b->id);
        });
        return $users;
    }

    /**
     * Active students in courses this person teaches.
     *
     * @param int|null $userid
     * @return \stdClass[]
     */
    public static function students_taught(?int $userid = null): array {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        return self::students_in_courses(\local_wolfwhale\teacher::course_ids_for($userid));
    }

    /**
     * Active students in schools this person administers.
     *
     * @param int $userid
     * @return \stdClass[]
     */
    public static function students_administered(int $userid): array {
        if (!\local_wwadmin\scope::is_admin($userid)) {
            return [];
        }
        $courses = \local_wwadmin\scope::courses(\local_wwadmin\scope::visible_ids($userid));
        return self::students_in_courses(array_map('intval', array_keys($courses)));
    }

    /**
     * Active students with the student role in these courses.
     *
     * @param int[] $courseids
     * @return \stdClass[] Keyed by user id.
     */
    public static function students_in_courses(array $courseids): array {
        global $DB;

        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $now = \core\di::get(\core\clock::class)->time();
        $params += [
            'level' => CONTEXT_COURSE,
            'student' => 'student',
            'active' => ENROL_USER_ACTIVE,
            'enabled' => ENROL_INSTANCE_ENABLED,
            'deleted' => 0,
            'suspended' => 0,
            'now1' => $now,
            'now2' => $now,
        ];
        return $DB->get_records_sql(
            "SELECT DISTINCT " . self::USER_FIELDS . "
               FROM {user} u
               JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = :active
                    AND ue.timestart < :now1 AND (ue.timeend = 0 OR ue.timeend > :now2)
               JOIN {enrol} e ON e.id = ue.enrolid AND e.status = :enabled
               JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = :level
               JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid AND r.archetype = :student
              WHERE u.deleted = :deleted
                AND u.suspended = :suspended
                AND e.courseid {$insql}
           ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC",
            $params
        );
    }

    /**
     * Linked children of one parent.
     *
     * @param int $parentid
     * @return \stdClass[]
     */
    public static function linked_children(int $parentid): array {
        global $DB;

        $roleid = \local_wolfwhale\parents::role_id();
        if (!$roleid || $parentid <= 0) {
            return [];
        }
        return $DB->get_records_sql(
            "SELECT " . self::USER_FIELDS . "
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {user} u ON u.id = ctx.instanceid
              WHERE ra.userid = :userid
                AND ra.roleid = :roleid
                AND ctx.contextlevel = :level
                AND u.deleted = 0
                AND u.id <> :self
           ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC",
            [
                'userid' => $parentid,
                'roleid' => $roleid,
                'level' => CONTEXT_USER,
                'self' => $parentid,
            ]
        );
    }

    /**
     * True when the parent role is assigned in this child's user context.
     *
     * @param int $parentid
     * @param int $childid
     * @return bool
     */
    public static function is_parent_of(int $parentid, int $childid): bool {
        $roleid = \local_wolfwhale\parents::role_id();
        if (!$roleid || $parentid <= 0 || $childid <= 0 || $parentid === $childid) {
            return false;
        }
        $context = \context_user::instance($childid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return user_has_role_assignment($parentid, $roleid, $context->id);
    }

    /**
     * Course ids this teacher shares with this enrolled student.
     *
     * @param int $teacher
     * @param int $student
     * @return int[]
     */
    private static function shared_course_ids(int $teacher, int $student): array {
        $taught = array_fill_keys(\local_wolfwhale\teacher::course_ids_for($teacher), true);
        if (!$taught) {
            return [];
        }
        $shared = [];
        foreach (enrol_get_users_courses($student, true, 'id') as $course) {
            $id = (int) $course->id;
            if (isset($taught[$id])) {
                $shared[] = $id;
            }
        }
        return $shared;
    }

    /**
     * This student's courses that sit in a school the viewer administers.
     *
     * @param int $viewer
     * @param int $student
     * @return int[]
     */
    private static function administered_course_ids(int $viewer, int $student): array {
        if (!\local_wwadmin\scope::is_admin($viewer)) {
            return [];
        }
        $visible = array_fill_keys(\local_wwadmin\scope::visible_ids($viewer), true);
        if (!$visible) {
            return [];
        }
        $ids = [];
        $courses = enrol_get_users_courses($student, true, 'id, category, visible');
        foreach ($courses as $course) {
            if ((int) $course->id === (int) SITEID || !isset($visible[(int) $course->category])) {
                continue;
            }
            $ids[] = (int) $course->id;
        }
        return $ids;
    }

    /**
     * A signed-in person who is not a guest and not deleted.
     *
     * @param int $userid
     * @return bool
     */
    private static function real_person(int $userid): bool {
        global $DB;

        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        return $DB->record_exists('user', ['id' => $userid, 'deleted' => 0]);
    }
}
