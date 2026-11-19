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

namespace local_wwpoints;

/**
 * Award points from real events. The ledger ignores a repeated source.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A student submitted an assignment.
     *
     * @param \core\event\base $event
     */
    public static function assignment_submitted(\core\event\base $event): void {
        $userid = (int) ($event->relateduserid ?: $event->userid);
        $submissionid = (int) $event->objectid;
        if ($userid <= 0 || $submissionid <= 0 || (int) $event->courseid <= 0) {
            return;
        }
        points::record(
            $userid,
            (int) $event->courseid,
            points::amount('assign'),
            get_string('reasonassign', 'local_wwpoints'),
            $event->eventname,
            'assign:' . $submissionid
        );
    }

    /**
     * An activity was marked complete.
     *
     * @param \core\event\base $event
     */
    public static function completion_updated(\core\event\base $event): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $state = (int) ($event->other['completionstate'] ?? 0);
        if ($state !== COMPLETION_COMPLETE && $state !== COMPLETION_COMPLETE_PASS) {
            return;
        }
        $userid = (int) $event->relateduserid;
        $cmid = (int) $event->contextinstanceid;
        if ($userid <= 0 || $cmid <= 0 || (int) $event->courseid <= 0) {
            return;
        }
        points::record(
            $userid,
            (int) $event->courseid,
            points::amount('completion'),
            get_string('reasoncompletion', 'local_wwpoints'),
            $event->eventname,
            'completion:' . $cmid . ':' . $userid
        );
    }

    /**
     * A badge was earned. A course badge stays in that course.
     *
     * @param \core\event\base $event
     */
    public static function badge_awarded(\core\event\base $event): void {
        $issued = (int) ($event->other['badgeissuedid'] ?? 0);
        $userid = (int) $event->relateduserid;
        if ($issued <= 0 || $userid <= 0) {
            return;
        }
        $courseid = ((int) $event->contextlevel === CONTEXT_COURSE) ? (int) $event->courseid : 0;
        points::record(
            $userid,
            $courseid,
            points::amount('badge'),
            get_string('reasonbadge', 'local_wwpoints'),
            $event->eventname,
            'badge:' . $issued
        );
    }

    /**
     * A student was marked present.
     *
     * @param \core\event\base $event
     */
    public static function marked_present(\core\event\base $event): void {
        $sessionid = (int) ($event->other['sessionid'] ?? 0);
        $userid = (int) $event->relateduserid;
        if ($sessionid <= 0 || $userid <= 0 || (int) $event->courseid <= 0) {
            return;
        }
        points::record(
            $userid,
            (int) $event->courseid,
            points::amount('present'),
            get_string('reasonpresent', 'local_wwpoints'),
            $event->eventname,
            'present:' . $sessionid . ':' . $userid,
            (int) $event->userid
        );
    }
}
