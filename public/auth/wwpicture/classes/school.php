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

namespace auth_wwpicture;

/**
 * One school is a top-level course category. Picture sign-in and email domains are stored there.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class school {
    /**
     * Schools this person may change.
     *
     * @param int $userid
     * @return array[]
     */
    public static function manageable(int $userid): array {
        global $DB;

        $cats = $DB->get_records('course_categories', ['parent' => 0], 'name');
        $rows = [];
        foreach ($cats as $cat) {
            $context = \context_coursecat::instance($cat->id);
            if (!has_capability('auth/wwpicture:manageschool', $context, $userid)) {
                continue;
            }
            $saved = $DB->get_record('auth_wwpicture_school', ['categoryid' => $cat->id]);
            $rows[] = [
                'id' => (int) $cat->id,
                'name' => format_string($cat->name),
                'enabled' => $saved && (int) $saved->enabled === 1,
                'domains' => $saved->domains ?? '',
            ];
        }
        return $rows;
    }

    /**
     * Save picture sign-in and the allowed email domains for one school.
     *
     * @param int $categoryid Top-level category.
     * @param bool $enabled
     * @param string $domains
     */
    public static function save(int $categoryid, bool $enabled, string $domains): void {
        global $DB;

        $record = (object) [
            'categoryid' => $categoryid,
            'enabled' => $enabled ? 1 : 0,
            'domains' => implode(',', self::split($domains)),
        ];
        $existing = $DB->get_record('auth_wwpicture_school', ['categoryid' => $categoryid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('auth_wwpicture_school', $record);
            return;
        }
        $DB->insert_record('auth_wwpicture_school', $record);
    }

    /**
     * Picture sign-in is on for the school that owns this course.
     *
     * @param int $courseid
     * @return bool
     */
    public static function picture_on(int $courseid): bool {
        global $DB;

        $categoryid = $DB->get_field('course', 'category', ['id' => $courseid]);
        if (!$categoryid) {
            return false;
        }
        $row = $DB->get_record('auth_wwpicture_school', ['categoryid' => self::root_id((int) $categoryid)]);
        return $row && (int) $row->enabled === 1;
    }

    /**
     * Whether this email may sign in with Google or Microsoft.
     *
     * When no school has set domains, any email is allowed. A person already in a
     * school must match that school's domains when the school has set some.
     * A new person must match at least one school that has set domains.
     *
     * @param string $email
     * @param int $userid Zero looks up an existing account by email.
     * @return bool
     */
    public static function email_allowed(string $email, int $userid = 0): bool {
        global $DB;

        if (!$DB->get_manager()->table_exists('auth_wwpicture_school')) {
            return true;
        }
        $domain = self::domain($email);
        if ($domain === '') {
            return false;
        }
        $restricted = self::restricted();
        if (!$restricted) {
            return true;
        }
        if ($userid === 0) {
            $existing = \core_user::get_user_by_email($email, 'id', null, IGNORE_MULTIPLE);
            $userid = !empty($existing->id) ? (int) $existing->id : 0;
        }
        if ($userid) {
            $mine = self::schools_for($userid);
            if ($mine) {
                foreach ($mine as $id) {
                    if (isset($restricted[$id]) && !self::matches($domain, $restricted[$id])) {
                        return false;
                    }
                }
                return true;
            }
        }
        foreach ($restricted as $list) {
            if (self::matches($domain, $list)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The top category in a path. That category is the school.
     *
     * @param int $categoryid
     * @return int
     */
    public static function root_id(int $categoryid): int {
        global $DB;

        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if (!$path) {
            return $categoryid;
        }
        $ids = array_values(array_filter(explode('/', $path)));
        return (int) ($ids[0] ?? $categoryid);
    }

    /**
     * @param string $email
     * @return string
     */
    private static function domain(string $email): string {
        $parts = explode('@', strtolower(trim($email)), 2);
        if (count($parts) !== 2) {
            return '';
        }
        return $parts[1];
    }

    /**
     * @param string $raw
     * @return string[]
     */
    private static function split(string $raw): array {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower(trim($raw))) as $part) {
            $part = ltrim($part, '@');
            if ($part !== '' && preg_match('/^[a-z0-9.-]+$/', $part)) {
                $out[$part] = $part;
            }
        }
        return array_values($out);
    }

    /**
     * @return array<int, string[]>
     */
    private static function restricted(): array {
        global $DB;

        $rows = $DB->get_records_select('auth_wwpicture_school', "domains <> ''");
        $out = [];
        foreach ($rows as $row) {
            $list = self::split($row->domains);
            if ($list) {
                $out[(int) $row->categoryid] = $list;
            }
        }
        return $out;
    }

    /**
     * @param int $userid
     * @return int[]
     */
    private static function schools_for(int $userid): array {
        global $DB;

        $sql = "SELECT DISTINCT c.category
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                 WHERE ue.userid = :userid";
        $cats = $DB->get_fieldset_sql($sql, ['userid' => $userid]);
        $roots = [];
        foreach ($cats as $cat) {
            $roots[] = self::root_id((int) $cat);
        }
        return array_values(array_unique($roots));
    }

    /**
     * @param string $domain
     * @param string[] $list
     * @return bool
     */
    private static function matches(string $domain, array $list): bool {
        foreach ($list as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }
}
