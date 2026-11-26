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
 * Library tiles.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

global $OUTPUT;

$school = optional_param('school', 0, PARAM_INT);
$kind = optional_param('kind', '', PARAM_ALPHA);
$folder = optional_param('folder', 0, PARAM_INT);

$url = new moodle_url('/local/wwlibrary/index.php', ['school' => $school]);
if ($kind !== '') {
    $url->param('kind', $kind);
}
if ($folder) {
    $url->param('folder', $folder);
}
\local_wwlibrary\library::setup_page(get_string('library', 'local_wwlibrary'), $url);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'local_wwlibrary/index',
    \local_wwlibrary\library::index_data($school, $kind, $folder)
);
echo $OUTPUT->footer();
