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
 * Open a library resource from a course.
 *
 * @package    mod_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/wwlibrary/lib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('wwlibrary', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('wwlibrary', ['id' => $cm->instance], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/wwlibrary:view', $context);

$PAGE->set_url('/mod/wwlibrary/view.php', ['id' => $cm->id]);
$PAGE->set_title($course->shortname . ': ' . $instance->name);
$PAGE->set_heading($course->fullname);
$PAGE->set_activity_record($instance);
$PAGE->activityheader->set_attrs([
    'description' => '',
]);

echo $OUTPUT->header();
$resource = \local_wwlibrary\library::get((int) $instance->resourceid);
if (!$resource || !\local_wwlibrary\library::can_view_resource($resource)) {
    echo $OUTPUT->render_from_template('local_wwlibrary/viewer', [
        'isnone' => true,
        'none' => get_string('none', 'wwlibrary'),
    ]);
} else {
    $data = \local_wwlibrary\library::viewer($resource);
    $data['showtitle'] = false;
    echo $OUTPUT->render_from_template('local_wwlibrary/viewer', $data);
}
wwlibrary_view($instance, $course, $cm, $context);
echo $OUTPUT->footer();
