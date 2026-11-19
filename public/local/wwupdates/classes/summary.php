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
 * Work due soon, and one weekly summary per parent per child.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class summary {
    /**
     * Tell parents about work due in the next two days.
     */
    public static function send_due(): void {
        $now = time();
        foreach (self::due_items_for_pairs($now, $now + (2 * DAYSECS)) as $item) {
            deliver::send(
                $item['parentid'],
                $item['childid'],
                'due',
                'due:' . $item['cmid'] . ':' . $item['due'],
                $item['school'],
                $item['child'],
                [get_string('dueline', 'local_wwupdates', $item['name'])]
            );
        }
    }

    /**
     * One summary for each linked child, on that school's day and hour.
     */
    public static function send_weekly(): void {
        $since = time() - (7 * DAYSECS);
        $ahead = time() + (7 * DAYSECS);
        foreach (audience::pairs() as $pair) {
            $parentid = (int) $pair->parentid;
            $childid = (int) $pair->childid;
            $schoolid = audience::primary_school($childid);
            if ($schoolid <= 0 || !schedule::ready($schoolid)) {
                continue;
            }
            $lines = array_merge(
                self::mark_lines($childid, $since),
                self::absent_lines($childid, $since),
                self::due_lines($childid, time(), $ahead),
                self::badge_lines($childid, $since),
                self::point_lines($childid, $since),
                self::message_lines($childid, $since)
            );
            deliver::send(
                $parentid,
                $childid,
                'weekly',
                schedule::week_key(),
                audience::category_name($schoolid),
                audience::first_name($childid),
                $lines
            );
        }
    }

    /**
     * Due items for every linked child.
     *
     * @param int $from
     * @param int $until
     * @return array[]
     */
    private static function due_items_for_pairs(int $from, int $until): array {
        $items = [];
        foreach (audience::pairs() as $pair) {
            $childid = (int) $pair->childid;
            $child = audience::first_name($childid);
            foreach (self::due_rows($childid, $from, $until) as $row) {
                $items[] = $row + [
                    'parentid' => (int) $pair->parentid,
                    'childid' => $childid,
                    'child' => $child,
                ];
            }
        }
        return $items;
    }

    /**
     * Due lines for one child.
     *
     * @param int $childid
     * @param int $from
     * @param int $until
     * @return string[]
     */
    private static function due_lines(int $childid, int $from, int $until): array {
        $lines = [];
        foreach (self::due_rows($childid, $from, $until) as $row) {
            $lines[] = get_string('dueline', 'local_wwupdates', $row['name']);
        }
        return $lines;
    }

    /**
     * Assignments due in the window that this child has not submitted.
     *
     * @param int $childid
     * @param int $from
     * @param int $until
     * @return array[]
     */
    private static function due_rows(int $childid, int $from, int $until): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $rows = [];
        $courses = enrol_get_users_courses($childid, true, 'id');
        foreach ($courses as $listed) {
            if ((int) $listed->id === (int) SITEID) {
                continue;
            }
            $course = get_course($listed->id);
            $modinfo = get_fast_modinfo($course->id, $childid);
            foreach ($modinfo->get_instances_of('assign') as $cm) {
                if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
                    continue;
                }
                $context = \context_module::instance($cm->id);
                $assign = new \assign($context, $cm, $course);
                $assign->update_effective_access($childid);
                $instance = $assign->get_instance($childid);
                $due = (int) $instance->duedate;
                $flags = $assign->get_user_flags($childid, false);
                if ($flags && !empty($flags->extensionduedate) && (int) $flags->extensionduedate > $due) {
                    $due = (int) $flags->extensionduedate;
                }
                if ($due < $from || $due > $until || $due <= 0) {
                    continue;
                }
                if (!empty($instance->teamsubmission)) {
                    $submission = $assign->get_group_submission($childid, 0, false);
                } else {
                    $submission = $assign->get_user_submission($childid, false);
                }
                if ($submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                    continue;
                }
                $rows[] = [
                    'cmid' => (int) $cm->id,
                    'due' => $due,
                    'name' => audience::plain($cm->name),
                    'school' => audience::school_name_for_course((int) $course->id),
                ];
            }
        }
        return $rows;
    }

    /**
     * Marks saved this week.
     *
     * @param int $childid
     * @param int $since
     * @return string[]
     */
    private static function mark_lines(int $childid, int $since): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/gradelib.php');
        $records = $DB->get_records_sql(
            "SELECT gg.id, gg.finalgrade, gg.hidden, gi.itemname, gi.courseid, gi.id AS itemid
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gg.userid = ?
                AND gg.finalgrade IS NOT NULL
                AND gg.timemodified >= ?
                AND gi.itemtype IN ('mod', 'manual')
                AND gi.hidden = 0",
            [$childid, $since]
        );
        $lines = [];
        foreach ($records as $record) {
            $hidden = (int) $record->hidden;
            if ($hidden === 1 || ($hidden > 1 && $hidden > time())) {
                continue;
            }
            if (!audience::enrolled($childid, (int) $record->courseid)) {
                continue;
            }
            $item = \grade_item::fetch(['id' => $record->itemid]);
            if (!$item) {
                continue;
            }
            $grade = audience::plain(grade_format_gradevalue(
                (float) $record->finalgrade,
                $item,
                true,
                GRADE_DISPLAY_TYPE_REAL
            ));
            $name = audience::plain((string) ($record->itemname ?: $item->get_name()));
            if ($grade === '' || $name === '') {
                continue;
            }
            $lines[] = get_string('markline', 'local_wwupdates', (object) [
                'grade' => $grade,
                'item' => $name,
            ]);
        }
        return $lines;
    }

    /**
     * Absences this week.
     *
     * @param int $childid
     * @param int $since
     * @return string[]
     */
    private static function absent_lines(int $childid, int $since): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_wwattendance_log')) {
            return [];
        }
        $records = $DB->get_records_sql(
            "SELECT l.id, c.fullname, c.id AS courseid
               FROM {local_wwattendance_log} l
               JOIN {local_wwattendance_session} s ON s.id = l.sessionid
               JOIN {course} c ON c.id = s.courseid
              WHERE l.userid = ?
                AND l.status = ?
                AND s.sessdate >= ?",
            [$childid, 'absent', $since]
        );
        $lines = [];
        foreach ($records as $record) {
            if (!audience::enrolled($childid, (int) $record->courseid)) {
                continue;
            }
            $lines[] = get_string('absentline', 'local_wwupdates', audience::plain($record->fullname));
        }
        return $lines;
    }

    /**
     * Badges earned this week that other people may see.
     *
     * @param int $childid
     * @param int $since
     * @return string[]
     */
    private static function badge_lines(int $childid, int $since): array {
        global $CFG, $DB;

        if (empty($CFG->enablebadges)) {
            return [];
        }
        $records = $DB->get_records_sql(
            "SELECT bi.id, b.name
               FROM {badge_issued} bi
               JOIN {badge} b ON b.id = bi.badgeid
              WHERE bi.userid = ?
                AND bi.dateissued >= ?
                AND bi.visible = 1",
            [$childid, $since]
        );
        $lines = [];
        foreach ($records as $record) {
            $lines[] = get_string('badgeline', 'local_wwupdates', audience::plain($record->name));
        }
        return $lines;
    }

    /**
     * Points earned this week, and the current level.
     *
     * @param int $childid
     * @param int $since
     * @return string[]
     */
    private static function point_lines(int $childid, int $since): array {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_wwpoints_ledger')) {
            return [];
        }
        $earned = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0) FROM {local_wwpoints_ledger} WHERE userid = ? AND timecreated >= ?",
            [$childid, $since]
        );
        if ($earned === 0) {
            return [];
        }
        $total = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0) FROM {local_wwpoints_ledger} WHERE userid = ?",
            [$childid]
        );
        return [
            get_string('pointsline', 'local_wwupdates', $earned),
            get_string('levelline', 'local_wwupdates', \local_wwpoints\levels::for_points($total)),
        ];
    }

    /**
     * Teachers who wrote to this child this week. The message text is not copied.
     *
     * @param int $childid
     * @param int $since
     * @return string[]
     */
    private static function message_lines(int $childid, int $since): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT m.id, m.useridfrom
               FROM {messages} m
               JOIN {message_conversation_members} mem
                 ON mem.conversationid = m.conversationid AND mem.userid = ?
              WHERE m.useridfrom <> ?
                AND m.timecreated >= ?",
            [$childid, $childid, $since]
        );
        $lines = [];
        $seen = [];
        foreach ($records as $record) {
            $teacherid = (int) $record->useridfrom;
            if (isset($seen[$teacherid]) || !audience::teaches($teacherid, $childid)) {
                continue;
            }
            $seen[$teacherid] = true;
            $lines[] = get_string('messageline', 'local_wwupdates', audience::first_name($teacherid));
        }
        return $lines;
    }
}
