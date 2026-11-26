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

namespace local_wwnation;

/**
 * The stored boundary between Nations.
 *
 * A Nation row points at one top-level course category. Membership rows are
 * the authorization record. A check that finds a Nation row reads those rows
 * and does not treat a role on the whole site as membership.
 *
 * @package    local_wwnation
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class nation {
    /**
     * The top category on a path.
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
     * Nation id for a top category, or 0 when it has not been stored.
     *
     * @param int $categoryid
     * @return int
     */
    public static function id_for_root(int $categoryid): int {
        global $DB;

        $root = self::root_id($categoryid);
        if ($root <= 0) {
            return 0;
        }
        $id = $DB->get_field('local_wwnation', 'id', ['categoryid' => $root]);
        return $id ? (int) $id : 0;
    }

    /**
     * Store the Nation for a top category when it is missing.
     *
     * @param int $categoryid
     * @return int Nation id, or 0.
     */
    public static function ensure(int $categoryid): int {
        global $DB;

        $root = self::root_id($categoryid);
        if ($root <= 0) {
            return 0;
        }
        $existing = self::id_for_root($root);
        if ($existing) {
            return $existing;
        }
        $name = $DB->get_field('course_categories', 'name', ['id' => $root]);
        return (int) $DB->insert_record('local_wwnation', (object) [
            'categoryid' => $root,
            'name' => self::plain((string) $name),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a Nation and its top category. Only a site administrator may do this.
     *
     * @param string $name
     * @return int Nation id.
     */
    public static function create(string $name): int {
        $name = self::plain(clean_param($name, PARAM_TEXT));
        if ($name === '' || !is_siteadmin()) {
            throw new \moodle_exception('denied', 'local_wwnation');
        }
        $category = \core_course_category::create(['name' => $name]);
        $id = self::ensure((int) $category->id);
        self::sync($id);
        return $id;
    }

    /**
     * Every stored Nation, oldest first.
     *
     * @return \stdClass[]
     */
    public static function all(): array {
        global $DB;

        return $DB->get_records('local_wwnation', null, 'name ASC, id ASC');
    }

    /**
     * Write membership for one Nation from enrolments, roles, and parent links.
     *
     * @param int $nationid
     */
    public static function sync(int $nationid): void {
        global $DB;

        $nation = $DB->get_record('local_wwnation', ['id' => $nationid], '*', MUST_EXIST);
        $name = self::plain((string) $DB->get_field('course_categories', 'name', ['id' => $nation->categoryid]));
        if ($name !== '' && $name !== $nation->name) {
            $DB->set_field('local_wwnation', 'name', $name, ['id' => $nationid]);
            $DB->set_field('local_wwnation', 'timemodified', time(), ['id' => $nationid]);
        }
        $tree = self::tree_ids((int) $nation->categoryid);
        $ids = [];
        if ($tree) {
            foreach (self::enrolled_ids($tree) as $id) {
                $ids[$id] = $id;
            }
            foreach (self::role_ids($tree) as $id) {
                $ids[$id] = $id;
            }
            foreach (self::parent_ids_for(array_values($ids), $nationid) as $id) {
                $ids[$id] = $id;
            }
        }
        $existing = self::member_ids($nationid);
        foreach ($existing as $id) {
            if (!isset($ids[$id])) {
                $DB->delete_records('local_wwnation_member', ['nationid' => $nationid, 'userid' => $id]);
            }
        }
        $have = array_fill_keys($existing, true);
        foreach ($ids as $id) {
            if (!isset($have[$id])) {
                self::add_member($nationid, $id);
            }
        }
    }

    /**
     * Store the Nation and refresh its membership.
     *
     * @param int $categoryid
     * @return int Nation id, or 0.
     */
    public static function sync_root(int $categoryid): int {
        $id = self::ensure($categoryid);
        if ($id) {
            self::sync($id);
        }
        return $id;
    }

    /**
     * Refresh every Nation this person is enrolled in or holds a school role in.
     *
     * @param int $userid
     */
    public static function sync_user(int $userid): void {
        foreach (self::roots_for_user($userid) as $root) {
            self::sync_root($root);
        }
    }

    /**
     * People stored in this Nation.
     *
     * @param int $nationid
     * @return int[]
     */
    public static function member_ids(int $nationid): array {
        global $DB;

        if ($nationid <= 0) {
            return [];
        }
        $ids = $DB->get_fieldset_select('local_wwnation_member', 'userid', 'nationid = ?', [$nationid]);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * True when the membership table contains this person and Nation.
     *
     * @param int $userid
     * @param int $nationid
     * @return bool
     */
    public static function is_member(int $userid, int $nationid): bool {
        global $DB;

        if ($userid <= 0 || $nationid <= 0) {
            return false;
        }
        return $DB->record_exists('local_wwnation_member', ['userid' => $userid, 'nationid' => $nationid]);
    }

    /**
     * Nations this person is stored in.
     *
     * @param int $userid
     * @return int[]
     */
    public static function nations_of(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }
        $ids = $DB->get_fieldset_select('local_wwnation_member', 'nationid', 'userid = ?', [$userid]);
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * True when both people are stored in one shared Nation.
     *
     * @param int $a
     * @param int $b
     * @return bool
     */
    public static function shares(int $a, int $b): bool {
        if ($a <= 0 || $b <= 0) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        return (bool) array_intersect(self::nations_of($a), self::nations_of($b));
    }

    /**
     * People in this Nation who are also stored in another Nation.
     *
     * @param int $nationid
     * @return int
     */
    public static function shared_count(int $nationid): int {
        global $DB;

        if ($nationid <= 0) {
            return 0;
        }
        return (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT m.userid)
               FROM {local_wwnation_member} m
               JOIN {local_wwnation_member} o
                 ON o.userid = m.userid AND o.nationid <> m.nationid
              WHERE m.nationid = ?",
            [$nationid]
        );
    }

    /**
     * Refuse unless this person is stored in the course's Nation.
     *
     * When the Nation row is missing it is written first. When the row is
     * already there, a missing membership row is a refusal. It is not rebuilt.
     *
     * @param int $actor
     * @param int $courseid
     */
    public static function require_course(int $actor, int $courseid): void {
        if (!self::allows_course($actor, $courseid)) {
            throw new \moodle_exception('denied', 'local_wwnation');
        }
    }

    /**
     * True when this person may open this course under the Nation boundary.
     *
     * @param int $actor
     * @param int $courseid
     * @return bool
     */
    public static function allows_course(int $actor, int $courseid): bool {
        global $DB;

        if ($actor <= 0 || isguestuser($actor) || $courseid <= 0 || $courseid === (int) SITEID) {
            return false;
        }
        if (is_siteadmin($actor)) {
            return true;
        }
        $categoryid = $DB->get_field('course', 'category', ['id' => $courseid]);
        if (!$categoryid) {
            return false;
        }
        $root = self::root_id((int) $categoryid);
        if ($root <= 0) {
            return false;
        }
        $nationid = self::id_for_root($root);
        if (!$nationid) {
            $nationid = self::sync_root($root);
        }
        return $nationid && self::is_member($actor, $nationid);
    }

    /**
     * Refuse unless this person is stored in the category's Nation.
     *
     * @param int $actor
     * @param int $categoryid
     */
    public static function require_category(int $actor, int $categoryid): void {
        if ($actor <= 0 || isguestuser($actor)) {
            throw new \moodle_exception('denied', 'local_wwnation');
        }
        if (is_siteadmin($actor)) {
            return;
        }
        $root = self::root_id($categoryid);
        if ($root <= 0) {
            throw new \moodle_exception('denied', 'local_wwnation');
        }
        $nationid = self::id_for_root($root);
        if (!$nationid) {
            $nationid = self::sync_root($root);
        }
        if (!$nationid || !self::is_member($actor, $nationid)) {
            throw new \moodle_exception('denied', 'local_wwnation');
        }
    }

    /**
     * Refuse unless both people are stored in one Nation.
     *
     * @param int $actor
     * @param int $target
     */
    public static function require_user(int $actor, int $target): void {
        if ($actor <= 0 || $target <= 0 || isguestuser($actor)) {
            throw new \moodle_exception('denied', 'local_wwnation');
        }
        if ($actor === $target || is_siteadmin($actor)) {
            return;
        }
        self::sync_user($actor);
        self::sync_user($target);
        if (!self::shares($actor, $target)) {
            throw new \moodle_exception('denied', 'local_wwnation');
        }
    }

    /**
     * True when a parent may see this child under the stored Nations.
     *
     * A child with no school is left to the parent role. A parent stored in
     * another Nation is refused even when the role row exists.
     *
     * @param int $parent
     * @param int $child
     * @return bool
     */
    public static function shares_parent(int $parent, int $child): bool {
        if ($parent <= 0 || $child <= 0 || $parent === $child) {
            return false;
        }
        self::sync_user($child);
        $childnations = self::nations_of($child);
        if (!$childnations) {
            return true;
        }
        return (bool) array_intersect(self::nations_of($parent), $childnations);
    }

    /**
     * Store the parent in each Nation the child belongs to.
     *
     * The caller has already decided this link is allowed. A parent of children
     * in two Nations is stored in both. Seeing the child still requires the link.
     *
     * @param int $parent
     * @param int $child
     */
    public static function assert_parent_link(int $parent, int $child): void {
        self::sync_user($child);
        foreach (self::nations_of($child) as $id) {
            self::add_member($id, $parent);
        }
    }

    /**
     * Unread conversations whose other people are all in this person's Nations.
     *
     * @param int $userid
     * @return int
     */
    public static function unread_count(int $userid): int {
        global $CFG, $DB;

        if ($userid <= 0 || empty($CFG->messaging)) {
            return 0;
        }
        self::sync_user($userid);
        $user = (object) ['id' => $userid];
        if (!self::nations_of($userid)) {
            return (int) \core_message\api::count_unread_conversations($user);
        }
        $conversationids = $DB->get_fieldset_sql(
            "SELECT DISTINCT m.conversationid
               FROM {messages} m
               JOIN {message_conversations} mc ON mc.id = m.conversationid
               JOIN {message_conversation_members} mine ON mine.conversationid = mc.id
          LEFT JOIN {message_user_actions} mua
                 ON mua.messageid = m.id AND mua.userid = mine.userid AND mua.action = :read
              WHERE mine.userid = :userid
                AND mc.enabled = :enabled
                AND m.useridfrom <> mine.userid
                AND mua.id IS NULL",
            [
                'read' => \core_message\api::MESSAGE_ACTION_READ,
                'userid' => $userid,
                'enabled' => \core_message\api::MESSAGE_CONVERSATION_ENABLED,
            ]
        );
        $count = 0;
        foreach ($conversationids as $conversationid) {
            $others = $DB->get_fieldset_sql(
                "SELECT userid
                   FROM {message_conversation_members}
                  WHERE conversationid = ? AND userid <> ?",
                [$conversationid, $userid]
            );
            if (!$others) {
                continue;
            }
            $open = true;
            foreach ($others as $other) {
                $other = (int) $other;
                self::sync_user($other);
                if (!self::shares($userid, $other)) {
                    $open = false;
                    break;
                }
            }
            if ($open) {
                $count++;
            }
        }
        return $count;
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
     * Top categories this person is enrolled in or holds a category or course role in.
     *
     * @param int $userid
     * @return int[]
     */
    private static function roots_for_user(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }
        $categories = $DB->get_fieldset_sql(
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
        $fromcourses = $DB->get_fieldset_sql(
            "SELECT DISTINCT c.category
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {course} c ON c.id = ctx.instanceid
              WHERE ra.userid = ? AND ctx.contextlevel = ? AND c.category <> 0",
            [$userid, CONTEXT_COURSE]
        );
        $roots = [];
        foreach (array_merge($categories, $fromroles, $fromcourses) as $id) {
            $root = self::root_id((int) $id);
            if ($root > 0) {
                $roots[$root] = $root;
            }
        }
        return array_values($roots);
    }

    /**
     * Enrolled people in these categories, excluding guests.
     *
     * @param int[] $tree
     * @return int[]
     */
    private static function enrolled_ids(array $tree): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($tree, SQL_PARAMS_NAMED);
        $params['deleted'] = 0;
        $params['guest'] = 'guest';
        $params['siteid'] = SITEID;
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ue.userid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {course} c ON c.id = e.courseid
               JOIN {user} u ON u.id = ue.userid
              WHERE c.category {$insql}
                AND c.id <> :siteid
                AND u.deleted = :deleted
                AND u.username <> :guest",
            $params
        );
        return array_map('intval', $ids);
    }

    /**
     * People with a role on these categories or on a course in them.
     *
     * @param int[] $tree
     * @return int[]
     */
    private static function role_ids(array $tree): array {
        global $DB;

        [$csql, $cparams] = $DB->get_in_or_equal($tree, SQL_PARAMS_NAMED, 'c');
        [$asql, $aparams] = $DB->get_in_or_equal($tree, SQL_PARAMS_NAMED, 'a');
        $params = $cparams + $aparams + [
            'deleted' => 0,
            'guest' => 'guest',
            'catlevel' => CONTEXT_COURSECAT,
            'courselevel' => CONTEXT_COURSE,
            'siteid' => SITEID,
        ];
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {user} u ON u.id = ra.userid
              WHERE u.deleted = :deleted
                AND u.username <> :guest
                AND (
                    (ctx.contextlevel = :catlevel AND ctx.instanceid {$csql})
                    OR (ctx.contextlevel = :courselevel AND ctx.instanceid <> :siteid AND EXISTS (
                        SELECT 1
                          FROM {course} c
                         WHERE c.id = ctx.instanceid
                           AND c.category {$asql}
                    ))
                )",
            $params
        );
        return array_map('intval', $ids);
    }

    /**
     * Parents linked to these people.
     *
     * @param int[] $userids
     * @param int $nationid
     * @return int[]
     */
    private static function parent_ids_for(array $userids, int $nationid): array {
        global $DB;

        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'parent']);
        if (!$roleid) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['roleid'] = $roleid;
        $params['level'] = CONTEXT_USER;
        $params['deleted'] = 0;
        $params['guest'] = 'guest';
        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {user} u ON u.id = ra.userid
              WHERE ra.roleid = :roleid
                AND ctx.contextlevel = :level
                AND ctx.instanceid {$insql}
                AND u.deleted = :deleted
                AND u.username <> :guest",
            $params
        );
        return array_map('intval', $ids);
    }

    /**
     * Store one membership row.
     *
     * @param int $nationid
     * @param int $userid
     */
    private static function add_member(int $nationid, int $userid): void {
        global $DB;

        if ($nationid <= 0 || $userid <= 0 || isguestuser($userid)) {
            return;
        }
        if (self::is_member($userid, $nationid)) {
            return;
        }
        $DB->insert_record('local_wwnation_member', (object) [
            'nationid' => $nationid,
            'userid' => $userid,
            'timecreated' => time(),
        ]);
    }
}
