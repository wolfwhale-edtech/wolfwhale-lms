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
 * Download the school and term summary.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
\local_wwadmin\scope::require_admin();

if (optional_param('download', 0, PARAM_BOOL)) {
    require_sesskey();
    \local_wwadmin\report::download();
}

$url = new moodle_url('/local/wwadmin/report.php');
\local_wwadmin\ui::render('reports', $url, [
    'actions' => [[
        'word' => get_string('download', 'local_wwadmin'),
        'url' => (new moodle_url($url, ['download' => 1, 'sesskey' => sesskey()]))->out(false),
    ]],
]);
