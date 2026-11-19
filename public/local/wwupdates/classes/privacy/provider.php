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

namespace local_wwupdates\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for parent updates.
 *
 * The phone number is a profile field for that parent. The sent table records
 * which parent was told about which child. A school's day and hour are not
 * personal data.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {

    /**
     * Data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wwupdates_sent', [
            'parentid' => 'privacy:metadata:sent:parentid',
            'childid' => 'privacy:metadata:sent:childid',
            'kind' => 'privacy:metadata:sent:kind',
            'sourcekey' => 'privacy:metadata:sent:sourcekey',
            'timecreated' => 'privacy:metadata:sent:timecreated',
        ], 'privacy:metadata:sent');
        foreach (\local_wwupdates\preferences::CHANNELS as $key) {
            $collection->add_user_preference(
                \local_wwupdates\preferences::name($key),
                'privacy:metadata:preference:' . $key
            );
        }
        foreach (\local_wwupdates\preferences::TYPES as $key) {
            $collection->add_user_preference(
                \local_wwupdates\preferences::name($key),
                'privacy:metadata:preference:' . $key
            );
        }
        $collection->add_user_preference('parentphone', 'privacy:metadata:phone');
        return $collection;
    }

    /**
     * Export the switches and the phone number.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid) {
        $keys = array_merge(\local_wwupdates\preferences::CHANNELS, \local_wwupdates\preferences::TYPES);
        foreach ($keys as $key) {
            $value = get_user_preferences(\local_wwupdates\preferences::name($key), null, $userid);
            if ($value === null) {
                continue;
            }
            writer::export_user_preference(
                'local_wwupdates',
                \local_wwupdates\preferences::name($key),
                $value,
                get_string('privacy:metadata:preference:' . $key, 'local_wwupdates')
            );
        }
        $phone = \local_wwupdates\preferences::phone($userid);
        if ($phone !== '') {
            writer::export_user_preference(
                'local_wwupdates',
                'parentphone',
                $phone,
                get_string('privacy:metadata:phone', 'local_wwupdates')
            );
        }
    }

    /**
     * The user context when this person is a parent or a child in the sent table.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwupdates_sent} s
                    ON s.parentid = ctx.instanceid OR s.childid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND ctx.instanceid = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_USER,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People named in the sent table for this user context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $sql = "SELECT parentid AS userid
                  FROM {local_wwupdates_sent}
                 WHERE parentid = :parent
                 UNION
                SELECT childid AS userid
                  FROM {local_wwupdates_sent}
                 WHERE childid = :child";
        $userlist->add_from_sql('userid', $sql, [
            'parent' => $context->instanceid,
            'child' => $context->instanceid,
        ]);
    }

    /**
     * Export rows about this person.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel != CONTEXT_USER || (int) $context->instanceid !== $userid) {
                continue;
            }
            $rows = $DB->get_records_select(
                'local_wwupdates_sent',
                'parentid = ? OR childid = ?',
                [$userid, $userid],
                'timecreated ASC',
                'id, parentid, childid, kind, sourcekey, timecreated'
            );
            if ($rows) {
                writer::with_context($context)->export_data(['Updates'], (object) ['updates' => array_values($rows)]);
            }
        }
    }

    /**
     * Delete rows about this person.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist as $context) {
            if ($context->contextlevel == CONTEXT_USER && (int) $context->instanceid === $userid) {
                $DB->delete_records_select('local_wwupdates_sent', 'parentid = ? OR childid = ?', [$userid, $userid]);
            }
        }
    }

    /**
     * Delete rows for every person in this user context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $DB->delete_records_select(
            'local_wwupdates_sent',
            'parentid = ? OR childid = ?',
            [$context->instanceid, $context->instanceid]
        );
    }

    /**
     * Delete rows for the listed people.
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
                $DB->delete_records_select('local_wwupdates_sent', 'parentid = ? OR childid = ?', [$userid, $userid]);
            }
        }
    }
}
