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

namespace local_wwattendance\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for attendance.
 *
 * A student's mark belongs to that student. The person who took attendance
 * is stored on the day. Deleting the taker clears that field and leaves the marks.
 *
 * @package    local_wwattendance
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
        $collection->add_database_table('local_wwattendance_session', [
            'courseid' => 'privacy:metadata:session:courseid',
            'takenby' => 'privacy:metadata:session:takenby',
            'sessdate' => 'privacy:metadata:session:sessdate',
        ], 'privacy:metadata:session');
        $collection->add_database_table('local_wwattendance_log', [
            'userid' => 'privacy:metadata:log:userid',
            'status' => 'privacy:metadata:log:status',
        ], 'privacy:metadata:log');
        return $collection;
    }

    /**
     * Course contexts where this person has a mark or took attendance.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwattendance_session} s ON s.courseid = ctx.instanceid
                  JOIN {local_wwattendance_log} l ON l.sessionid = s.id
                 WHERE ctx.contextlevel = :level
                   AND l.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwattendance_session} s ON s.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND s.takenby = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People with attendance data in this course.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $sql = "SELECT l.userid
                  FROM {local_wwattendance_log} l
                  JOIN {local_wwattendance_session} s ON s.id = l.sessionid
                 WHERE s.courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
        $sql = "SELECT s.takenby AS userid
                  FROM {local_wwattendance_session} s
                 WHERE s.courseid = :courseid
                   AND s.takenby <> 0";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export this person's marks and the days they took.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $marks = $DB->get_records_sql(
                "SELECT l.id, l.status, s.sessdate
                   FROM {local_wwattendance_log} l
                   JOIN {local_wwattendance_session} s ON s.id = l.sessionid
                  WHERE s.courseid = :courseid
                    AND l.userid = :userid",
                ['courseid' => $context->instanceid, 'userid' => $user->id]
            );
            $taken = $DB->get_records('local_wwattendance_session', [
                'courseid' => $context->instanceid,
                'takenby' => $user->id,
            ], 'sessdate ASC', 'id, sessdate');
            if (!$marks && !$taken) {
                continue;
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_wwattendance')],
                (object) [
                    'marks' => array_values($marks),
                    'taken' => array_values($taken),
                ]
            );
        }
    }

    /**
     * Delete every attendance row in this course.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $sessions = $DB->get_fieldset_select(
            'local_wwattendance_session',
            'id',
            'courseid = ?',
            [$context->instanceid]
        );
        if ($sessions) {
            [$insql, $params] = $DB->get_in_or_equal($sessions);
            $DB->delete_records_select('local_wwattendance_log', "sessionid {$insql}", $params);
        }
        $DB->delete_records('local_wwattendance_session', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete marks for these people and clear the days they took.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_in_course((int) $userid, (int) $context->instanceid);
        }
    }

    /**
     * Delete this person's marks and clear the days they took.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            self::delete_user_in_course($userid, (int) $context->instanceid);
        }
    }

    /**
     * Remove one person's data in one course. Other people's marks stay.
     *
     * @param int $userid
     * @param int $courseid
     */
    private static function delete_user_in_course(int $userid, int $courseid): void {
        global $DB;

        $sessions = $DB->get_fieldset_select(
            'local_wwattendance_session',
            'id',
            'courseid = ?',
            [$courseid]
        );
        if ($sessions) {
            [$insql, $params] = $DB->get_in_or_equal($sessions, SQL_PARAMS_QM);
            $params[] = $userid;
            $DB->delete_records_select('local_wwattendance_log', "sessionid {$insql} AND userid = ?", $params);
        }
        $DB->set_field('local_wwattendance_session', 'takenby', 0, [
            'courseid' => $courseid,
            'takenby' => $userid,
        ]);
    }
}
