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

namespace local_wwadmin;

/**
 * A downloadable archive of one school's own data.
 *
 * The archive is built by a background task. Every query is limited to that
 * school's categories. A person who cannot export that school cannot queue
 * the task, and the task checks again before it writes a file.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exporter {
    /** @var string */
    public const QUEUED = 'queued';

    /** @var string */
    public const READY = 'ready';

    /** @var string */
    public const FAILED = 'failed';

    /**
     * Ask for an export of one school and queue the background task.
     *
     * @param int $categoryid
     * @return int Export record id.
     */
    public static function request(int $categoryid): int {
        global $DB, $USER;

        scope::require($categoryid, 'local/wwadmin:export');
        $id = $DB->insert_record('local_wwadmin_export', (object) [
            'userid' => (int) $USER->id,
            'categoryid' => $categoryid,
            'status' => self::QUEUED,
            'detail' => '',
            'timecreated' => time(),
            'timecompleted' => 0,
        ]);
        $task = new \local_wwadmin\task\export_task();
        $task->set_custom_data(['exportid' => (int) $id]);
        $task->set_userid((int) $USER->id);
        \core\task\manager::queue_adhoc_task($task);
        return (int) $id;
    }

    /**
     * Exports this person asked for, in schools they can still export.
     *
     * @return \stdClass[]
     */
    public static function list_for_current(): array {
        global $DB, $USER;

        $visible = self::exportable_ids();
        if (!$visible) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED);
        $params['userid'] = (int) $USER->id;
        return $DB->get_records_select(
            'local_wwadmin_export',
            "userid = :userid AND categoryid {$insql}",
            $params,
            'timecreated DESC'
        );
    }

    /**
     * Schools this person may export.
     *
     * @return int[]
     */
    public static function exportable_ids(): array {
        global $USER;

        $ids = [];
        foreach (scope::managed_root_ids((int) $USER->id) as $root) {
            foreach (scope::tree_ids($root) as $id) {
                $context = \context_coursecat::instance($id);
                if (has_capability('local/wwadmin:export', $context)) {
                    $ids[$id] = $id;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * Build the archive for one queued export.
     *
     * The check uses the person stored on the export, not whoever happens to
     * be running the task. A failed check writes no file.
     *
     * @param int $exportid
     */
    public static function run(int $exportid): void {
        global $DB;

        $log = $DB->get_record('local_wwadmin_export', ['id' => $exportid], '*', MUST_EXIST);
        $context = \context_coursecat::instance($log->categoryid, IGNORE_MISSING);
        if (!$context || !has_capability('local/wwadmin:export', $context, (int) $log->userid)) {
            self::mark($log, self::FAILED, 'nopermission');
            return;
        }
        try {
            $sets = self::gather((int) $log->categoryid);
            self::store($log, $sets);
            self::mark($log, self::READY, '');
        } catch (\Throwable $error) {
            self::mark($log, self::FAILED, 'failed');
            debugging($error->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The stored archive, when this person may download it.
     *
     * @param int $exportid
     * @return \stored_file
     */
    public static function require_file(int $exportid): \stored_file {
        global $DB, $USER;

        $log = $DB->get_record('local_wwadmin_export', ['id' => $exportid], '*', MUST_EXIST);
        if ((int) $log->userid !== (int) $USER->id || $log->status !== self::READY) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        scope::require((int) $log->categoryid, 'local/wwadmin:export');
        $file = self::stored_file($log);
        if (!$file) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        return $file;
    }

    /**
     * Rows for one school. Nothing from any other category is included.
     *
     * @param int $categoryid
     * @return array<string, array<int, array<string, string|int|float|null>>>
     */
    public static function gather(int $categoryid): array {
        $tree = scope::tree_ids($categoryid);
        $users = scope::users($tree);
        $userids = array_map('intval', array_keys($users));
        $courses = scope::courses($tree);
        $courseids = array_map('intval', array_keys($courses));
        $sets = [
            'users' => self::user_rows($users),
            'enrolments' => self::enrolment_rows($courseids, $userids),
            'grades' => self::grade_rows($courseids, $userids),
            'attendance' => self::attendance_rows($courseids),
            'submissions' => self::submission_rows($courseids, $userids),
        ];
        if (class_exists(\local_wwpoints\points::class)) {
            $sets['points'] = \local_wwpoints\points::export_rows($courseids, $userids);
            $sets['avatars'] = \local_wwpoints\avatar::export_rows($userids);
            $sets['goals'] = \local_wwpoints\goal::export_rows($courseids, $tree);
        }
        return $sets;
    }

    /**
     * User rows. Passwords are not included.
     *
     * @param \stdClass[] $users
     * @return array[]
     */
    private static function user_rows(array $users): array {
        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'id' => (int) $user->id,
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'idnumber' => $user->idnumber,
                'suspended' => (int) $user->suspended,
                'auth' => $user->auth,
            ];
        }
        return $rows;
    }

    /**
     * Enrolments in this school's courses.
     *
     * @param int[] $courseids
     * @param int[] $userids
     * @return array[]
     */
    private static function enrolment_rows(array $courseids, array $userids): array {
        global $DB;

        if (!$courseids || !$userids) {
            return [];
        }
        [$csql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params = array_merge($cparams, $uparams, ['level' => CONTEXT_COURSE]);
        $sql = "SELECT ue.id, ue.userid, e.courseid, c.fullname AS coursename, e.enrol,
                       ue.status, ue.timestart, ue.timeend, r.shortname AS role
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
             LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :level
             LEFT JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
             LEFT JOIN {role} r ON r.id = ra.roleid
                 WHERE e.courseid {$csql}
                   AND ue.userid {$usql}
              ORDER BY ue.userid ASC, e.courseid ASC, ue.id ASC";
        $rows = [];
        $seen = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $key = $record->id;
            if (!isset($seen[$key])) {
                $seen[$key] = count($rows);
                $rows[] = [
                    'userid' => (int) $record->userid,
                    'courseid' => (int) $record->courseid,
                    'coursename' => $record->coursename,
                    'enrol' => $record->enrol,
                    'status' => (int) $record->status,
                    'timestart' => (int) $record->timestart,
                    'timeend' => (int) $record->timeend,
                    'roles' => $record->role ?? '',
                ];
                continue;
            }
            if ($record->role) {
                $index = $seen[$key];
                $roles = array_filter(explode(',', $rows[$index]['roles']));
                if (!in_array($record->role, $roles, true)) {
                    $roles[] = $record->role;
                    $rows[$index]['roles'] = implode(',', $roles);
                }
            }
        }
        $records->close();
        return $rows;
    }

    /**
     * Saved grades in this school's courses.
     *
     * @param int[] $courseids
     * @param int[] $userids
     * @return array[]
     */
    private static function grade_rows(array $courseids, array $userids): array {
        global $DB;

        if (!$courseids || !$userids) {
            return [];
        }
        [$csql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params = array_merge($cparams, $uparams);
        $sql = "SELECT gg.id, gg.userid, gi.courseid, gi.itemname, gi.itemtype, gi.itemmodule,
                       gg.finalgrade, gg.rawgrademax, gg.timemodified
                  FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                 WHERE gi.courseid {$csql}
                   AND gg.userid {$usql}
                   AND gg.finalgrade IS NOT NULL
              ORDER BY gg.userid ASC, gi.courseid ASC, gi.id ASC";
        $rows = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $rows[] = [
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'itemname' => (string) $record->itemname,
                'itemtype' => (string) $record->itemtype,
                'itemmodule' => (string) $record->itemmodule,
                'finalgrade' => $record->finalgrade,
                'rawgrademax' => $record->rawgrademax,
                'timemodified' => (int) $record->timemodified,
            ];
        }
        $records->close();
        return $rows;
    }

    /**
     * Attendance marks in this school's courses.
     *
     * @param int[] $courseids
     * @return array[]
     */
    private static function attendance_rows(array $courseids): array {
        global $DB;

        if (!$courseids || !$DB->get_manager()->table_exists('local_wwattendance_log')) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $sql = "SELECT l.id, l.userid, s.courseid, s.sessdate, s.takenby, l.status
                  FROM {local_wwattendance_log} l
                  JOIN {local_wwattendance_session} s ON s.id = l.sessionid
                 WHERE s.courseid {$insql}
              ORDER BY s.courseid ASC, s.sessdate ASC, l.userid ASC";
        $rows = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $rows[] = [
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'sessdate' => (int) $record->sessdate,
                'takenby' => (int) $record->takenby,
                'status' => $record->status,
            ];
        }
        $records->close();
        return $rows;
    }

    /**
     * Assignment submission metadata. File contents and typed answers are not included.
     *
     * @param int[] $courseids
     * @param int[] $userids
     * @return array[]
     */
    private static function submission_rows(array $courseids, array $userids): array {
        global $DB;

        if (!$courseids || !$userids) {
            return [];
        }
        [$csql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params = array_merge($cparams, $uparams);
        $sql = "SELECT s.id, s.userid, a.course AS courseid, a.id AS assignment, a.name,
                       s.status, s.attemptnumber, s.timecreated, s.timemodified, s.latest
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                 WHERE a.course {$csql}
                   AND s.userid {$usql}
              ORDER BY a.course ASC, s.userid ASC, s.id ASC";
        $rows = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $rows[] = [
                'userid' => (int) $record->userid,
                'courseid' => (int) $record->courseid,
                'assignment' => (int) $record->assignment,
                'name' => $record->name,
                'status' => $record->status,
                'attemptnumber' => (int) $record->attemptnumber,
                'timecreated' => (int) $record->timecreated,
                'timemodified' => (int) $record->timemodified,
                'latest' => (int) $record->latest,
            ];
        }
        $records->close();
        return $rows;
    }

    /**
     * Write CSV and JSON files into a zip in the requester's file area.
     *
     * @param \stdClass $log
     * @param array $sets
     */
    private static function store(\stdClass $log, array $sets): void {
        $temp = make_temp_directory('local_wwadmin/' . $log->id);
        $files = [];
        foreach ($sets as $name => $rows) {
            $columns = self::columns($name, $rows);
            $csv = $temp . '/' . $name . '.csv';
            $json = $temp . '/' . $name . '.json';
            self::write_csv($csv, $columns, $rows);
            file_put_contents($json, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $files[$name . '.csv'] = $csv;
            $files[$name . '.json'] = $json;
        }
        $context = \context_user::instance((int) $log->userid);
        $packer = get_file_packer('application/zip');
        $stored = $packer->archive_to_storage(
            $files,
            $context->id,
            'local_wwadmin',
            'export',
            (int) $log->id,
            '/',
            'export.zip',
            (int) $log->userid
        );
        remove_dir($temp);
        if (!$stored) {
            throw new \moodle_exception('failed', 'local_wwadmin');
        }
    }

    /**
     * Column names for one set, including an empty export.
     *
     * @param string $name
     * @param array[] $rows
     * @return string[]
     */
    private static function columns(string $name, array $rows): array {
        if ($rows) {
            return array_keys($rows[0]);
        }
        $empty = [
            'users' => ['id', 'username', 'firstname', 'lastname', 'email', 'idnumber', 'suspended', 'auth'],
            'enrolments' => ['userid', 'courseid', 'coursename', 'enrol', 'status', 'timestart', 'timeend', 'roles'],
            'grades' => ['userid', 'courseid', 'itemname', 'itemtype', 'itemmodule', 'finalgrade', 'rawgrademax', 'timemodified'],
            'attendance' => ['userid', 'courseid', 'sessdate', 'takenby', 'status'],
            'submissions' => ['userid', 'courseid', 'assignment', 'name', 'status', 'attemptnumber', 'timecreated', 'timemodified', 'latest'],
            'points' => ['userid', 'courseid', 'amount', 'reason', 'eventname', 'sourcekey', 'awardedby', 'timecreated'],
            'avatars' => ['userid', 'face', 'skin', 'hair', 'eyes', 'clothing', 'accessory'],
            'goals' => ['courseid', 'categoryid', 'name', 'target', 'timeend', 'createdby'],
        ];
        return $empty[$name] ?? [];
    }

    /**
     * Write one CSV file.
     *
     * @param string $path
     * @param string[] $columns
     * @param array[] $rows
     */
    private static function write_csv(string $path, array $columns, array $rows): void {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \moodle_exception('failed', 'local_wwadmin');
        }
        fputcsv($handle, $columns, ',', '"', '\\');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $row[$column] ?? '';
            }
            fputcsv($handle, $line, ',', '"', '\\');
        }
        fclose($handle);
    }

    /**
     * The zip for one export, if it has been stored.
     *
     * @param \stdClass $log
     * @return \stored_file|null
     */
    public static function stored_file(\stdClass $log): ?\stored_file {
        $context = \context_user::instance((int) $log->userid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        $file = get_file_storage()->get_file(
            $context->id,
            'local_wwadmin',
            'export',
            (int) $log->id,
            '/',
            'export.zip'
        );
        return $file ?: null;
    }

    /**
     * Update the status of one export.
     *
     * @param \stdClass $log
     * @param string $status
     * @param string $detail
     */
    private static function mark(\stdClass $log, string $status, string $detail): void {
        global $DB;

        $log->status = $status;
        $log->detail = $detail;
        $log->timecompleted = time();
        $DB->update_record('local_wwadmin_export', $log);
    }
}
