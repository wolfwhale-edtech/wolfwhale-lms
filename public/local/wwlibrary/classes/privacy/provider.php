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

namespace local_wwlibrary\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for the library.
 *
 * A resource and a folder stay when the person who added them is deleted.
 * The name of that person is cleared. The files stay with the school.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Tables and files that store personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_wwlibrary_resource', [
            'name' => 'privacy:metadata:resource:name',
            'kind' => 'privacy:metadata:resource:kind',
            'userid' => 'privacy:metadata:resource:userid',
        ], 'privacy:metadata:resource');
        $collection->add_database_table('local_wwlibrary_folder', [
            'name' => 'privacy:metadata:folder:name',
            'userid' => 'privacy:metadata:folder:userid',
        ], 'privacy:metadata:folder');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:files');
        return $collection;
    }

    /**
     * Schools where this person added a resource or a folder, and the system
     * context when they stored a file.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwlibrary_resource} r ON r.categoryid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND r.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSECAT,
            'userid' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_wwlibrary_folder} f ON f.categoryid = ctx.instanceid
                 WHERE ctx.contextlevel = :level
                   AND f.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_COURSECAT,
            'userid' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                 WHERE ctx.contextlevel = :level
                   AND EXISTS (
                       SELECT 1
                         FROM {files} f
                        WHERE f.component = :component
                          AND f.userid = :userid
                          AND f.filename <> '.'
                   )";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_SYSTEM,
            'component' => 'local_wwlibrary',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * People who added library data in this context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $sql = "SELECT userid
                      FROM {local_wwlibrary_resource}
                     WHERE categoryid = :categoryid
                       AND userid <> 0";
            $userlist->add_from_sql('userid', $sql, ['categoryid' => $context->instanceid]);
            $sql = "SELECT userid
                      FROM {local_wwlibrary_folder}
                     WHERE categoryid = :categoryid
                       AND userid <> 0";
            $userlist->add_from_sql('userid', $sql, ['categoryid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $sql = "SELECT userid
                      FROM {files}
                     WHERE component = :component
                       AND userid <> 0
                       AND filename <> '.'";
            $userlist->add_from_sql('userid', $sql, ['component' => 'local_wwlibrary']);
        }
    }

    /**
     * Export resources and folders this person added.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $user = $contextlist->get_user();
        $fs = get_file_storage();
        $context = \local_wwlibrary\storage::context();
        foreach ($contextlist->get_contexts() as $ctx) {
            if ($ctx->contextlevel != CONTEXT_COURSECAT) {
                continue;
            }
            $resources = $DB->get_records('local_wwlibrary_resource', [
                'categoryid' => $ctx->instanceid,
                'userid' => $user->id,
            ], 'id ASC');
            foreach ($resources as $resource) {
                $path = [get_string('pluginname', 'local_wwlibrary'), $resource->name];
                writer::with_context($ctx)->export_data($path, (object) [
                    'name' => $resource->name,
                    'kind' => $resource->kind,
                ]);
                $files = $fs->get_area_files($context->id, 'local_wwlibrary', 'content', $resource->id, 'id', false);
                foreach ($files as $file) {
                    writer::with_context($ctx)->export_file($path, $file);
                }
            }
            $folders = $DB->get_records('local_wwlibrary_folder', [
                'categoryid' => $ctx->instanceid,
                'userid' => $user->id,
            ], 'id ASC', 'id, name');
            if ($folders) {
                writer::with_context($ctx)->export_data(
                    [get_string('pluginname', 'local_wwlibrary')],
                    (object) ['folders' => array_values($folders)]
                );
            }
        }
    }

    /**
     * Clear who added library data in this school, or who stored a file.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $DB->set_field('local_wwlibrary_resource', 'userid', 0, ['categoryid' => $context->instanceid]);
            $DB->set_field('local_wwlibrary_folder', 'userid', 0, ['categoryid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->set_field('files', 'userid', 0, ['component' => 'local_wwlibrary']);
        }
    }

    /**
     * Clear these people in this context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            self::clear_user((int) $userid, $context);
        }
    }

    /**
     * Clear this person. The resources, folders, and files stay.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::clear_user($userid, $context);
        }
    }

    /**
     * Clear one person in one context.
     *
     * @param int $userid
     * @param \context $context
     */
    private static function clear_user(int $userid, \context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSECAT) {
            $DB->set_field('local_wwlibrary_resource', 'userid', 0, [
                'categoryid' => $context->instanceid,
                'userid' => $userid,
            ]);
            $DB->set_field('local_wwlibrary_folder', 'userid', 0, [
                'categoryid' => $context->instanceid,
                'userid' => $userid,
            ]);
            return;
        }
        if ($context->contextlevel == CONTEXT_SYSTEM) {
            $DB->set_field('files', 'userid', 0, [
                'component' => 'local_wwlibrary',
                'userid' => $userid,
            ]);
        }
    }
}
