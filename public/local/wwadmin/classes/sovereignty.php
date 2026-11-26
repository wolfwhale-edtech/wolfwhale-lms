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

use local_wwnation\nation;

/**
 * Live counts for one Nation.
 *
 * Every number is a database count limited to that Nation's stored members
 * and its own categories.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sovereignty {
    /**
     * Isolation fact for one school.
     *
     * @param int $categoryid
     * @return string
     */
    public static function isolation_fact(int $categoryid): string {
        $nationid = nation::sync_root($categoryid);
        if (!$nationid) {
            return get_string('none', 'local_wwadmin');
        }
        return self::isolation_word($nationid);
    }

    /**
     * Counts, the export control, and the audit rows for one school.
     *
     * @param int $categoryid
     * @return array
     */
    public static function export(int $categoryid): array {
        global $DB, $USER;

        scope::require($categoryid);
        $nationid = nation::sync_root($categoryid);
        $record = $DB->get_record('local_wwnation', ['id' => $nationid], '*', MUST_EXIST);
        $tree = scope::tree_ids((int) $record->categoryid);
        $counts = self::counts($nationid, $tree);
        $shared = nation::shared_count($nationid);
        $rows = [
            self::count_row('users', $counts['users']),
            self::count_row('courses', $counts['courses']),
            self::count_row('enrolments', $counts['enrolments']),
            self::count_row('marks', $counts['marks']),
            self::count_row('attendance', $counts['attendance']),
            self::count_row('badges', $counts['badges']),
            self::count_row('points', $counts['points']),
            self::count_row('preferences', $counts['preferences']),
            [
                'word' => get_string('isolation', 'local_wwadmin'),
                'fact' => self::isolation_word($nationid),
            ],
        ];
        $audits = [];
        $exports = $DB->get_records('local_wwadmin_export', ['categoryid' => (int) $record->categoryid], 'timecreated DESC');
        foreach ($exports as $export) {
            $who = $DB->get_record('user', ['id' => $export->userid], 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, deleted');
            $name = ($who && !(int) $who->deleted) ? nation::plain(fullname($who)) : (string) $export->userid;
            $status = get_string($export->status, 'local_wwadmin');
            $when = userdate((int) $export->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
            $row = [
                'word' => $name,
                'fact' => $status . ' ' . $when,
            ];
            if ($export->status === exporter::READY && (int) $export->userid === (int) $USER->id) {
                $row['url'] = (new \moodle_url('/local/wwadmin/download.php', [
                    'id' => $export->id,
                    'sesskey' => sesskey(),
                ]))->out(false);
            }
            $audits[] = $row;
        }
        return [
            'nation' => nation::plain($record->name),
            'counts' => $rows,
            'shared' => $shared,
            'exportword' => get_string('export', 'local_wwadmin'),
            'sesskey' => sesskey(),
            'category' => (int) $record->categoryid,
            'audits' => $audits,
            'hasaudits' => $audits !== [],
        ];
    }

    /**
     * Isolated, or the number of people who also belong to another Nation.
     *
     * @param int $nationid
     * @return string
     */
    private static function isolation_word(int $nationid): string {
        $shared = nation::shared_count($nationid);
        if ($shared === 0) {
            return get_string('isolated', 'local_wwadmin');
        }
        return (string) $shared;
    }

    /**
     * One count tile.
     *
     * @param string $key
     * @param int $count
     * @return array
     */
    private static function count_row(string $key, int $count): array {
        return [
            'word' => get_string($key, 'local_wwadmin'),
            'fact' => (string) $count,
        ];
    }

    /**
     * Database counts for one Nation.
     *
     * @param int $nationid
     * @param int[] $tree
     * @return array<string, int>
     */
    private static function counts(int $nationid, array $tree): array {
        global $DB;

        $empty = [
            'users' => 0,
            'courses' => 0,
            'enrolments' => 0,
            'marks' => 0,
            'attendance' => 0,
            'badges' => 0,
            'points' => 0,
            'preferences' => 0,
        ];
        if (!$tree) {
            $empty['users'] = count(nation::member_ids($nationid));
            return $empty;
        }
        [$insql, $treeparams] = $DB->get_in_or_equal($tree, SQL_PARAMS_NAMED, 'cat');
        $courseparams = $treeparams + ['siteid' => SITEID];
        $memberparams = $treeparams + ['nation' => $nationid, 'siteid' => SITEID];
        $markparams = $treeparams + ['nation' => $nationid];
        $users = (int) $DB->count_records('local_wwnation_member', ['nationid' => $nationid]);
        $courses = (int) $DB->count_records_select('course', "category {$insql} AND id <> :siteid", $courseparams);
        $enrolments = (int) $DB->get_field_sql(
            "SELECT COUNT(ue.id)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {course} c ON c.id = e.courseid
               JOIN {local_wwnation_member} m ON m.userid = ue.userid AND m.nationid = :nation
              WHERE c.category {$insql}
                AND c.id <> :siteid",
            $memberparams
        );
        $marks = (int) $DB->get_field_sql(
            "SELECT COUNT(gg.id)
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
               JOIN {course} c ON c.id = gi.courseid
               JOIN {local_wwnation_member} m ON m.userid = gg.userid AND m.nationid = :nation
              WHERE c.category {$insql}
                AND gg.finalgrade IS NOT NULL",
            $markparams
        );
        $attendance = 0;
        if ($DB->get_manager()->table_exists('local_wwattendance_log')) {
            $attendance = (int) $DB->get_field_sql(
                "SELECT COUNT(l.id)
                   FROM {local_wwattendance_log} l
                   JOIN {local_wwattendance_session} s ON s.id = l.sessionid
                   JOIN {course} c ON c.id = s.courseid
                  WHERE c.category {$insql}",
                $treeparams
            );
        }
        $badges = 0;
        if ($DB->get_manager()->table_exists('badge_issued')) {
            $badges = (int) $DB->get_field_sql(
                "SELECT COUNT(bi.id)
                   FROM {badge_issued} bi
                   JOIN {badge} b ON b.id = bi.badgeid
                   JOIN {local_wwnation_member} m ON m.userid = bi.userid AND m.nationid = :nation
                  WHERE b.courseid IS NULL
                     OR b.courseid = 0
                     OR EXISTS (
                         SELECT 1 FROM {course} c WHERE c.id = b.courseid AND c.category {$insql}
                     )",
                $markparams
            );
        }
        $points = 0;
        if ($DB->get_manager()->table_exists('local_wwpoints_ledger')) {
            $points = (int) $DB->get_field_sql(
                "SELECT COUNT(p.id)
                   FROM {local_wwpoints_ledger} p
                   JOIN {local_wwnation_member} m ON m.userid = p.userid AND m.nationid = :nation
                  WHERE p.courseid = 0
                     OR EXISTS (
                         SELECT 1 FROM {course} c WHERE c.id = p.courseid AND c.category {$insql}
                     )",
                $markparams
            );
        }
        $preferences = self::preference_count($nationid);
        return [
            'users' => $users,
            'courses' => $courses,
            'enrolments' => $enrolments,
            'marks' => $marks,
            'attendance' => $attendance,
            'badges' => $badges,
            'points' => $points,
            'preferences' => $preferences,
        ];
    }

    /**
     * Parents in this Nation who have a stored update switch.
     *
     * @param int $nationid
     * @return int
     */
    private static function preference_count(int $nationid): int {
        global $DB;

        if (!class_exists(\local_wwupdates\preferences::class)) {
            return 0;
        }
        $names = [];
        foreach (array_merge(\local_wwupdates\preferences::CHANNELS, \local_wwupdates\preferences::TYPES) as $key) {
            $names[] = \local_wwupdates\preferences::name($key);
        }
        [$nsql, $nparams] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED, 'p');
        $nparams['nation'] = $nationid;
        $nparams['role'] = 'parent';
        $nparams['level'] = CONTEXT_USER;
        return (int) $DB->get_field_sql(
            "SELECT COUNT(DISTINCT m.userid)
               FROM {local_wwnation_member} m
               JOIN {role_assignments} ra ON ra.userid = m.userid
               JOIN {role} r ON r.id = ra.roleid AND r.shortname = :role
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :level
               JOIN {user_preferences} up ON up.userid = m.userid AND up.name {$nsql}
              WHERE m.nationid = :nation",
            $nparams
        );
    }
}
