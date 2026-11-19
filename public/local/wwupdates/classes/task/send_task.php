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

namespace local_wwupdates\task;

/**
 * Work due soon, and the weekly summary on the school's day and hour.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_task extends \core\task\scheduled_task {
    /**
     * The name shown to an administrator.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task', 'local_wwupdates');
    }

    /**
     * Send what is due, then the weekly summary when the school day has arrived.
     */
    public function execute() {
        \local_wwupdates\summary::send_due();
        \local_wwupdates\summary::send_weekly();
    }
}
