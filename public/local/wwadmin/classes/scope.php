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
 * Which schools a person may administer.
 *
 * A school is a course category. A manager role on that category covers the
 * category and its children, and no other category. A site administrator
 * covers every top-level category.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope {
    /**
     * A site administrator, or a manager of at least one school.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_admin(int $userid): bool {
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        return (bool) self::managed_root_ids($userid);
    }

    /**
     * Administrators leave the dashboard.
     *
     * @param \moodle_url $url
     * @param int $userid
     * @return bool
     */
    public static function should_redirect(\moodle_url $url, int $userid): bool {
        if (!self::is_admin($userid)) {
            return false;
        }
        $dashboard = new \moodle_url('/my/index.php');
        $my = new \moodle_url('/my/');
        return $url->compare($dashboard, URL_MATCH_BASE) || $url->compare($my, URL_MATCH_BASE);
    }

    /**
     * Where an administrator should land after login.
     *
     * A course or activity they asked for is kept. The front page, the
     * dashboard, and the student or teacher home are replaced.
     *
     * @param string|null $wantsurl
     * @param int $userid
     * @return string|null
     */
    public static function login_wantsurl(?string $wantsurl, int $userid): ?string {
        if (!self::is_admin($userid)) {
            return null;
        }
        if ($wantsurl === null || $wantsurl === '' || self::is_homepage_url($wantsurl)) {
            return self::url()->out(false);
        }
        return null;
    }

    /**
     * The admin home.
     *
     * @return \moodle_url
     */
    public static function url(): \moodle_url {
        return new \moodle_url('/local/wwadmin/index.php');
    }

    /**
     * Send anyone who is not an administrator away.
     */
    public static function require_admin(): void {
        global $USER;

        if (isguestuser() || !self::is_admin((int) $USER->id)) {
            redirect(new \moodle_url('/my/'));
        }
    }

    /**
     * Schools this person administers, one row per Nation or school.
     *
     * A category whose parent is already in the list is part of that school,
     * not a second school. A site administrator gets every top-level category.
     *
     * @param int $userid
     * @return int[]
     */
    public static function managed_root_ids(int $userid): array {
        global $DB;

        if ($userid <= 0 || isguestuser($userid)) {
            return [];
        }
        if (is_siteadmin($userid)) {
            $ids = $DB->get_fieldset_select('course_categories', 'id', 'parent = 0', []);
            return array_map('intval', $ids);
        }

        $cats = $DB->get_records('course_categories', null, 'depth ASC, id ASC', 'id, path');
        $direct = [];
        foreach ($cats as $cat) {
            $context = \context_coursecat::instance($cat->id);
            // A role on the whole site is not a school. The assignment has to
            // sit on this category or one of its parent categories.
            if (has_capability('local/wwadmin:view', $context, $userid)
                    && self::assigned_on_category_path($userid, (int) $cat->id)) {
                $direct[] = (int) $cat->id;
            }
        }
        $roots = [];
        foreach ($direct as $id) {
            $path = $cats[$id]->path;
            $nested = false;
            foreach ($direct as $other) {
                if ($other === $id) {
                    continue;
                }
                if (str_starts_with($path, $cats[$other]->path . '/')) {
                    $nested = true;
                    break;
                }
            }
            if (!$nested) {
                $roots[] = $id;
            }
        }
        return $roots;
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
     * Every category id inside the schools this person administers.
     *
     * @param int|null $userid
     * @return int[]
     */
    public static function visible_ids(?int $userid = null): array {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        $ids = [];
        foreach (self::managed_root_ids($userid) as $root) {
            foreach (self::tree_ids($root) as $id) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Refuse unless this person may use this capability in this school.
     *
     * @param int $categoryid
     * @param string $capability
     * @return \context_coursecat
     */
    public static function require(int $categoryid, string $capability = 'local/wwadmin:view'): \context_coursecat {
        global $USER;

        $context = \context_coursecat::instance($categoryid);
        require_capability($capability, $context);
        if (!is_siteadmin()) {
            $root = \local_wwnation\nation::root_id($categoryid);
            if (!self::assigned_on_category_path((int) $USER->id, $categoryid)) {
                throw new \moodle_exception('denied', 'local_wwnation');
            }
            $nationid = \local_wwnation\nation::id_for_root($root);
            if ($nationid && !\local_wwnation\nation::is_member((int) $USER->id, $nationid)) {
                throw new \moodle_exception('denied', 'local_wwnation');
            }
        }
        return $context;
    }

    /**
     * True when this person holds a role on this category or a parent category.
     *
     * A role on the system context does not count.
     *
     * @param int $userid
     * @param int $categoryid
     * @return bool
     */
    private static function assigned_on_category_path(int $userid, int $categoryid): bool {
        global $DB;

        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if (!$path) {
            return false;
        }
        $ids = array_values(array_filter(array_map('intval', explode('/', (string) $path))));
        if (!$ids) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $params['level'] = CONTEXT_COURSECAT;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid
                AND ctx.contextlevel = :level
                AND ctx.instanceid {$insql}",
            $params
        );
    }

    /**
     * Courses in these categories, excluding the front page.
     *
     * @param int[] $categoryids
     * @return \stdClass[] Keyed by course id.
     */
    public static function courses(array $categoryids): array {
        global $DB;

        if (!$categoryids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $params['siteid'] = SITEID;
        return $DB->get_records_select(
            'course',
            "category {$insql} AND id <> :siteid",
            $params,
            'fullname ASC'
        );
    }

    /**
     * People enrolled in these categories, or holding a role there.
     *
     * @param int[] $categoryids
     * @return \stdClass[] Keyed by user id.
     */
    public static function users(array $categoryids): array {
        global $DB;

        if (!$categoryids) {
            return [];
        }
        $allowed = [];
        $roots = [];
        foreach ($categoryids as $id) {
            $root = \local_wwnation\nation::root_id((int) $id);
            if ($root > 0) {
                $roots[$root] = $root;
            }
        }
        foreach ($roots as $root) {
            $nationid = \local_wwnation\nation::sync_root($root);
            foreach (\local_wwnation\nation::member_ids($nationid) as $userid) {
                $allowed[$userid] = $userid;
            }
        }
        [$csql, $cparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'c');
        [$asql, $aparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'a');
        [$bsql, $bparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'b');
        $params = array_merge($cparams, $aparams, $bparams, [
            'deleted' => 0,
            'guest' => 'guest',
            'courselevel' => CONTEXT_COURSE,
            'catlevel' => CONTEXT_COURSECAT,
        ]);
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.email, u.username, u.suspended, u.idnumber, u.auth
                  FROM {user} u
                 WHERE u.deleted = :deleted
                   AND u.username <> :guest
                   AND (
                        EXISTS (
                            SELECT 1
                              FROM {user_enrolments} ue
                              JOIN {enrol} e ON e.id = ue.enrolid
                              JOIN {course} c ON c.id = e.courseid
                             WHERE ue.userid = u.id
                               AND c.category {$csql}
                        )
                        OR EXISTS (
                            SELECT 1
                              FROM {role_assignments} ra
                              JOIN {context} ctx ON ctx.id = ra.contextid
                             WHERE ra.userid = u.id
                               AND ctx.contextlevel = :catlevel
                               AND ctx.instanceid {$asql}
                        )
                        OR EXISTS (
                            SELECT 1
                              FROM {role_assignments} ra
                              JOIN {context} ctx ON ctx.id = ra.contextid
                              JOIN {course} c ON c.id = ctx.instanceid
                             WHERE ra.userid = u.id
                               AND ctx.contextlevel = :courselevel
                               AND c.category {$bsql}
                        )
                   )
              ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC";
        $rows = $DB->get_records_sql($sql, $params);
        if ($allowed) {
            foreach ($rows as $id => $row) {
                if (!isset($allowed[(int) $id])) {
                    unset($rows[$id]);
                }
            }
        }
        return $rows;
    }

    /**
     * Course categories this person belongs to, by enrolment or by a role.
     *
     * A role on the whole site is not a school.
     *
     * @param int $userid
     * @return int[]
     */
    public static function school_category_ids(int $userid): array {
        global $DB;

        $ids = [];
        $fromcourses = $DB->get_fieldset_sql(
            "SELECT DISTINCT c.category
               FROM {course} c
               JOIN {enrol} e ON e.courseid = c.id
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE ue.userid = ? AND c.category <> 0",
            [$userid]
        );
        $fromroles = $DB->get_fieldset_sql(
            "SELECT DISTINCT ctx.instanceid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = ? AND ctx.contextlevel = ?",
            [$userid, CONTEXT_COURSECAT]
        );
        $fromcourseroles = $DB->get_fieldset_sql(
            "SELECT DISTINCT c.category
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = ? AND ctx.contextlevel = ? AND c.category <> 0",
            [$userid, CONTEXT_COURSE]
        );
        foreach (array_merge($fromcourses, $fromroles, $fromcourseroles) as $id) {
            $ids[(int) $id] = (int) $id;
        }
        return array_values($ids);
    }

    /**
     * True when the address is a front page, the dashboard, or another home.
     *
     * @param string $wantsurl
     * @return bool
     */
    private static function is_homepage_url(string $wantsurl): bool {
        global $CFG;

        $path = parse_url($wantsurl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $root = parse_url($CFG->wwwroot, PHP_URL_PATH);
        if (is_string($root) && $root !== '' && $root !== '/' && str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }
        if ($path === '' || $path === '/') {
            $path = '/';
        } else {
            $path = rtrim($path, '/');
        }
        return in_array($path, [
            '/',
            '/index.php',
            '/my',
            '/my/index.php',
            '/local/wolfwhale/index.php',
            '/local/wolfwhale/teacher.php',
            '/local/wwadmin/index.php',
        ], true);
    }
}
