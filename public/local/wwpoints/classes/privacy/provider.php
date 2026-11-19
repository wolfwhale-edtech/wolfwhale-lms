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

namespace local_wwpoints\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for points, avatars, and goals.
 *
 * A ledger row belongs to the student. The person who awarded it is cleared
 * when that person is deleted, and the row stays. An avatar belongs to the
 * person who chose it. A goal stays when its author is deleted, and the
 * author field is cleared.
 *
 * @package    local_wwpoints
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
        $collection->add_database_table('local_wwpoints_ledger', [
            'userid' => 'privacy:metadata:ledger:userid',
            'courseid' => 'privacy:metadata:ledger:courseid',
            'amount' => 'privacy:metadata:ledger:amount',
            'reason' => 'privacy:metadata:ledger:reason',
            'eventname' => 'privacy:metadata:ledger:eventname',
            'sourcekey' => 'privacy:metadata:ledger:sourcekey',
            'awardedby' => 'privacy:metadata:ledger:awardedby',
            'timecreated' => 'privacy:metadata:ledger:timecreated',
        ], 'privacy:metadata:ledger');
        $collection->add_database_table('local_wwpoints_avatar', [
            'userid' => 'privacy:metadata:avatar:userid',
            'face' => 'privacy:metadata:avatar:face',
            'skin' => 'privacy:metadata:avatar:skin',
            'hair' => 'privacy:metadata:avatar:hair',
            'eyes' => 'privacy:metadata:avatar:eyes',
            'clothing' => 'privacy:metadata:avatar:clothing',
            'accessory' => 'privacy:metadata:avatar:accessory',
        ], 'privacy:metadata:avatar');
        $collection->add_database_table('local_wwpoints_goal', [
            'courseid' => 'privacy:metadata:goal:courseid',
            'categoryid' => 'privacy:metadata:goal:categoryid',
            'name' => 'privacy:metadata:goal:name',
            'target' => 'privacy:metadata:goal:target',
            'timeend' => 'privacy:metadata:goal:timeend',
            'createdby' => 'privacy:metadata:goal:createdby',
        ], 'privacy:metadata:goal');
        return $collection;
    }

    /**
     * Contexts where this person has points, an avatar, or authored a goal.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwpoints_ledger} l ON l.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND l.courseid <> 0
                   AND (l.userid = :userid OR l.awardedby = :awardedby)";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSE,
            'userid' => $userid,
            'awardedby' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwpoints_goal} g ON g.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND g.courseid <> 0
                   AND g.createdby = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSE,
            'userid' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwpoints_goal} g ON g.categoryid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND g.categoryid <> 0
                   AND g.createdby = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSECAT,
            'userid' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :level
                   AND ctx.instanceid = :userid
                   AND (
                        EXISTS (SELECT 1 FROM {local_wwpoints_avatar} a WHERE a.userid = :avataruser)
                     OR EXISTS (SELECT 1 FROM {local_wwpoints_ledger} l
                                 WHERE l.userid = :ledgeruser AND l.courseid = 0)
                   )";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_USER,
            'userid' => $userid,
            'avataruser' => $userid,
            'ledgeruser' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People with points data in this context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            $sql = "SELECT userid FROM {local_wwpoints_ledger} WHERE courseid = :courseid";
            $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
            $sql = "SELECT awardedby AS userid
                      FROM {local_wwpoints_ledger}
                     WHERE courseid = :courseid AND awardedby <> 0";
            $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
            $sql = "SELECT createdby AS userid
                      FROM {local_wwpoints_goal}
                     WHERE courseid = :courseid AND createdby <> 0";
            $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $sql = "SELECT createdby AS userid
                      FROM {local_wwpoints_goal}
                     WHERE categoryid = :categoryid AND createdby <> 0";
            $userlist->add_from_sql('userid', $sql, ['categoryid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel == CONTEXT_USER) {
            $sql = "SELECT userid FROM {local_wwpoints_avatar} WHERE userid = :userid";
            $userlist->add_from_sql('userid', $sql, ['userid' => $context->instanceid]);
            $sql = "SELECT userid FROM {local_wwpoints_ledger} WHERE userid = :userid AND courseid = 0";
            $userlist->add_from_sql('userid', $sql, ['userid' => $context->instanceid]);
        }
    }

    /**
     * Export this person's points, avatar, and goals they authored.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $path = [get_string('pluginname', 'local_wwpoints')];
        foreach ($contextlist->get_contexts() as $context) {
            $data = new \stdClass();
            if ($context->contextlevel == CONTEXT_COURSE) {
                $earned = $DB->get_records('local_wwpoints_ledger', [
                    'courseid' => $context->instanceid,
                    'userid' => $user->id,
                ], 'timecreated ASC');
                $awarded = $DB->get_records_select(
                    'local_wwpoints_ledger',
                    'courseid = :courseid AND awardedby = :awardedby AND userid <> :userid',
                    [
                        'courseid' => $context->instanceid,
                        'awardedby' => $user->id,
                        'userid' => $user->id,
                    ],
                    'timecreated ASC'
                );
                $goals = $DB->get_records('local_wwpoints_goal', [
                    'courseid' => $context->instanceid,
                    'createdby' => $user->id,
                ]);
                if (!$earned && !$awarded && !$goals) {
                    continue;
                }
                $data->points = array_values($earned);
                $data->awarded = array_values($awarded);
                $data->goals = array_values($goals);
            } else if ($context->contextlevel == CONTEXT_COURSECAT) {
                $goals = $DB->get_records('local_wwpoints_goal', [
                    'categoryid' => $context->instanceid,
                    'createdby' => $user->id,
                ]);
                if (!$goals) {
                    continue;
                }
                $data->goals = array_values($goals);
            } else if ($context->contextlevel == CONTEXT_USER && (int) $context->instanceid === (int) $user->id) {
                $avatar = $DB->get_record('local_wwpoints_avatar', ['userid' => $user->id]);
                $site = $DB->get_records('local_wwpoints_ledger', [
                    'userid' => $user->id,
                    'courseid' => 0,
                ], 'timecreated ASC');
                if (!$avatar && !$site) {
                    continue;
                }
                if ($avatar) {
                    $data->avatar = $avatar;
                }
                $data->points = array_values($site);
            } else {
                continue;
            }
            writer::with_context($context)->export_data($path, $data);
        }
    }

    /**
     * Delete every points row in this context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSE) {
            $DB->delete_records('local_wwpoints_ledger', ['courseid' => $context->instanceid]);
            $DB->delete_records('local_wwpoints_goal', ['courseid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $DB->delete_records('local_wwpoints_goal', ['categoryid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel == CONTEXT_USER) {
            $DB->delete_records('local_wwpoints_avatar', ['userid' => $context->instanceid]);
            $DB->delete_records('local_wwpoints_ledger', [
                'userid' => $context->instanceid,
                'courseid' => 0,
            ]);
        }
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
     * Remove one person's data in one context. Other people's rows stay.
     *
     * @param int $userid
     * @param \context $context
     */
    private static function delete_user_in_context(int $userid, \context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSE) {
            $DB->delete_records('local_wwpoints_ledger', [
                'courseid' => $context->instanceid,
                'userid' => $userid,
            ]);
            $DB->set_field('local_wwpoints_ledger', 'awardedby', 0, [
                'courseid' => $context->instanceid,
                'awardedby' => $userid,
            ]);
            $DB->set_field('local_wwpoints_goal', 'createdby', 0, [
                'courseid' => $context->instanceid,
                'createdby' => $userid,
            ]);
            return;
        }
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $DB->set_field('local_wwpoints_goal', 'createdby', 0, [
                'categoryid' => $context->instanceid,
                'createdby' => $userid,
            ]);
            return;
        }
        if ($context->contextlevel == CONTEXT_USER && (int) $context->instanceid === $userid) {
            $DB->delete_records('local_wwpoints_avatar', ['userid' => $userid]);
            $DB->delete_records('local_wwpoints_ledger', [
                'userid' => $userid,
                'courseid' => 0,
            ]);
        }
    }
}
