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
 * Picture sign-in and email domains for each school.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
$url = new moodle_url('/auth/wwpicture/schools.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_title(get_string('schools', 'auth_wwpicture'));
$PAGE->set_heading(get_string('schools', 'auth_wwpicture'));

$schools = \auth_wwpicture\school::manageable((int) $USER->id);
if (!$schools) {
    throw new moodle_exception('schools', 'auth_wwpicture');
}

if (data_submitted()) {
    require_sesskey();
    $allowed = [];
    foreach ($schools as $school) {
        $allowed[(int) $school['id']] = true;
    }
    foreach (array_keys($allowed) as $id) {
        \auth_wwpicture\school::save(
            $id,
            (bool) optional_param('enabled' . $id, 0, PARAM_BOOL),
            optional_param('domains' . $id, '', PARAM_RAW_TRIMMED)
        );
    }
    redirect($url);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('auth_wwpicture/schools', [
    'url' => $url->out(false),
    'sesskey' => sesskey(),
    'schools' => $schools,
]);
echo $OUTPUT->footer();
