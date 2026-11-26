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
 * Open one library resource.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

global $OUTPUT;

$id = required_param('id', PARAM_INT);
$resource = \local_wwlibrary\library::get($id);
if (!$resource || !\local_wwlibrary\library::can_view_resource($resource)) {
    throw new moodle_exception('cannotview', 'local_wwlibrary');
}

$name = \local_wwlibrary\library::plain($resource->name);
\local_wwlibrary\library::setup_page($name, new moodle_url('/local/wwlibrary/view.php', ['id' => $id]));

$data = \local_wwlibrary\library::viewer($resource);
$data['showtitle'] = true;

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwlibrary/viewer', $data);
echo $OUTPUT->footer();
