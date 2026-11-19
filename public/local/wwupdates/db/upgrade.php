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
 * Upgrade parent updates.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Drop the extra phone field. The number is the mobile field on the account.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_wwupdates_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026111901) {
        $field = $DB->get_record('user_info_field', ['shortname' => 'parentphone']);
        if ($field) {
            $DB->delete_records('user_info_data', ['fieldid' => $field->id]);
            $DB->delete_records('user_info_field', ['id' => $field->id]);
            if (!$DB->record_exists('user_info_field', ['categoryid' => $field->categoryid])) {
                $category = $DB->get_record('user_info_category', ['id' => $field->categoryid]);
                if ($category && $category->name === 'Parent') {
                    $DB->delete_records('user_info_category', ['id' => $field->categoryid]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026111901, 'local', 'wwupdates');
    }

    return true;
}
