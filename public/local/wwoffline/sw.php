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
 * Service worker for student pages.
 *
 * The script has no session and no personal data. The scope header lets it
 * cover the whole site from this address.
 *
 * @package    local_wwoffline
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

$scope = \local_wwoffline\hook_callbacks::scope_path();
$base = $CFG->wwwroot . '/local/wwoffline/js/';

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: ' . $scope);
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');

echo 'self.WW_SCRIPT_BASE=' . json_encode($base, JSON_UNESCAPED_SLASHES) . ";\n";
readfile(__DIR__ . '/js/sw.js');
