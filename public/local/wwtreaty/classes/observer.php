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
 * Turn a real completion into evidence for each outcome tagged on that activity.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * An activity was marked complete, or the completion was cleared.
     *
     * @param \core\event\base $event
     */
    public static function completion_updated(\core\event\base $event): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');
        $userid = (int) $event->relateduserid;
        $cmid = (int) $event->contextinstanceid;
        $courseid = (int) $event->courseid;
        if ($userid <= 0 || $cmid <= 0 || $courseid <= 0) {
            return;
        }
        $state = (int) ($event->other['completionstate'] ?? 0);
        if ($state !== COMPLETION_COMPLETE && $state !== COMPLETION_COMPLETE_PASS) {
            coverage::clear_auto($userid, $cmid);
            return;
        }
        $tags = $DB->get_records('local_wwtreaty_tag', ['cmid' => $cmid]);
        foreach ($tags as $tag) {
            if (!$DB->record_exists('local_wwtreaty', ['id' => $tag->outcomeid, 'active' => 1])) {
                continue;
            }
            coverage::store((int) $tag->outcomeid, $userid, $courseid, $cmid, 0);
        }
    }

    /**
     * A course reset can delete completion rows without an event for each activity.
     *
     * @param \core\event\base $event
     */
    public static function course_reset_ended(\core\event\base $event): void {
        if ((int) $event->courseid > 0) {
            coverage::reconcile_auto((int) $event->courseid);
        }
    }
}
