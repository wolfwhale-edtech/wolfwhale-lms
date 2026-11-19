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

namespace auth_wwpicture\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for picture codes.
 *
 * The class link and the school settings do not name a person.
 * Deleting a person removes that person's code and leaves everyone else.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * The code table stores personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('auth_wwpicture_code', [
            'userid' => 'privacy:metadata:code:userid',
            'codehash' => 'privacy:metadata:code:codehash',
            'failures' => 'privacy:metadata:code:failures',
            'lockeduntil' => 'privacy:metadata:code:lockeduntil',
            'lastattempt' => 'privacy:metadata:code:lastattempt',
        ], 'privacy:metadata:code');
        return $collection;
    }

    /**
     * The user context when this person has a picture code.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        if ($DB->record_exists('auth_wwpicture_code', ['userid' => $userid])) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * The person whose code is stored in this user context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        if ($DB->record_exists('auth_wwpicture_code', ['userid' => $context->instanceid])) {
            $userlist->add_user($context->instanceid);
        }
    }

    /**
     * Export the hash and the lock. The pictures themselves are not stored.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $row = $DB->get_record('auth_wwpicture_code', ['userid' => $user->id]);
        if (!$row) {
            return;
        }
        writer::with_context(\context_user::instance($user->id))->export_data(
            [get_string('pluginname', 'auth_wwpicture')],
            (object) [
                'codehash' => $row->codehash,
                'failures' => (int) $row->failures,
                'lockeduntil' => (int) $row->lockeduntil,
                'lastattempt' => (int) $row->lastattempt,
            ]
        );
    }

    /**
     * Delete every code in this user context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        $DB->delete_records('auth_wwpicture_code', ['userid' => $context->instanceid]);
    }

    /**
     * Delete this person's code.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }
        $DB->delete_records('auth_wwpicture_code', ['userid' => $contextlist->get_user()->id]);
    }

    /**
     * Delete codes for the listed people in this user context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_USER) {
            return;
        }
        if (!in_array($context->instanceid, $userlist->get_userids())) {
            return;
        }
        $DB->delete_records('auth_wwpicture_code', ['userid' => $context->instanceid]);
    }
}
