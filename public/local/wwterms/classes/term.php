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

namespace local_wwterms;

/**
 * Named date ranges for one school (one course category).
 *
 * Nothing here assumes a calendar. A school chooses its own names and dates.
 *
 * @package    local_wwterms
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class term {
    /**
     * Whether this person may read and change terms in this school.
     *
     * @param int $categoryid
     * @param int|null $userid
     * @return bool
     */
    public static function can_manage(int $categoryid, ?int $userid = null): bool {
        $context = \context_coursecat::instance($categoryid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return has_capability('local/wwterms:manage', $context, $userid);
    }

    /**
     * Refuse unless this person may manage terms in this school.
     *
     * @param int $categoryid
     * @return \context_coursecat
     */
    public static function require_manage(int $categoryid): \context_coursecat {
        global $USER;

        $context = \context_coursecat::instance($categoryid);
        require_capability('local/wwterms:manage', $context);
        \local_wwnation\nation::require_category((int) $USER->id, $categoryid);
        return $context;
    }

    /**
     * Terms for one school, earliest start first.
     *
     * The caller must already be allowed to manage this school. A person who
     * only manages another school gets nothing from {@see self::list_for()}.
     *
     * @param int $categoryid
     * @return \stdClass[]
     */
    public static function records(int $categoryid): array {
        global $DB;

        return $DB->get_records('local_wwterms', ['categoryid' => $categoryid], 'timestart ASC, id ASC');
    }

    /**
     * Terms for one school, after a capability check.
     *
     * @param int $categoryid
     * @return \stdClass[]
     */
    public static function list_for(int $categoryid): array {
        self::require_manage($categoryid);
        return self::records($categoryid);
    }

    /**
     * One term, or null when it is missing or outside the schools this person manages.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;

        $record = $DB->get_record('local_wwterms', ['id' => $id]);
        if (!$record || !self::can_manage((int) $record->categoryid)) {
            return null;
        }
        return $record;
    }

    /**
     * Store a term. The end must be after the start.
     *
     * @param int $categoryid
     * @param string $name
     * @param int $timestart
     * @param int $timeend
     * @return int
     */
    public static function create(int $categoryid, string $name, int $timestart, int $timeend): int {
        global $DB, $USER;

        self::require_manage($categoryid);
        $name = trim($name);
        if ($name === '' || $timestart <= 0 || $timeend <= $timestart) {
            throw new \moodle_exception('invalidterm', 'local_wwterms');
        }
        $now = time();
        return (int) $DB->insert_record('local_wwterms', (object) [
            'categoryid' => $categoryid,
            'name' => $name,
            'timestart' => $timestart,
            'timeend' => $timeend,
            'usermodified' => (int) $USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Delete one term in a school this person manages.
     *
     * @param int $id
     * @return bool
     */
    public static function delete(int $id): bool {
        global $DB;

        $record = self::get($id);
        if (!$record) {
            return false;
        }
        self::require_manage((int) $record->categoryid);
        return $DB->delete_records('local_wwterms', ['id' => $record->id]);
    }
}
