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

namespace local_wwtreaty\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for Treaty Education outcomes.
 *
 * An outcome belongs to a school. Deleting the person who saved it clears
 * their name and leaves the outcome. Evidence belongs to the student.
 * Deleting the student removes that evidence. Deleting the person who
 * recorded it clears their name and leaves the evidence.
 *
 * @package    local_wwtreaty
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
        $collection->add_database_table('local_wwtreaty', [
            'usermodified' => 'privacy:metadata:outcome:usermodified',
        ], 'privacy:metadata:outcome');
        $collection->add_database_table('local_wwtreaty_tag', [
            'usermodified' => 'privacy:metadata:tag:usermodified',
        ], 'privacy:metadata:tag');
        $collection->add_database_table('local_wwtreaty_evidence', [
            'outcomeid' => 'privacy:metadata:evidence:outcomeid',
            'userid' => 'privacy:metadata:evidence:userid',
            'courseid' => 'privacy:metadata:evidence:courseid',
            'cmid' => 'privacy:metadata:evidence:cmid',
            'usermodified' => 'privacy:metadata:evidence:usermodified',
            'timecreated' => 'privacy:metadata:evidence:timecreated',
        ], 'privacy:metadata:evidence');
        return $collection;
    }

    /**
     * Courses and schools where this person saved a row or has evidence.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwtreaty} o ON o.categoryid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND o.usermodified = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSECAT,
            'userid' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwtreaty_tag} t ON t.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND t.usermodified = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwtreaty_evidence} e ON e.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND (e.userid = :userid OR e.usermodified = :editor)";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSE,
            'userid' => $userid,
            'editor' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People connected to this context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $sql = "SELECT o.usermodified AS userid
                      FROM {local_wwtreaty} o
                     WHERE o.categoryid = :categoryid
                       AND o.usermodified <> 0";
            $userlist->add_from_sql('userid', $sql, ['categoryid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $sql = "SELECT t.usermodified AS userid
                  FROM {local_wwtreaty_tag} t
                 WHERE t.courseid = :courseid
                   AND t.usermodified <> 0";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
        $sql = "SELECT e.userid
                  FROM {local_wwtreaty_evidence} e
                 WHERE e.courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
        $sql = "SELECT e.usermodified AS userid
                  FROM {local_wwtreaty_evidence} e
                 WHERE e.courseid = :courseid
                   AND e.usermodified <> 0";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export this person's rows.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $path = [get_string('pluginname', 'local_wwtreaty')];
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSECAT) {
                $rows = $DB->get_records('local_wwtreaty', [
                    'categoryid' => $context->instanceid,
                    'usermodified' => $user->id,
                ], 'code ASC', 'id, code, usermodified, timemodified');
                if ($rows) {
                    writer::with_context($context)->export_data($path, (object) ['outcomes' => array_values($rows)]);
                }
                continue;
            }
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $tags = $DB->get_records('local_wwtreaty_tag', [
                'courseid' => $context->instanceid,
                'usermodified' => $user->id,
            ], 'id ASC', 'id, outcomeid, cmid, timemodified');
            $evidence = $DB->get_records_select(
                'local_wwtreaty_evidence',
                'courseid = :courseid AND (userid = :userid OR usermodified = :editor)',
                [
                    'courseid' => $context->instanceid,
                    'userid' => $user->id,
                    'editor' => $user->id,
                ],
                'timecreated ASC',
                'id, outcomeid, userid, cmid, usermodified, timecreated'
            );
            if (!$tags && !$evidence) {
                continue;
            }
            writer::with_context($context)->export_data($path, (object) [
                'tags' => array_values($tags ?: []),
                'evidence' => array_values($evidence ?: []),
            ]);
        }
    }

    /**
     * Delete every personal row in this context. Outcomes and tags stay, with the editor cleared.
     * Evidence in a course is removed because it is about the students in that course.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $DB->set_field('local_wwtreaty', 'usermodified', 0, ['categoryid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $DB->set_field('local_wwtreaty_tag', 'usermodified', 0, ['courseid' => $context->instanceid]);
        $DB->delete_records('local_wwtreaty_evidence', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete these people's data in this context.
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
     * Delete this person's data in the approved contexts.
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
     * Remove one person's data in one context.
     *
     * @param int $userid
     * @param \context $context
     */
    private static function delete_user_in_context(int $userid, \context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $DB->set_field('local_wwtreaty', 'usermodified', 0, [
                'categoryid' => $context->instanceid,
                'usermodified' => $userid,
            ]);
            return;
        }
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $DB->set_field('local_wwtreaty_tag', 'usermodified', 0, [
            'courseid' => $context->instanceid,
            'usermodified' => $userid,
        ]);
        $DB->delete_records('local_wwtreaty_evidence', [
            'courseid' => $context->instanceid,
            'userid' => $userid,
        ]);
        $DB->set_field('local_wwtreaty_evidence', 'usermodified', 0, [
            'courseid' => $context->instanceid,
            'usermodified' => $userid,
        ]);
    }
}
