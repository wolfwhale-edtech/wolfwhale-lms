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
 * Turn real events into parent updates.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A new mark in a course the child is enrolled in.
     *
     * Course totals are skipped. Saving an activity mark already updates the total.
     *
     * @param \core\event\user_graded $event
     */
    public static function graded(\core\event\user_graded $event): void {
        global $CFG;

        $childid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if (!audience::enrolled($childid, $courseid)) {
            return;
        }
        $course = get_course($courseid);
        if (empty($course->showgrades)) {
            return;
        }
        require_once($CFG->libdir . '/gradelib.php');
        $grade = $event->get_grade();
        $item = $grade->grade_item ?? null;
        if (!$item || !in_array($item->itemtype, ['mod', 'manual'], true)) {
            return;
        }
        if ($grade->finalgrade === null || $item->is_hidden() || $grade->is_hidden()) {
            return;
        }
        $text = audience::plain(grade_format_gradevalue(
            (float) $grade->finalgrade,
            $item,
            true,
            GRADE_DISPLAY_TYPE_REAL
        ));
        $name = audience::plain((string) ($item->itemname ?: $item->get_name()));
        if ($text === '' || $name === '') {
            return;
        }
        self::tell(
            $childid,
            $courseid,
            'mark',
            'grade:' . (int) $grade->id . ':' . $grade->finalgrade,
            [get_string('markline', 'local_wwupdates', (object) ['grade' => $text, 'item' => $name])]
        );
    }

    /**
     * A missed attendance mark.
     *
     * @param \core\event\base $event
     */
    public static function missed(\core\event\base $event): void {
        $childid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        if (!audience::enrolled($childid, $courseid)) {
            return;
        }
        $course = get_course($courseid);
        $sessionid = (int) ($event->other['sessionid'] ?? 0);
        self::tell(
            $childid,
            $courseid,
            'absent',
            'absent:' . $sessionid . ':' . $childid,
            [get_string('absentline', 'local_wwupdates', audience::plain($course->fullname))]
        );
    }

    /**
     * A badge the child earned.
     *
     * @param \core\event\badge_awarded $event
     */
    public static function badge(\core\event\badge_awarded $event): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/badgeslib.php');
        $childid = (int) $event->relateduserid;
        $badge = $DB->get_record('badge', ['id' => $event->objectid], 'id, name, type, courseid', IGNORE_MISSING);
        if (!$badge) {
            return;
        }
        $courseid = 0;
        if ((int) $badge->type === BADGE_TYPE_COURSE && (int) $badge->courseid > 0) {
            $courseid = (int) $badge->courseid;
            if (!audience::enrolled($childid, $courseid)) {
                return;
            }
        }
        $issued = (int) ($event->other['badgeissuedid'] ?? 0);
        if ($issued <= 0) {
            return;
        }
        $award = $DB->get_record('badge_issued', ['id' => $issued], 'id, visible', IGNORE_MISSING);
        if (!$award || empty($award->visible)) {
            return;
        }
        self::tell(
            $childid,
            $courseid,
            'badge',
            'badge:' . $issued,
            [get_string('badgeline', 'local_wwupdates', audience::plain($badge->name))]
        );
    }

    /**
     * A higher level.
     *
     * @param \core\event\base $event
     */
    public static function level(\core\event\base $event): void {
        $childid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;
        $level = (int) ($event->other['level'] ?? 0);
        if ($level <= 0) {
            return;
        }
        if ($courseid > 0 && !audience::enrolled($childid, $courseid)) {
            return;
        }
        self::tell(
            $childid,
            $courseid,
            'badge',
            'level:' . $childid . ':' . $level,
            [get_string('levelline', 'local_wwupdates', $level)]
        );
    }

    /**
     * A teacher wrote to the child. The message text is not copied.
     *
     * @param \core\event\message_sent $event
     */
    public static function teacher_message(\core\event\message_sent $event): void {
        $teacherid = (int) $event->userid;
        $childid = (int) $event->relateduserid;
        $courseid = audience::shared_course($teacherid, $childid);
        if ($courseid <= 0) {
            return;
        }
        self::tell(
            $childid,
            $courseid,
            'message',
            'message:' . (int) $event->objectid,
            [get_string('messageline', 'local_wwupdates', audience::first_name($teacherid))]
        );
    }

    /**
     * Send one line to each linked parent.
     *
     * @param int $childid
     * @param int $courseid Zero when the event is not in one course.
     * @param string $kind
     * @param string $sourcekey
     * @param string[] $lines
     */
    private static function tell(int $childid, int $courseid, string $kind, string $sourcekey, array $lines): void {
        if ($courseid > 0) {
            $school = audience::school_name_for_course($courseid);
        } else {
            $school = audience::category_name(audience::primary_school($childid));
        }
        $child = audience::first_name($childid);
        foreach (audience::parent_ids($childid) as $parentid) {
            deliver::send($parentid, $childid, $kind, $sourcekey, $school, $child, $lines);
        }
    }
}
