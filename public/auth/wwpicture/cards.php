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
 * Print the picture cards from this teacher's session.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$user = optional_param('user', 0, PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
require_capability('auth/wwpicture:manage', context_course::instance($course->id));

$PAGE->set_url(new moodle_url('/auth/wwpicture/cards.php', ['id' => $course->id, 'user' => $user]));
$PAGE->set_pagelayout('popup');
$PAGE->set_title(get_string('cards', 'auth_wwpicture'));
$PAGE->set_heading(format_string($course->fullname));

global $SESSION;
if ($user) {
    $one = $SESSION->wwpicturecards[$user] ?? null;
    $cards = $one ? [$one] : [];
} else {
    $cards = \auth_wwpicture\code::cards();
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('auth_wwpicture/cards', ['cards' => $cards]);
echo $OUTPUT->footer();
