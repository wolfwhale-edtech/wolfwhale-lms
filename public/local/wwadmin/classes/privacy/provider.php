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

namespace local_wwadmin\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
/**
 * Privacy provider for export records and seat counts.
 *
 * The archive is a copy held for the person who asked for it. Deleting that
 * person removes the record and the file. Deleting the person who entered a
 * seat count clears that name and leaves the number.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Tables that store personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wwadmin_export', [
            'userid' => 'privacy:metadata:export:userid',
            'categoryid' => 'privacy:metadata:export:categoryid',
            'status' => 'privacy:metadata:export:status',
            'timecreated' => 'privacy:metadata:export:timecreated',
        ], 'privacy:metadata:export');
        $collection->add_database_table('local_wwadmin_seat', [
            'categoryid' => 'privacy:metadata:seat:categoryid',
            'seats' => 'privacy:metadata:seat:seats',
            'usermodified' => 'privacy:metadata:seat:usermodified',
        ], 'privacy:metadata:seat');
        return $collection;
    }

    /**
     * Contexts where this person asked for an export or entered a seat count.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :level
                   AND ctx.instanceid = :userid
                   AND EXISTS (
                       SELECT 1
                         FROM {local_wwadmin_export} e
                        WHERE e.userid = :exportuser
                   )";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_USER,
            'userid' => $userid,
            'exportuser' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwadmin_seat} s ON s.categoryid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND s.usermodified = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSECAT,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People with export or seat data in this context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_USER) {
            $sql = "SELECT e.userid
                      FROM {local_wwadmin_export} e
                     WHERE e.userid = :userid";
            $userlist->add_from_sql('userid', $sql, ['userid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSECAT) {
            return;
        }
        $sql = "SELECT s.usermodified AS userid
                  FROM {local_wwadmin_seat} s
                 WHERE s.categoryid = :categoryid
                   AND s.usermodified <> 0";
        $userlist->add_from_sql('userid', $sql, ['categoryid' => $context->instanceid]);
    }

    /**
     * Export this person's export records and seat edits.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && (int) $context->instanceid === (int) $user->id) {
                $exports = $DB->get_records('local_wwadmin_export', ['userid' => $user->id], 'timecreated ASC',
                    'id, categoryid, status, timecreated, timecompleted');
                if ($exports) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'local_wwadmin')],
                        (object) ['exports' => array_values($exports)]
                    );
                }
            }
            if ($context->contextlevel != CONTEXT_COURSECAT) {
                continue;
            }
            $seat = $DB->get_record('local_wwadmin_seat', [
                'categoryid' => $context->instanceid,
                'usermodified' => $user->id,
            ], 'id, categoryid, seats, timemodified');
            if ($seat) {
                writer::with_context($context)->export_data(
                    [get_string('licences', 'local_wwadmin')],
                    $seat
                );
            }
        }
    }

    /**
     * Delete export files and clear seat editors in this context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel == CONTEXT_USER) {
            self::delete_exports_for_user((int) $context->instanceid);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSECAT) {
            return;
        }
        $DB->set_field('local_wwadmin_seat', 'usermodified', 0, ['categoryid' => $context->instanceid]);
    }

    /**
     * Delete or clear data for these people.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_in_context((int) $userid, $context);
        }
    }

    /**
     * Delete or clear data for this person.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_user_in_context($userid, $context);
        }
    }

    /**
     * Delete one person's data in one context.
     *
     * @param int $userid
     * @param \context $context
     */
    private static function delete_user_in_context(int $userid, \context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_USER && (int) $context->instanceid === $userid) {
            self::delete_exports_for_user($userid);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSECAT) {
            return;
        }
        $DB->set_field('local_wwadmin_seat', 'usermodified', 0, [
            'categoryid' => $context->instanceid,
            'usermodified' => $userid,
        ]);
    }

    /**
     * Remove export records and their files for one person.
     *
     * @param int $userid
     */
    private static function delete_exports_for_user(int $userid): void {
        global $DB;

        $exports = $DB->get_records('local_wwadmin_export', ['userid' => $userid]);
        $fs = get_file_storage();
        $context = \context_user::instance($userid, IGNORE_MISSING);
        foreach ($exports as $export) {
            if ($context) {
                $fs->delete_area_files($context->id, 'local_wwadmin', 'export', $export->id);
            }
        }
        $DB->delete_records('local_wwadmin_export', ['userid' => $userid]);
    }
}
