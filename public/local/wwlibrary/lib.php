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
 * File serving for the library.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Serve a library file to someone who may open that resource.
 *
 * @param \stdClass $course
 * @param \stdClass $cm
 * @param \context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_wwlibrary_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ((int) $context->contextlevel !== CONTEXT_SYSTEM) {
        return false;
    }
    require_login();
    return \local_wwlibrary\storage::serve($filearea, $args, (bool) $forcedownload, $options);
}
