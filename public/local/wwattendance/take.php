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
 * Take attendance for one course.
 *
 * @package    local_wwattendance
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
require_login($course);

$context = \context_course::instance($course->id);
require_capability('local/wwattendance:take', $context);

$url = new moodle_url('/local/wwattendance/take.php', ['courseid' => $course->id]);
$day = \local_wwattendance\attendance::day_start();

if (data_submitted()) {
    require_sesskey();
    $raw = optional_param_array('status', [], PARAM_ALPHA);
    \local_wwattendance\attendance::save($course->id, $raw, $day);
    redirect($url);
}

$saved = \local_wwattendance\attendance::marks($course->id, $day);
$people = [];
foreach (\local_wwattendance\attendance::students($course->id) as $user) {
    $status = $saved[(int) $user->id] ?? '';
    $people[] = [
        'id' => (int) $user->id,
        'name' => fullname($user),
        'ispresent' => $status === \local_wwattendance\attendance::PRESENT,
        'isabsent' => $status === \local_wwattendance\attendance::ABSENT,
        'islate' => $status === \local_wwattendance\attendance::LATE,
    ];
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$PAGE->set_course($course);
$title = format_string($course->fullname, true, ['context' => $context, 'escape' => false]);
$title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwattendance/take', [
    'course' => $title,
    'action' => $url->out(false),
    'courseid' => $course->id,
    'sesskey' => sesskey(),
    'students' => $people,
    'empty' => $people === [],
    'none' => get_string('none', 'local_wwattendance'),
    'present' => get_string('present', 'local_wwattendance'),
    'absent' => get_string('absent', 'local_wwattendance'),
    'late' => get_string('late', 'local_wwattendance'),
    'save' => get_string('save', 'local_wwattendance'),
    'hassave' => $people !== [],
]);
echo $OUTPUT->footer();
