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
 * Add or change one library folder.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

global $DB, $OUTPUT, $USER;

$id = optional_param('id', 0, PARAM_INT);
$folder = null;
if ($id) {
    $folder = $DB->get_record('local_wwlibrary_folder', ['id' => $id]);
    if (!$folder || !\local_wwlibrary\library::can_manage_school((int) $USER->id, (int) $folder->categoryid)) {
        throw new moodle_exception('cannotview', 'local_wwlibrary');
    }
    $school = (int) $folder->categoryid;
    $name = $folder->name;
} else {
    $school = optional_param('school', 0, PARAM_INT);
    $schools = \local_wwlibrary\library::manageable_school_ids((int) $USER->id);
    if (!$school || !in_array($school, $schools, true)) {
        $school = count($schools) === 1 ? (int) $schools[0] : 0;
    }
    if (!$school || !\local_wwlibrary\library::can_manage_school((int) $USER->id, $school)) {
        throw new moodle_exception('cannotview', 'local_wwlibrary');
    }
    $name = '';
}

$actionurl = new moodle_url('/local/wwlibrary/folder.php');
if ($id) {
    $actionurl->param('id', $id);
} else {
    $actionurl->param('school', $school);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'remove') {
        \local_wwlibrary\library::remove_folder($id);
        redirect(new moodle_url('/local/wwlibrary/index.php', ['school' => $school]));
    }
    if ($action === 'earlier' || $action === 'later') {
        \local_wwlibrary\library::nudge_folder($id, $action === 'earlier' ? -1 : 1);
        redirect($actionurl);
    }
    $saved = \local_wwlibrary\library::save_folder(
        (int) $USER->id,
        $school,
        required_param('name', PARAM_TEXT),
        $id
    );
    redirect(new moodle_url('/local/wwlibrary/index.php', [
        'school' => $school,
        'folder' => $saved->id,
    ]));
}

\local_wwlibrary\library::setup_page(
    $id ? \local_wwlibrary\library::plain($name) : get_string('folder', 'local_wwlibrary'),
    $actionurl
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwlibrary/form', [
    'action' => $actionurl->out(false),
    'sesskey' => sesskey(),
    'fields' => [[
        'label' => get_string('name', 'local_wwlibrary'),
        'name' => 'name',
        'value' => $name,
        'text' => true,
        'required' => true,
    ]],
    'save' => get_string('save', 'local_wwlibrary'),
    'removeword' => $id ? get_string('remove', 'local_wwlibrary') : '',
    'earlier' => $id ? get_string('earlier', 'local_wwlibrary') : '',
    'later' => $id ? get_string('later', 'local_wwlibrary') : '',
]);
echo $OUTPUT->footer();
