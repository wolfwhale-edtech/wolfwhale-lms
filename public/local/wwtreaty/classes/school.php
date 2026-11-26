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

namespace local_wwtreaty;

/**
 * Which school an outcome belongs to, and who may change it.
 *
 * A school is a top-level course category. Outcomes are stored on that
 * category. A manager of the school, a site administrator, or an editing
 * teacher of a course in the school may change the list. Tagging and
 * coverage stay on the course.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class school {
    /**
     * The top-level category that owns this category.
     *
     * @param int $categoryid
     * @return int
     */
    public static function root_id(int $categoryid): int {
        global $DB;

        $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path', IGNORE_MISSING);
        if (!$cat) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $parts = array_values(array_filter(explode('/', $cat->path), 'strlen'));
        return (int) $parts[0];
    }

    /**
     * The school that contains this course.
     *
     * @param int $courseid
     * @return int
     */
    public static function school_for_course(int $courseid): int {
        global $DB;

        $categoryid = $DB->get_field('course', 'category', ['id' => $courseid], IGNORE_MISSING);
        if (!$categoryid) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        return self::root_id((int) $categoryid);
    }

    /**
     * A category and every category under it.
     *
     * @param int $categoryid
     * @return int[]
     */
    public static function tree_ids(int $categoryid): array {
        global $DB;

        $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path', IGNORE_MISSING);
        if (!$cat) {
            return [];
        }
        $ids = [];
        $prefix = $cat->path . '/';
        foreach ($DB->get_records('course_categories', null, '', 'id, path') as $row) {
            if ((int) $row->id === $categoryid || str_starts_with($row->path, $prefix)) {
                $ids[] = (int) $row->id;
            }
        }
        return $ids;
    }

    /**
     * Courses in one school, excluding the front page.
     *
     * @param int $rootid
     * @return \stdClass[]
     */
    public static function courses_in(int $rootid): array {
        global $DB;

        $ids = self::tree_ids($rootid);
        if (!$ids) {
            return [];
        }
        [$sql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $params['site'] = SITEID;
        return $DB->get_records_select(
            'course',
            "category {$sql} AND id <> :site",
            $params,
            'fullname ASC',
            'id, fullname, category, visible, groupmode'
        );
    }

    /**
     * Whether this person may add, edit, archive, and import outcomes for this school.
     *
     * @param int $categoryid
     * @param int|null $userid
     * @return bool
     */
    public static function can_manage(int $categoryid, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        $root = self::root_id($categoryid);
        $context = \context_coursecat::instance($root, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if (has_capability('local/wwtreaty:manage', $context, $userid)) {
            return true;
        }
        foreach (self::courses_in($root) as $course) {
            $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
            if ($coursecontext && has_capability('local/wwtreaty:manage', $coursecontext, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refuse unless this person may manage outcomes for this school.
     *
     * An editing teacher holds the capability on a course, so a check on the
     * category context alone would refuse them.
     *
     * @param int $categoryid
     * @return \context_coursecat
     */
    public static function require_manage(int $categoryid): \context_coursecat {
        $root = self::root_id($categoryid);
        $context = \context_coursecat::instance($root);
        if (!self::can_manage($root)) {
            throw new \required_capability_exception($context, 'local/wwtreaty:manage', 'nopermissions', '');
        }
        return $context;
    }

    /**
     * Schools whose outcome list this person may change.
     *
     * @param int|null $userid
     * @return int[]
     */
    public static function managed_root_ids(?int $userid = null): array {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        if ($userid <= 0 || isguestuser($userid)) {
            return [];
        }
        $roots = [];
        $ids = $DB->get_fieldset_select('course_categories', 'id', 'parent = 0', []);
        foreach ($ids as $id) {
            if (self::can_manage((int) $id, $userid)) {
                $roots[] = (int) $id;
            }
        }
        return $roots;
    }

    /**
     * Whether this person may tag activities in this course.
     *
     * @param int $courseid
     * @param int|null $userid
     * @return bool
     */
    public static function can_tag(int $courseid, ?int $userid = null): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return has_capability('local/wwtreaty:tag', $context, $userid);
    }

    /**
     * Refuse unless this person may tag activities in this course.
     *
     * @param int $courseid
     * @return \context_course
     */
    public static function require_tag(int $courseid): \context_course {
        $context = \context_course::instance($courseid);
        require_capability('local/wwtreaty:tag', $context);
        return $context;
    }

    /**
     * Whether this person may see coverage for this course.
     *
     * @param int $courseid
     * @param int|null $userid
     * @return bool
     */
    public static function can_view(int $courseid, ?int $userid = null): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return has_capability('local/wwtreaty:view', $context, $userid);
    }

    /**
     * Refuse unless this person may see coverage for this course.
     *
     * @param int $courseid
     * @return \context_course
     */
    public static function require_view(int $courseid): \context_course {
        $context = \context_course::instance($courseid);
        require_capability('local/wwtreaty:view', $context);
        return $context;
    }

    /**
     * Courses this person teaches and may view coverage for.
     *
     * @param int|null $userid
     * @return \stdClass[]
     */
    public static function teaching_courses(?int $userid = null): array {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        if (!class_exists(\local_wolfwhale\teacher::class)) {
            return [];
        }
        $courses = [];
        foreach (\local_wolfwhale\teacher::course_ids_for($userid) as $id) {
            if (!self::can_view((int) $id, $userid)) {
                continue;
            }
            $courses[] = get_course((int) $id);
        }
        return $courses;
    }
}
