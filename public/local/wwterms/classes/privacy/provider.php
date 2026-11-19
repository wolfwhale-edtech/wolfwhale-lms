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

namespace local_wwterms\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for school terms.
 *
 * A term belongs to a school. The only personal field is who last saved it.
 * Deleting that person clears the name and leaves the term.
 *
 * @package    local_wwterms
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * The term table stores who last saved a term.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wwterms', [
            'categoryid' => 'privacy:metadata:term:categoryid',
            'name' => 'privacy:metadata:term:name',
            'timestart' => 'privacy:metadata:term:timestart',
            'timeend' => 'privacy:metadata:term:timeend',
            'usermodified' => 'privacy:metadata:term:usermodified',
        ], 'privacy:metadata:term');
        return $collection;
    }

    /**
     * Schools where this person last saved a term.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwterms} t ON t.categoryid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND t.usermodified = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSECAT,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People who last saved a term in this school.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSECAT) {
            return;
        }
        $sql = "SELECT t.usermodified AS userid
                  FROM {local_wwterms} t
                 WHERE t.categoryid = :categoryid
                   AND t.usermodified <> 0";
        $userlist->add_from_sql('userid', $sql, ['categoryid' => $context->instanceid]);
    }

    /**
     * Export the terms this person last saved.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSECAT) {
                continue;
            }
            $terms = $DB->get_records('local_wwterms', [
                'categoryid' => $context->instanceid,
                'usermodified' => $user->id,
            ], 'timestart ASC', 'id, name, timestart, timeend');
            if (!$terms) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_wwterms')],
                (object) ['terms' => array_values($terms)]
            );
        }
    }

    /**
     * Clear the editor on every term in this school.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSECAT) {
            return;
        }
        $DB->set_field('local_wwterms', 'usermodified', 0, ['categoryid' => $context->instanceid]);
    }

    /**
     * Clear the editor for these people in this school.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSECAT) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::clear_user((int) $userid, (int) $context->instanceid);
        }
    }

    /**
     * Clear the editor for this person. The term stays.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSECAT) {
                continue;
            }
            self::clear_user($userid, (int) $context->instanceid);
        }
    }

    /**
     * Clear one person on one school's terms.
     *
     * @param int $userid
     * @param int $categoryid
     */
    private static function clear_user(int $userid, int $categoryid): void {
        global $DB;

        $DB->set_field('local_wwterms', 'usermodified', 0, [
            'categoryid' => $categoryid,
            'usermodified' => $userid,
        ]);
    }
}
