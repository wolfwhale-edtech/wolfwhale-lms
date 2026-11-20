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

namespace local_wwupdates;

/**
 * Who may be told about one child.
 *
 * A parent is a person who holds the parent role in that child's user context.
 * A course counts only when the child is enrolled in it. Another school's
 * parent is not in that list.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audience {
    /**
     * Parent and child pairs from real parent-role links.
     *
     * @return \stdClass[]
     */
    public static function pairs(): array {
        global $DB;

        $roleid = self::role_id();
        if (!$roleid) {
            return [];
        }
        return $DB->get_records_sql(
            "SELECT ra.id, ra.userid AS parentid, ctx.instanceid AS childid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {user} parent ON parent.id = ra.userid
               JOIN {user} child ON child.id = ctx.instanceid
              WHERE ra.roleid = :roleid
                AND ctx.contextlevel = :level
                AND parent.deleted = 0
                AND child.deleted = 0
                AND ra.userid <> ctx.instanceid",
            ['roleid' => $roleid, 'level' => CONTEXT_USER]
        );
    }

    /**
     * Parents linked to this child.
     *
     * @param int $childid
     * @return int[]
     */
    public static function parent_ids(int $childid): array {
        global $DB;

        $roleid = self::role_id();
        if (!$roleid || $childid <= 0) {
            return [];
        }
        $context = \context_user::instance($childid, IGNORE_MISSING);
        if (!$context) {
            return [];
        }
        $ids = $DB->get_fieldset_sql(
            "SELECT ra.userid
               FROM {role_assignments} ra
               JOIN {user} u ON u.id = ra.userid
              WHERE ra.roleid = ?
                AND ra.contextid = ?
                AND ra.userid <> ?
                AND u.deleted = 0
                AND u.suspended = 0",
            [$roleid, $context->id, $childid]
        );
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * True when this parent is linked to this child.
     *
     * @param int $parentid
     * @param int $childid
     * @return bool
     */
    public static function linked(int $parentid, int $childid): bool {
        return in_array($parentid, self::parent_ids($childid), true);
    }

    /**
     * True when the child is enrolled in the course.
     *
     * @param int $childid
     * @param int $courseid
     * @return bool
     */
    public static function enrolled(int $childid, int $courseid): bool {
        if ($childid <= 0 || $courseid <= 0 || $courseid === (int) SITEID) {
            return false;
        }
        $courses = enrol_get_users_courses($childid, true, 'id');
        return isset($courses[$courseid]);
    }

    /**
     * The top-level category that holds most of this child's courses.
     *
     * @param int $childid
     * @return int
     */
    public static function primary_school(int $childid): int {
        $courses = enrol_get_users_courses($childid, true, 'id, category');
        $counts = [];
        foreach ($courses as $course) {
            if ((int) $course->id === (int) SITEID) {
                continue;
            }
            $root = self::root_id((int) $course->category);
            if ($root > 0) {
                $counts[$root] = ($counts[$root] ?? 0) + 1;
            }
        }
        if (!$counts) {
            return 0;
        }
        arsort($counts);
        return (int) array_key_first($counts);
    }

    /**
     * School name for a course.
     *
     * @param int $courseid
     * @return string
     */
    public static function school_name_for_course(int $courseid): string {
        $course = get_course($courseid);
        return self::category_name(self::root_id((int) $course->category));
    }

    /**
     * A category name with the tags removed.
     *
     * @param int $categoryid
     * @return string
     */
    public static function category_name(int $categoryid): string {
        global $DB;

        if ($categoryid <= 0) {
            return '';
        }
        $name = $DB->get_field('course_categories', 'name', ['id' => $categoryid]);
        return self::plain((string) $name);
    }

    /**
     * The person's first name.
     *
     * @param int $userid
     * @return string
     */
    public static function first_name(int $userid): string {
        global $DB;

        $name = $DB->get_field('user', 'firstname', ['id' => $userid]);
        return self::plain((string) $name);
    }

    /**
     * True when this person teaches the child in one of the child's courses.
     *
     * @param int $teacherid
     * @param int $childid
     * @return bool
     */
    public static function teaches(int $teacherid, int $childid): bool {
        return self::shared_course($teacherid, $childid) > 0;
    }

    /**
     * One course where this teacher teaches this enrolled child.
     *
     * @param int $teacherid
     * @param int $childid
     * @return int
     */
    public static function shared_course(int $teacherid, int $childid): int {
        global $DB;

        if ($teacherid <= 0 || $teacherid === $childid) {
            return 0;
        }
        $courses = enrol_get_users_courses($childid, true, 'id');
        foreach ($courses as $course) {
            if ((int) $course->id === (int) SITEID) {
                continue;
            }
            $context = \context_course::instance($course->id);
            $contextids = [$context->id];
            $parent = $context->get_parent_context();
            while ($parent && (int) $parent->contextlevel >= CONTEXT_COURSECAT) {
                $contextids[] = $parent->id;
                $parent = $parent->get_parent_context();
            }
            [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
            $params['userid'] = $teacherid;
            $params['editing'] = 'editingteacher';
            $params['teacher'] = 'teacher';
            $found = $DB->record_exists_sql(
                "SELECT 1
                   FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                  WHERE ra.userid = :userid
                    AND ra.contextid {$insql}
                    AND r.archetype IN (:editing, :teacher)",
                $params
            );
            if ($found) {
                return (int) $course->id;
            }
        }
        return 0;
    }

    /**
     * Plain text.
     *
     * @param string $value
     * @return string
     */
    public static function plain(string $value): string {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));
        return is_string($text) ? $text : '';
    }

    /**
     * The top category id on a category path.
     *
     * @param int $categoryid
     * @return int
     */
    public static function root_id(int $categoryid): int {
        global $DB;

        if ($categoryid <= 0) {
            return 0;
        }
        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if (!$path) {
            return 0;
        }
        $parts = array_values(array_filter(array_map('intval', explode('/', (string) $path))));
        return $parts[0] ?? 0;
    }

    /**
     * The parent role id, or 0.
     *
     * @return int
     */
    private static function role_id(): int {
        global $DB;

        $id = $DB->get_field('role', 'id', ['shortname' => 'parent']);
        return $id ? (int) $id : 0;
    }
}
