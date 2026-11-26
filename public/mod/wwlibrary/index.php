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
 * Library resources in one course.
 *
 * @package    mod_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_course_login($course);

$PAGE->set_url('/mod/wwlibrary/index.php', ['id' => $id]);
$PAGE->set_title(get_string('library', 'local_wwlibrary'));
$PAGE->set_heading($course->fullname);

$instances = get_all_instances_in_course('wwlibrary', $course);
$rows = [];
foreach ($instances as $instance) {
    $rows[] = [
        'name' => \local_wwlibrary\library::plain($instance->name),
        'url' => (new moodle_url('/mod/wwlibrary/view.php', ['id' => $instance->coursemodule]))->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwlibrary/index', [
    'kinds' => [],
    'folders' => [],
    'resources' => array_map(function($row) {
        return [
            'name' => $row['name'],
            'url' => $row['url'],
            'ismap' => true,
        ];
    }, $rows),
    'manage' => false,
    'manyschools' => false,
    'screenspeech' => '',
    'read' => get_string('read', 'local_wwlibrary'),
]);
echo $OUTPUT->footer();
