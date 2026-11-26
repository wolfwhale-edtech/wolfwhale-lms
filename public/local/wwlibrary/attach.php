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
 * Attach one library resource to a course.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

global $OUTPUT, $USER;

$id = required_param('id', PARAM_INT);
$resource = \local_wwlibrary\library::get($id);
if (!$resource || !\local_wwlibrary\library::can_manage_resource($resource)) {
    throw new moodle_exception('cannotview', 'local_wwlibrary');
}

$courses = \local_wwlibrary\library::attachable_courses((int) $resource->categoryid);
$options = [];
foreach ($courses as $course) {
    $modinfo = get_fast_modinfo($course);
    $sections = $modinfo->get_section_info_all();
    foreach ($sections as $section) {
        $label = \local_wwlibrary\library::plain($course->fullname);
        if (count($sections) > 1) {
            $sectionname = \local_wwlibrary\library::plain(get_section_name($course, $section));
            $label = count($courses) > 1 ? $label . ' ' . $sectionname : $sectionname;
        }
        $options[] = [
            'value' => (int) $course->id . ':' . (int) $section->section,
            'label' => $label,
        ];
    }
}
if (!$options) {
    throw new moodle_exception('cannotview', 'local_wwlibrary');
}

$actionurl = new moodle_url('/local/wwlibrary/attach.php', ['id' => $id]);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $picked = required_param('place', PARAM_RAW_TRIMMED);
    if (!preg_match('/^(\d+):(\d+)$/', $picked, $match)) {
        throw new moodle_exception('refused', 'local_wwlibrary');
    }
    $moduleinfo = \local_wwlibrary\library::attach($id, (int) $match[1], (int) $match[2]);
    redirect(new moodle_url('/mod/wwlibrary/view.php', ['id' => $moduleinfo->coursemodule]));
}

\local_wwlibrary\library::setup_page(\local_wwlibrary\library::plain($resource->name), $actionurl);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwlibrary/form', [
    'action' => $actionurl->out(false),
    'sesskey' => sesskey(),
    'fields' => [[
        'label' => get_string('course', 'local_wwlibrary'),
        'name' => 'place',
        'select' => true,
        'options' => $options,
    ]],
    'save' => get_string('attach', 'local_wwlibrary'),
]);
echo $OUTPUT->footer();
