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
 * Blank CSV or JSON import file. The keys are the format. No outcome wording is included.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

if (!\local_wwtreaty\school::managed_root_ids()) {
    redirect(new moodle_url('/my/'));
}

$format = required_param('format', PARAM_ALPHA);
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outcomes.csv"');
    echo \local_wwtreaty\outcome::CSV_HEADER;
    exit;
}
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="outcomes.json"');
    echo \local_wwtreaty\outcome::JSON_TEMPLATE;
    exit;
}

throw new moodle_exception('invalidimport', 'local_wwtreaty', '', 'File');
