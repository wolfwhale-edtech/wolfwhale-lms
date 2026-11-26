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

namespace local_wwnation\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Nation membership.
 *
 * Deleting a person removes their membership rows. The Nation stays.
 *
 * @package    local_wwnation
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * The membership table stores which Nation a person belongs to.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wwnation_member', [
            'nationid' => 'privacy:metadata:member:nationid',
            'userid' => 'privacy:metadata:member:userid',
            'timecreated' => 'privacy:metadata:member:timecreated',
        ], 'privacy:metadata:member');
        return $collection;
    }

    /**
     * The user context when this person has a membership row.
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
                         FROM {local_wwnation_member} m
                        WHERE m.userid = :member
                   )";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_USER,
            'userid' => $userid,
            'member' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People with a membership row in this user context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $sql = "SELECT m.userid
                  FROM {local_wwnation_member} m
                 WHERE m.userid = :userid";
        $userlist->add_from_sql('userid', $sql, ['userid' => $context->instanceid]);
    }

    /**
     * Export the Nation ids stored for this person.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        $rows = $DB->get_records('local_wwnation_member', ['userid' => $userid], 'id ASC', 'id, nationid, timecreated');
        if (!$rows) {
            return;
        }
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_USER || (int) $context->instanceid !== $userid) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_wwnation')],
                (object) ['nations' => array_values($rows)]
            );
        }
    }

    /**
     * Remove every membership row in this user context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $DB->delete_records('local_wwnation_member', ['userid' => $context->instanceid]);
    }

    /**
     * Remove membership rows for these people.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            if ((int) $userid === (int) $context->instanceid) {
                $DB->delete_records('local_wwnation_member', ['userid' => $userid]);
            }
        }
    }

    /**
     * Remove this person's membership rows.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_USER && (int) $context->instanceid === $userid) {
                $DB->delete_records('local_wwnation_member', ['userid' => $userid]);
            }
        }
    }
}
