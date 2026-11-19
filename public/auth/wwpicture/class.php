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
 * Teacher page: the class link, a reset, and the print cards.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($course->id);
require_capability('auth/wwpicture:manage', $context);

$url = new moodle_url('/auth/wwpicture/class.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_title(get_string('picture', 'auth_wwpicture'));
$PAGE->set_heading(format_string($course->fullname));

$reset = optional_param('reset', 0, PARAM_INT);
$all = optional_param('all', 0, PARAM_BOOL);
if (data_submitted() && ($reset || $all)) {
    require_sesskey();
    $cards = new moodle_url('/auth/wwpicture/cards.php', ['id' => $course->id]);
    if ($all) {
        foreach (\auth_wwpicture\code::students($course->id) as $user) {
            \auth_wwpicture\code::reset_student((int) $user->id);
        }
        redirect($cards);
    }
    if (\auth_wwpicture\code::students($course->id)) {
        $ids = array_map(function($user) {
            return (int) $user->id;
        }, \auth_wwpicture\code::students($course->id));
        if (in_array($reset, $ids, true)) {
            \auth_wwpicture\code::reset_student($reset);
            redirect(new moodle_url($cards, ['user' => $reset]));
        }
    }
    redirect($url);
}

$login = new moodle_url('/auth/wwpicture/login.php', [
    'course' => $course->id,
    't' => \auth_wwpicture\code::token($course->id),
]);
$qr = new core_qrcode($login->out(false));
$students = [];
foreach (\auth_wwpicture\code::students($course->id) as $user) {
    $students[] = [
        'id' => (int) $user->id,
        'name' => fullname($user),
        'face' => \auth_wwpicture\code::face($user),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('auth_wwpicture/teacher', [
    'coursename' => format_string($course->shortname),
    'url' => $login->out(false),
    'qr' => $qr->getBarcodeSVGcode(3, 3, '#2f5d8a'),
    'sesskey' => sesskey(),
    'action' => $url->out(false),
    'students' => $students,
]);
echo $OUTPUT->footer();
