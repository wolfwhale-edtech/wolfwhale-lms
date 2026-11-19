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
 * Read a page, book, or file without recording a visit.
 *
 * The activity view page records the visit and can mark the activity complete.
 * Fetching this address ahead of time does not.
 *
 * @package    local_wwoffline
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);
$cmrecord = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
if (!in_array($cmrecord->modname, \local_wwoffline\lessons::MODULES, true)) {
    throw new moodle_exception('invalidcoursemodule');
}

$course = get_course($cmrecord->course);
require_course_login($course, true, $cmrecord);
$cm = get_fast_modinfo($course)->get_cm($cmrecord->id);
if (!$cm->uservisible) {
    throw new moodle_exception('nopermissions', 'error', '', 'view');
}

$PAGE->set_url(\local_wwoffline\lessons::read_url($cm));
$PAGE->set_context($cm->context);
$PAGE->set_cm($cm, $course);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_title($course->shortname . ': ' . $cm->name);
$PAGE->set_heading($course->fullname);

// The worker stores this response under the real activity address as well.
header('X-WW-Alias: ' . \local_wwoffline\lessons::view_url($cm)->out(false));

echo $OUTPUT->header();
echo $OUTPUT->heading($cm->name);
echo \local_wwoffline\lessons::readable($cm);
echo $OUTPUT->footer();
