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
 * Set the goal for a course this teacher teaches.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$courses = \local_wwpoints\points::courses_for('local/wwpoints:setgoal');
if (!$courses) {
    redirect(new moodle_url('/my/'));
}

$courseid = optional_param('courseid', 0, PARAM_INT);
if (!$courseid && count($courses) === 1) {
    $courseid = (int) reset($courses)->id;
}

$url = new moodle_url('/local/wwpoints/goal.php');
$data = [];
if ($courseid) {
    $url->param('courseid', $courseid);
    $course = get_course($courseid);
    require_login($course);
    $context = \local_wwpoints\goal::require_course($courseid);
    if (data_submitted()) {
        require_sesskey();
        \local_wwpoints\goal::save_course(
            $courseid,
            required_param('name', PARAM_TEXT),
            required_param('target', PARAM_INT),
            \local_wwpoints\goal::day_end(required_param('end', PARAM_RAW_TRIMMED))
        );
        redirect($url);
    }
    $goal = \local_wwpoints\goal::for_course($courseid);
    $title = \local_wwpoints\points::plain($course->fullname);
    $data['form'] = true;
    $data['name'] = $goal ? \local_wwpoints\points::plain($goal->name) : '';
    $data['target'] = $goal ? (int) $goal->target : '';
    $data['end'] = $goal ? \local_wwpoints\goal::day_value((int) $goal->timeend) : '';
    $data['goals'] = $goal ? [\local_wwpoints\goal::progress($goal)] : [];
} else {
    $rows = [];
    foreach ($courses as $course) {
        $goal = \local_wwpoints\goal::for_course((int) $course->id);
        $rows[] = [
            'word' => \local_wwpoints\points::plain($course->fullname),
            'fact' => $goal ? \local_wwpoints\points::plain($goal->name) : get_string('none', 'local_wwpoints'),
            'url' => (new moodle_url('/local/wwpoints/goal.php', ['courseid' => $course->id]))->out(false),
        ];
    }
    $context = context_user::instance($USER->id);
    $title = get_string('goal', 'local_wwpoints');
    $data['rows'] = $rows;
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
$data['nameword'] = get_string('name', 'local_wwpoints');
$data['targetword'] = get_string('target', 'local_wwpoints');
$data['endword'] = get_string('end', 'local_wwpoints');
$data['save'] = get_string('save', 'local_wwpoints');
$data['read'] = get_string('read', 'local_wwpoints');
$speech = [$title];
foreach ($data['goals'] ?? [] as $bar) {
    $speech[] = $bar['speech'];
}
$data['speech'] = implode(' ', $speech);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwpoints/goal', $data);
echo $OUTPUT->footer();
