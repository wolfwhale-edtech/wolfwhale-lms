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
 * Teacher comments stored for one student, course, and term.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class comment {
    /**
     * Save or clear one teacher's comment.
     *
     * An empty comment deletes that teacher's row. Another teacher's comment
     * is left alone.
     *
     * @param int $viewer
     * @param int $student
     * @param int $courseid
     * @param string $termkey
     * @param string $text
     */
    public static function save(int $viewer, int $student, int $courseid, string $termkey, string $text): void {
        global $DB;

        if (!access::can_comment($viewer, $student, $courseid) || $termkey === '') {
            throw new \moodle_exception('cannotview', 'local_wwreports');
        }
        $text = trim(clean_param($text, PARAM_TEXT));
        $text = \core_text::substr($text, 0, 2000);
        $existing = $DB->get_record('local_wwreports_comment', [
            'userid' => $student,
            'courseid' => $courseid,
            'termkey' => $termkey,
            'commenterid' => $viewer,
        ]);
        $now = time();
        if ($text === '') {
            if ($existing) {
                $DB->delete_records('local_wwreports_comment', ['id' => $existing->id]);
            }
            return;
        }
        if ($existing) {
            $existing->commenttext = $text;
            $existing->timemodified = $now;
            $DB->update_record('local_wwreports_comment', $existing);
            return;
        }
        $DB->insert_record('local_wwreports_comment', (object) [
            'userid' => $student,
            'courseid' => $courseid,
            'termkey' => $termkey,
            'commenterid' => $viewer,
            'commenttext' => $text,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Comments for one student in these courses during one term.
     *
     * @param int $student
     * @param int[] $courseids
     * @param string $termkey
     * @return array<int, array[]> Course id => comment rows.
     */
    public static function for_courses(int $student, array $courseids, string $termkey): array {
        global $DB;

        $courseids = array_values(array_unique(array_filter(array_map('intval', $courseids))));
        $grouped = [];
        foreach ($courseids as $courseid) {
            $grouped[$courseid] = [];
        }
        if (!$courseids || $termkey === '' || $student <= 0) {
            return $grouped;
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['userid'] = $student;
        $params['termkey'] = $termkey;
        $params['deleted'] = 0;
        $records = $DB->get_records_sql(
            "SELECT c.id, c.courseid, c.commenterid, c.commenttext,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename
               FROM {local_wwreports_comment} c
               JOIN {user} u ON u.id = c.commenterid AND u.deleted = :deleted
              WHERE c.userid = :userid
                AND c.termkey = :termkey
                AND c.courseid {$insql}
           ORDER BY c.timemodified ASC, c.id ASC",
            $params
        );
        foreach ($records as $record) {
            $text = self::plain((string) $record->commenttext);
            if ($text === '') {
                continue;
            }
            $grouped[(int) $record->courseid][] = [
                'author' => self::plain(fullname($record)),
                'text' => $text,
                'commenterid' => (int) $record->commenterid,
            ];
        }
        return $grouped;
    }

    /**
     * Plain text with no tags.
     *
     * @param string $value
     * @return string
     */
    private static function plain(string $value): string {
        return \local_wwpoints\points::plain($value);
    }
}
