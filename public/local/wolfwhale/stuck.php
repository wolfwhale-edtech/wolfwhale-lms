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
 * Students with overdue work or no recent course visit.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wolfwhale\teacher::require_teacher();

$rows = \local_wolfwhale\teacher::stuck_rows();
\local_wolfwhale\teacher::setup_page('stuck', new moodle_url('/local/wolfwhale/stuck.php'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wolfwhale/list', [
    'rows' => $rows,
    'empty' => $rows === [],
    'none' => get_string('none', 'local_wolfwhale'),
]);
echo $OUTPUT->footer();
