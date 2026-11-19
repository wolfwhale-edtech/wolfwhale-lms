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
 * Give or take points in a course this teacher teaches.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$courses = \local_wwpoints\points::courses_for('local/wwpoints:award');
if (!$courses) {
    redirect(new moodle_url('/my/'));
}

$courseid = optional_param('courseid', 0, PARAM_INT);
if (!$courseid && count($courses) === 1) {
    $courseid = (int) reset($courses)->id;
}

$url = new moodle_url('/local/wwpoints/award.php');
if ($courseid) {
    $url->param('courseid', $courseid);
    $course = get_course($courseid);
    require_login($course);
    if (data_submitted()) {
        require_sesskey();
        $action = required_param('action', PARAM_ALPHA);
        \local_wwpoints\points::adjust(
            $courseid,
            required_param('student', PARAM_INT),
            required_param('amount', PARAM_INT),
            required_param('reason', PARAM_TEXT),
            $action === 'take'
        );
        redirect($url);
    }
    $people = [];
    foreach (\local_wwpoints\points::students($courseid) as $user) {
        $people[] = [
            'id' => (int) $user->id,
            'name' => \local_wwpoints\points::plain(fullname($user)),
            'total' => (string) \local_wwpoints\points::course_total((int) $user->id, $courseid),
            'avatar' => \local_wwpoints\avatar::svg_for((int) $user->id),
            'pointsword' => get_string('points', 'local_wwpoints'),
            'reasonword' => get_string('reason', 'local_wwpoints'),
            'give' => get_string('give', 'local_wwpoints'),
            'take' => get_string('take', 'local_wwpoints'),
            'action' => $url->out(false),
            'sesskey' => sesskey(),
        ];
    }
    $context = \context_course::instance($courseid);
    $title = \local_wwpoints\points::plain($course->fullname);
    $data = [
        'course' => true,
        'students' => $people,
        'empty' => $people === [],
        'none' => get_string('none', 'local_wwpoints'),
    ];
} else {
    $rows = [];
    foreach ($courses as $course) {
        $rows[] = [
            'word' => \local_wwpoints\points::plain($course->fullname),
            'url' => (new moodle_url('/local/wwpoints/award.php', ['courseid' => $course->id]))->out(false),
        ];
    }
    $context = context_user::instance($USER->id);
    $title = get_string('points', 'local_wwpoints');
    $data = ['rows' => $rows];
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title($title);
$PAGE->set_heading($title);

$data['action'] = $url->out(false);
$data['sesskey'] = sesskey();
$data['title'] = $title;
$data['read'] = get_string('read', 'local_wwpoints');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwpoints/award', $data);
echo $OUTPUT->footer();
