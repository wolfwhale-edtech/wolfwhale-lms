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
 * Parent home.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wolfwhale\parents::require_parent();

$child = \local_wolfwhale\parents::resolve(optional_param('child', 0, PARAM_INT));
$data = \local_wolfwhale\parents::export((int) $child->id);
\local_wolfwhale\parents::setup_page('home', new moodle_url('/local/wolfwhale/parent.php', ['child' => $child->id]));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wolfwhale/parent', $data);
echo $OUTPUT->footer();
