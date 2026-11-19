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
 * Course menu link for the class sign-in page.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add the class sign-in page for a teacher.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function auth_wwpicture_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('auth/wwpicture:manage', $context)) {
        return;
    }
    $navigation->add(
        get_string('picture', 'auth_wwpicture'),
        new moodle_url('/auth/wwpicture/class.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'wwpicture',
        new pix_icon('i/user', '')
    );
}
