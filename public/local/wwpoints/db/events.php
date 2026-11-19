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

/**
 * Event observers that write the points ledger.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\local_wwpoints\observer::assignment_submitted',
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_wwpoints\observer::completion_updated',
    ],
    [
        'eventname' => '\core\event\badge_awarded',
        'callback' => '\local_wwpoints\observer::badge_awarded',
    ],
    [
        'eventname' => '\local_wwattendance\event\attendance_marked',
        'callback' => '\local_wwpoints\observer::marked_present',
    ],
];
