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
 * Add or change one library resource.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

global $DB, $OUTPUT, $USER;

$id = optional_param('id', 0, PARAM_INT);
$resource = null;
if ($id) {
    $resource = \local_wwlibrary\library::get($id);
    if (!$resource || !\local_wwlibrary\library::can_manage_resource($resource)) {
        throw new moodle_exception('cannotview', 'local_wwlibrary');
    }
    $school = (int) $resource->categoryid;
    $folderid = (int) $resource->folderid;
    $kind = $resource->kind;
    $name = $resource->name;
    $link = $resource->externalurl;
} else {
    $school = optional_param('school', 0, PARAM_INT);
    $schools = \local_wwlibrary\library::manageable_school_ids((int) $USER->id);
    if (!$school || !in_array($school, $schools, true)) {
        $school = count($schools) === 1 ? (int) $schools[0] : 0;
    }
    if (!$school || !\local_wwlibrary\library::can_manage_school((int) $USER->id, $school)) {
        throw new moodle_exception('cannotview', 'local_wwlibrary');
    }
    $folderid = optional_param('folder', 0, PARAM_INT);
    $kind = 'map';
    $name = '';
    $link = '';
}

$actionurl = \local_wwlibrary\library::edit_action($id, $school, $folderid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'remove') {
        \local_wwlibrary\library::remove($id);
        redirect(new moodle_url('/local/wwlibrary/index.php', ['school' => $school]));
    }
    if ($action === 'earlier' || $action === 'later') {
        \local_wwlibrary\library::nudge($id, $action === 'earlier' ? -1 : 1);
        redirect($actionurl);
    }
    $path = null;
    $filename = null;
    if (!empty($_FILES['file']['name'])) {
        $upload = $_FILES['file'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || empty($upload['tmp_name'])
                || !is_uploaded_file($upload['tmp_name'])) {
            throw new moodle_exception('refused', 'local_wwlibrary');
        }
        $path = $upload['tmp_name'];
        $filename = (string) $upload['name'];
    }
    $saved = \local_wwlibrary\library::save((int) $USER->id, [
        'id' => $id,
        'categoryid' => $school,
        'folderid' => optional_param('folderid', 0, PARAM_INT),
        'name' => required_param('name', PARAM_TEXT),
        'kind' => required_param('kind', PARAM_ALPHA),
        'externalurl' => optional_param('externalurl', '', PARAM_RAW_TRIMMED),
    ], $path, $filename);
    redirect(new moodle_url('/local/wwlibrary/view.php', ['id' => $saved->id]));
}

$kindoptions = [];
foreach (['map' => 'maps', 'world' => 'worlds', 'model' => 'models'] as $value => $stringkey) {
    $kindoptions[] = [
        'value' => $value,
        'label' => get_string($stringkey, 'local_wwlibrary'),
        'checked' => $kind === $value,
    ];
}
$folders = $DB->get_records('local_wwlibrary_folder', ['categoryid' => $school], 'sortorder ASC, id ASC');
$folderoptions = [[
    'value' => 0,
    'label' => get_string('none', 'local_wwlibrary'),
    'selected' => $folderid === 0,
]];
foreach ($folders as $folder) {
    $folderoptions[] = [
        'value' => (int) $folder->id,
        'label' => \local_wwlibrary\library::plain($folder->name),
        'selected' => (int) $folder->id === $folderid,
    ];
}

$attach = '';
if ($id && \local_wwlibrary\library::attachable_courses($school)) {
    $attach = (new moodle_url('/local/wwlibrary/attach.php', ['id' => $id]))->out(false);
}

\local_wwlibrary\library::setup_page(
    $id ? \local_wwlibrary\library::plain($name) : get_string('add', 'local_wwlibrary'),
    $actionurl
);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwlibrary/form', [
    'action' => $actionurl->out(false),
    'sesskey' => sesskey(),
    'hidden' => !$id ? [['name' => 'school', 'value' => $school]] : [],
    'hasfile' => true,
    'haskinds' => true,
    'kinds' => $kindoptions,
    'fields' => [
        [
            'label' => get_string('name', 'local_wwlibrary'),
            'name' => 'name',
            'value' => $name,
            'text' => true,
            'required' => true,
        ],
        [
            'label' => get_string('link', 'local_wwlibrary'),
            'name' => 'externalurl',
            'value' => $link,
            'text' => true,
        ],
        [
            'label' => get_string('file', 'local_wwlibrary'),
            'name' => 'file',
            'isfile' => true,
            'accept' => '.mcworld,.glb,.gltf,.html,.htm,.zip',
        ],
        [
            'label' => get_string('folder', 'local_wwlibrary'),
            'name' => 'folderid',
            'select' => true,
            'options' => $folderoptions,
        ],
    ],
    'save' => get_string('save', 'local_wwlibrary'),
    'removeword' => $id ? get_string('remove', 'local_wwlibrary') : '',
    'earlier' => $id ? get_string('earlier', 'local_wwlibrary') : '',
    'later' => $id ? get_string('later', 'local_wwlibrary') : '',
    'attach' => $attach,
    'attachword' => get_string('attach', 'local_wwlibrary'),
]);
echo $OUTPUT->footer();
