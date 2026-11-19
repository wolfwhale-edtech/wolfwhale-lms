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
 * The signed-in person chooses their own avatar.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
if (isguestuser()) {
    redirect(new moodle_url('/my/'));
}

$url = new moodle_url('/local/wwpoints/avatar.php');
if (data_submitted()) {
    require_sesskey();
    $incoming = [];
    foreach (\local_wwpoints\avatar::PARTS as $part) {
        $incoming[$part] = optional_param($part, '', PARAM_ALPHANUMEXT);
    }
    \local_wwpoints\avatar::save((int) $USER->id, $incoming);
    redirect($url);
}

$data = \local_wwpoints\avatar::form((int) $USER->id);
$context = context_user::instance($USER->id);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$title = get_string('points', 'local_wwpoints');
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwpoints/avatar', [
    'action' => $url->out(false),
    'sesskey' => sesskey(),
    'svg' => $data['svg'],
    'groups' => $data['groups'],
    'hidden' => $data['hidden'],
    'speech' => $data['speech'],
    'read' => get_string('read', 'local_wwpoints'),
]);
echo $OUTPUT->footer();
