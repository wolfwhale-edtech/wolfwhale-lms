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

namespace local_wwreports\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for report-card comments.
 *
 * A comment is about one student and was written by one teacher. Deleting
 * either person removes the comment.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * The comment table.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wwreports_comment', [
            'userid' => 'privacy:metadata:comment:userid',
            'courseid' => 'privacy:metadata:comment:courseid',
            'termkey' => 'privacy:metadata:comment:termkey',
            'commenterid' => 'privacy:metadata:comment:commenterid',
            'commenttext' => 'privacy:metadata:comment:commenttext',
            'timecreated' => 'privacy:metadata:comment:timecreated',
        ], 'privacy:metadata:comment');
        return $collection;
    }

    /**
     * Courses where this person is the student or the author.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwreports_comment} c ON c.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND (c.userid = :student OR c.commenterid = :author)";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSE,
            'student' => $userid,
            'author' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Students and authors with a comment in this course.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $sql = "SELECT c.userid
                  FROM {local_wwreports_comment} c
                 WHERE c.courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
        $sql = "SELECT c.commenterid AS userid
                  FROM {local_wwreports_comment} c
                 WHERE c.courseid = :courseid
                   AND c.commenterid <> 0";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export comments about this person and comments this person wrote.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $records = $DB->get_records_select(
                'local_wwreports_comment',
                'courseid = :courseid AND (userid = :student OR commenterid = :author)',
                [
                    'courseid' => $context->instanceid,
                    'student' => $userid,
                    'author' => $userid,
                ],
                'timecreated ASC',
                'id, userid, termkey, commenterid, commenttext, timecreated'
            );
            if (!$records) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_wwreports')],
                (object) ['comments' => array_values($records)]
            );
        }
    }

    /**
     * Delete every comment in this course.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $DB->delete_records('local_wwreports_comment', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete comments for these people in this course.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user((int) $userid, (int) $context->instanceid);
        }
    }

    /**
     * Delete this person's comments in these courses.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            self::delete_user($userid, (int) $context->instanceid);
        }
    }

    /**
     * Delete comments about this person, and comments they wrote, in one course.
     *
     * @param int $userid
     * @param int $courseid
     */
    private static function delete_user(int $userid, int $courseid): void {
        global $DB;

        $DB->delete_records('local_wwreports_comment', [
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        $DB->delete_records('local_wwreports_comment', [
            'courseid' => $courseid,
            'commenterid' => $userid,
        ]);
    }
}
