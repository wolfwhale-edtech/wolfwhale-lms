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
 * Three picture taps. The third tap signs the student in.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$courseid = required_param('course', PARAM_INT);
$token = required_param('t', PARAM_ALPHANUM);
$userid = required_param('u', PARAM_INT);
$locked = optional_param('locked', 0, PARAM_BOOL);

$url = new moodle_url('/auth/wwpicture/enter.php', [
    'course' => $courseid,
    't' => $token,
    'u' => $userid,
]);
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('picture', 'auth_wwpicture'));

if (!\auth_wwpicture\code::open($courseid, $token)) {
    redirect(new moodle_url('/auth/wwpicture/login.php'));
}

$pick = optional_param('pic', '', PARAM_ALPHA);
$again = optional_param('again', 0, PARAM_BOOL);
if (data_submitted()) {
    require_sesskey();
    if ($again) {
        \auth_wwpicture\code::clear_try();
        redirect($url);
    }
    if ($pick !== '') {
        $status = \auth_wwpicture\code::tap($courseid, $token, $userid, $pick);
        if ($status === 'ok') {
            redirect(new moodle_url('/'));
        }
        if ($status === 'locked') {
            redirect(new moodle_url($url, ['locked' => 1]));
        }
        if ($status === 'bad' || $status === 'denied' || $status === 'closed') {
            \auth_wwpicture\code::clear_try();
        }
        redirect($url);
    }
}

$user = $DB->get_record('user', ['id' => $userid],
    'id, firstname, lastname, middlename, alternatename, firstnamephonetic, lastnamephonetic', IGNORE_MISSING);
$picks = \auth_wwpicture\code::current_picks($courseid, $userid);
$slots = [];
foreach ($picks as $key) {
    $slots[] = ['svg' => \auth_wwpicture\pictures::svg($key)];
}
$pics = [];
foreach (\auth_wwpicture\pictures::KEYS as $key) {
    $pics[] = [
        'key' => $key,
        'word' => \auth_wwpicture\pictures::word($key),
        'svg' => \auth_wwpicture\pictures::svg($key),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('auth_wwpicture/enter', [
    'url' => $url->out(false),
    'sesskey' => sesskey(),
    'name' => $user ? fullname($user) : '',
    'face' => $user ? \auth_wwpicture\code::face($user) : '',
    'slots' => $slots,
    'haspicks' => $slots !== [],
    'locked' => (bool) $locked,
    'pics' => $pics,
]);
echo $OUTPUT->footer();
