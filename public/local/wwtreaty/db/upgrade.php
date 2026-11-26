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
 * Upgrade Treaty Education outcomes.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A manual mark can be recorded again in a later term.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_wwtreaty_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026112601) {
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_wwtreaty_evidence');
        $field = new xmldb_field('termid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $oldindex = new xmldb_index('evidenceslot', XMLDB_INDEX_UNIQUE, ['outcomeid', 'userid', 'courseid', 'cmid']);
        if ($dbman->index_exists($table, $oldindex)) {
            $dbman->drop_index($table, $oldindex);
        }
        $newindex = new xmldb_index('evidenceslot', XMLDB_INDEX_UNIQUE, ['outcomeid', 'userid', 'courseid', 'cmid', 'termid']);
        if (!$dbman->index_exists($table, $newindex)) {
            $dbman->add_index($table, $newindex);
        }
        upgrade_plugin_savepoint(true, 2026112601, 'local', 'wwtreaty');
    }

    return true;
}
