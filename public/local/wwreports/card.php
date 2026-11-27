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
 * A term report card for one student.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$studentid = optional_param('student', 0, PARAM_INT);
$termid = optional_param('term', '', PARAM_ALPHANUMEXT);
$download = optional_param('download', '', PARAM_ALPHA);
$viewer = (int) $USER->id;

if (data_submitted()) {
    require_sesskey();
    $courseid = required_param('course', PARAM_INT);
    $text = optional_param('comment', '', PARAM_TEXT);
    if ($studentid <= 0) {
        throw new moodle_exception('cannotview', 'local_wwreports');
    }
    \local_wwreports\access::require_view($viewer, $studentid);
    $term = \local_wwreports\terms::pick(
        \local_wwreports\terms::for_courses(\local_wwreports\access::courses_for($viewer, $studentid)),
        $termid
    );
    \local_wwreports\comment::save($viewer, $studentid, $courseid, (string) $term['id'], $text);
    redirect(new moodle_url('/local/wwreports/card.php', [
        'student' => $studentid,
        'term' => $term['id'],
    ]));
}

$roster = \local_wwreports\access::roster($viewer);
if ($studentid <= 0 && count($roster) === 1) {
    $studentid = (int) array_key_first($roster);
}

if ($studentid <= 0) {
    $data = \local_wwreports\card::picker($roster);
    $url = new moodle_url('/local/wwreports/card.php');
    $PAGE->set_context(context_user::instance($USER->id));
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('base');
    $PAGE->set_secondary_navigation(false);
    $title = get_string('report', 'local_wwreports');
    $PAGE->set_title($title);
    $PAGE->set_heading($title);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_wwreports/card', $data);
    echo $OUTPUT->footer();
    exit;
}

\local_wwreports\access::require_view($viewer, $studentid);
$courses = \local_wwreports\access::courses_for($viewer, $studentid);
$terms = \local_wwreports\terms::for_courses($courses);
$term = \local_wwreports\terms::pick($terms, $termid);
$data = \local_wwreports\card::build($viewer, $studentid, $term);
$data['sesskey'] = sesskey();
foreach ($data['courses'] as $index => $course) {
    $data['courses'][$index]['sesskey'] = $data['sesskey'];
}

if ($download === 'csv') {
    \local_wwreports\card::send_csv($data);
}
if ($download === 'html') {
    \local_wwreports\card::send_html($data);
}

$url = new moodle_url('/local/wwreports/card.php', [
    'student' => $studentid,
    'term' => $term['id'],
]);
$PAGE->set_context(context_user::instance($studentid));
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title($data['studentname']);
$PAGE->set_heading($data['studentname']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwreports/card', $data);
echo $OUTPUT->footer();
