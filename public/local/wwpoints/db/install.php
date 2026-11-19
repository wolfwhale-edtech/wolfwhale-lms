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
 * Store the level table and the point amounts.
 *
 * The screens read these values. They are not copied into the page code.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defaults an administrator can change later.
 */
function xmldb_local_wwpoints_install() {
    set_config('levels', \local_wwpoints\levels::DEFAULT_TABLE, 'local_wwpoints');
    set_config('points_assign', 10, 'local_wwpoints');
    set_config('points_completion', 5, 'local_wwpoints');
    set_config('points_present', 5, 'local_wwpoints');
    set_config('points_badge', 15, 'local_wwpoints');
    set_config('unlocks', \local_wwpoints\avatar::DEFAULT_UNLOCKS, 'local_wwpoints');
}
