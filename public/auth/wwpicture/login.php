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
 * Class sign-in page. Faces are shown only for the teacher's link.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$courseid = optional_param('course', 0, PARAM_INT);
$token = optional_param('t', '', PARAM_ALPHANUM);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/auth/wwpicture/login.php', ['course' => $courseid, 't' => $token]));
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('picture', 'auth_wwpicture'));

$students = [];
if (\auth_wwpicture\code::open($courseid, $token)) {
    foreach (\auth_wwpicture\code::students($courseid) as $user) {
        $students[] = [
            'name' => fullname($user),
            'face' => \auth_wwpicture\code::face($user),
            'url' => (new moodle_url('/auth/wwpicture/enter.php', [
                'course' => $courseid,
                't' => $token,
                'u' => $user->id,
            ]))->out(false),
        ];
    }
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('auth_wwpicture/roster', [
    'students' => $students,
    'hasstudents' => $students !== [],
]);
echo $OUTPUT->footer();
