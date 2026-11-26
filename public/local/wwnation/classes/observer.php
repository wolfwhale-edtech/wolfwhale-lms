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
 * Refresh the stored Nation when a person is enrolled or given a role.
 *
 * @package    local_wwnation
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Write this person's Nation membership again.
     *
     * @param \core\event\base $event
     */
    public static function user_changed(\core\event\base $event): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('local_wwnation')) {
            return;
        }
        $userid = (int) $event->relateduserid;
        if ($userid <= 0) {
            $userid = (int) $event->userid;
        }
        if ($userid > 0) {
            nation::sync_user($userid);
        }
    }
}
