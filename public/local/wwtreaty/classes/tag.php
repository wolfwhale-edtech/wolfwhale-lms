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
 * Outcomes tagged on activities in one course.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tag {
    /**
     * Activities in the course, in section order.
     *
     * @param int $courseid
     * @return \cm_info[]
     */
    public static function activities(int $courseid): array {
        $modinfo = get_fast_modinfo($courseid);
        $items = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            $items[] = $cm;
        }
        usort($items, static function ($a, $b) {
            return [$a->sectionnum, $a->id] <=> [$b->sectionnum, $b->id];
        });
        return $items;
    }

    /**
     * How many active outcomes are tagged on this activity.
     *
     * @param int $cmid
     * @return int
     */
    public static function count_for_activity(int $cmid): int {
        global $DB;

        $sql = "SELECT COUNT(1)
                  FROM {local_wwtreaty_tag} t
                  JOIN {local_wwtreaty} o ON o.id = t.outcomeid
                 WHERE t.cmid = :cmid
                   AND o.active = 1";
        return (int) $DB->count_records_sql($sql, ['cmid' => $cmid]);
    }

    /**
     * Active outcome ids tagged on this activity.
     *
     * @param int $cmid
     * @return int[]
     */
    public static function outcome_ids(int $cmid): array {
        global $DB;

        $sql = "SELECT o.id
                  FROM {local_wwtreaty_tag} t
                  JOIN {local_wwtreaty} o ON o.id = t.outcomeid
                 WHERE t.cmid = :cmid
                   AND o.active = 1";
        $ids = $DB->get_fieldset_sql($sql, ['cmid' => $cmid]);
        return array_map('intval', $ids);
    }

    /**
     * Tag or untag one active outcome on one activity in a course this person teaches.
     *
     * Tagging writes evidence for students who already completed the activity.
     * Untagging removes that automatic evidence and leaves a mark a teacher recorded.
     *
     * @param int $cmid
     * @param int $outcomeid
     * @param bool $on
     */
    public static function set(int $cmid, int $outcomeid, bool $on): void {
        global $DB, $USER;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        if (!empty($cm->deletioninprogress)) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        school::require_tag((int) $cm->course);
        $schoolid = school::school_for_course((int) $cm->course);
        $outcome = $DB->get_record('local_wwtreaty', [
            'id' => $outcomeid,
            'categoryid' => $schoolid,
            'active' => 1,
        ], '*', IGNORE_MISSING);
        if (!$outcome) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $existing = $DB->get_record('local_wwtreaty_tag', ['outcomeid' => $outcomeid, 'cmid' => $cmid]);
        if ($on) {
            if ($existing) {
                return;
            }
            $now = time();
            $DB->insert_record('local_wwtreaty_tag', (object) [
                'outcomeid' => $outcomeid,
                'cmid' => $cmid,
                'courseid' => (int) $cm->course,
                'usermodified' => (int) $USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            self::backfill($cmid, $outcomeid, (int) $cm->course);
            return;
        }
        if (!$existing) {
            return;
        }
        $DB->delete_records('local_wwtreaty_tag', ['id' => $existing->id]);
        $DB->delete_records('local_wwtreaty_evidence', [
            'outcomeid' => $outcomeid,
            'cmid' => $cmid,
            'usermodified' => 0,
        ]);
    }

    /**
     * Students who already finished this activity meet the outcome.
     *
     * @param int $cmid
     * @param int $outcomeid
     * @param int $courseid
     */
    private static function backfill(int $cmid, int $outcomeid, int $courseid): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');
        $rows = $DB->get_records_select(
            'course_modules_completion',
            'coursemoduleid = :cmid AND completionstate IN (:complete, :pass)',
            [
                'cmid' => $cmid,
                'complete' => COMPLETION_COMPLETE,
                'pass' => COMPLETION_COMPLETE_PASS,
            ]
        );
        foreach ($rows as $row) {
            coverage::store($outcomeid, (int) $row->userid, $courseid, $cmid, 0);
        }
    }
}
