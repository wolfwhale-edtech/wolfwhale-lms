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

use local_wwnation\nation;

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
     * @param bool $queue False when the caller builds the archive in this request.
     * @return int Export record id.
     */
    public static function request(int $categoryid, bool $queue = true): int {
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
        if ($queue) {
            $task = new \local_wwadmin\task\export_task();
            $task->set_custom_data(['exportid' => (int) $id]);
            $task->set_userid((int) $USER->id);
            \core\task\manager::queue_adhoc_task($task);
        }
        return (int) $id;
    }

    /**
     * Build one Nation archive now and record it in the audit log.
     *
     * @param int $categoryid
     * @return int Export record id.
     */
    public static function build(int $categoryid): int {
        $id = self::request($categoryid, false);
        self::run($id);
        return $id;
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
        $nationid = nation::id_for_root((int) $log->categoryid);
        if ($nationid && !is_siteadmin((int) $log->userid) && !nation::is_member((int) $log->userid, $nationid)) {
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
        $userids = nation::connected_ids($categoryid);
        $nationid = nation::id_for_root($categoryid);
        $tree = scope::tree_ids($categoryid);
        $users = self::member_users($userids);
        $userids = array_map('intval', array_keys($users));
        $courses = scope::courses($tree);
        $courseids = array_map('intval', array_keys($courses));
        $sets = [
            'manifest' => [self::manifest_row($nationid, $categoryid, $userids, $courseids)],
            'ocap' => [self::ocap_row()],
            'users' => self::user_rows($users),
            'courses' => self::course_rows($courses),
            'enrolments' => self::enrolment_rows($courseids, $userids),
            'grades' => self::grade_rows($courseids, $userids),
            'attendance' => self::attendance_rows($courseids),
            'submissions' => self::submission_rows($courseids, $userids),
            'badges' => self::badge_rows($courseids, $userids),
            'preferences' => self::preference_rows($userids),
        ];
        if (class_exists(\local_wwpoints\points::class)) {
            $sets['points'] = \local_wwpoints\points::export_rows($courseids, $userids);
            $sets['levels'] = self::level_rows($courseids, $userids);
            $sets['avatars'] = \local_wwpoints\avatar::export_rows($userids);
            $sets['goals'] = \local_wwpoints\goal::export_rows($courseids, $tree);
        }
        $clean = [];
        foreach ($sets as $name => $rows) {
            $clean[$name] = self::scrub($rows);
        }
        return $clean;
    }

    /**
     * Members, without secret columns.
     *
     * @param int[] $userids
     * @return \stdClass[]
     */
    private static function member_users(array $userids): array {
        global $DB;

        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['deleted'] = 0;
        $params['guest'] = 'guest';
        return $DB->get_records_select(
            'user',
            "id {$insql} AND deleted = :deleted AND username <> :guest",
            $params,
            'lastname ASC, firstname ASC, id ASC',
            'id, username, firstname, lastname, email, idnumber, suspended, auth'
        );
    }

    /**
     * One manifest row. Counts are filled after the other sets are known to the caller.
     *
     * @param int $nationid
     * @param int $categoryid
     * @param int[] $userids
     * @param int[] $courseids
     * @return array
     */
    private static function manifest_row(int $nationid, int $categoryid, array $userids, array $courseids): array {
        global $DB;

        $name = $DB->get_field('local_wwnation', 'name', ['id' => $nationid]);
        return [
            'nationid' => $nationid,
            'name' => nation::plain((string) $name),
            'categoryid' => $categoryid,
            'exported' => time(),
            'users' => count($userids),
            'courses' => count($courseids),
        ];
    }

    /**
     * OCAP principles for this archive. The Nation holds each one.
     *
     * @return array
     */
    private static function ocap_row(): array {
        return [
            'ownership' => 'nation',
            'control' => 'nation',
            'access' => 'nation',
            'possession' => 'nation',
            'statute' => 'LAFOIP',
        ];
    }

    /**
     * Courses in this Nation.
     *
     * @param \stdClass[] $courses
     * @return array[]
     */
    private static function course_rows(array $courses): array {
        $rows = [];
        foreach ($courses as $course) {
            $rows[] = [
                'id' => (int) $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'categoryid' => (int) $course->category,
                'visible' => (int) $course->visible,
            ];
        }
        return $rows;
    }

    /**
     * Badges issued to these people when the badge belongs to this Nation.
     *
     * @param int[] $courseids
     * @param int[] $userids
     * @return array[]
     */
    private static function badge_rows(array $courseids, array $userids): array {
        global $DB;

        if (!$userids || !$DB->get_manager()->table_exists('badge_issued')) {
            return [];
        }
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params = $uparams;
        $coursesql = '1 = 0';
        if ($courseids) {
            [$csql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
            $params += $cparams;
            $coursesql = "b.courseid {$csql}";
        }
        $sql = "SELECT bi.id, bi.userid, b.id AS badgeid, b.name, b.courseid, bi.dateissued, bi.visible
                  FROM {badge_issued} bi
                  JOIN {badge} b ON b.id = bi.badgeid
                 WHERE bi.userid {$usql}
                   AND (b.courseid IS NULL OR b.courseid = 0 OR {$coursesql})
              ORDER BY bi.userid ASC, b.id ASC";
        $rows = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $rows[] = [
                'userid' => (int) $record->userid,
                'badgeid' => (int) $record->badgeid,
                'name' => $record->name,
                'courseid' => (int) $record->courseid,
                'dateissued' => (int) $record->dateissued,
                'visible' => (int) $record->visible,
            ];
        }
        $records->close();
        return $rows;
    }

    /**
     * Level for each person from points earned in this Nation.
     *
     * @param int[] $courseids
     * @param int[] $userids
     * @return array[]
     */
    private static function level_rows(array $courseids, array $userids): array {
        global $DB;

        if (!$userids || !$DB->get_manager()->table_exists('local_wwpoints_ledger')) {
            return [];
        }
        $rows = [];
        foreach ($userids as $userid) {
            $params = ['userid' => $userid];
            $where = 'userid = :userid AND (courseid = 0';
            if ($courseids) {
                [$csql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
                $params += $cparams;
                $where .= " OR courseid {$csql}";
            }
            $where .= ')';
            $total = (int) $DB->get_field_sql(
                "SELECT COALESCE(SUM(amount), 0) FROM {local_wwpoints_ledger} WHERE {$where}",
                $params
            );
            $rows[] = [
                'userid' => $userid,
                'points' => $total,
                'level' => \local_wwpoints\levels::for_points($total),
            ];
        }
        return $rows;
    }

    /**
     * Parent update switches for parents in this Nation.
     *
     * Only the known switch names are read. Other preferences are left out.
     *
     * @param int[] $userids
     * @return array[]
     */
    private static function preference_rows(array $userids): array {
        global $DB;

        if (!$userids || !class_exists(\local_wwupdates\preferences::class)) {
            return [];
        }
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'parent']);
        if (!$roleid) {
            return [];
        }
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $uparams['roleid'] = $roleid;
        $uparams['level'] = CONTEXT_USER;
        $parents = $DB->get_fieldset_sql(
            "SELECT DISTINCT ra.userid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.roleid = :roleid
                AND ctx.contextlevel = :level
                AND ra.userid {$usql}",
            $uparams
        );
        $keys = array_merge(\local_wwupdates\preferences::CHANNELS, \local_wwupdates\preferences::TYPES);
        $rows = [];
        foreach ($parents as $parentid) {
            $parentid = (int) $parentid;
            $row = ['userid' => $parentid];
            foreach ($keys as $key) {
                $row[$key] = \local_wwupdates\preferences::on($parentid, $key) ? 1 : 0;
            }
            $row['phone'] = \local_wwupdates\preferences::phone($parentid);
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Drop secret columns from every row.
     *
     * @param array[] $rows
     * @return array[]
     */
    private static function scrub(array $rows): array {
        $banned = ['password', 'secret', 'token', 'sesskey', 'passwordhash', 'privatekey', 'hash'];
        $clean = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                if (in_array(strtolower((string) $key), $banned, true)) {
                    unset($row[$key]);
                }
            }
            $clean[] = $row;
        }
        return $clean;
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
            'manifest' => ['nationid', 'name', 'categoryid', 'exported', 'users', 'courses'],
            'ocap' => ['ownership', 'control', 'access', 'possession', 'statute'],
            'users' => ['id', 'username', 'firstname', 'lastname', 'email', 'idnumber', 'suspended', 'auth'],
            'courses' => ['id', 'fullname', 'shortname', 'categoryid', 'visible'],
            'enrolments' => ['userid', 'courseid', 'coursename', 'enrol', 'status', 'timestart', 'timeend', 'roles'],
            'grades' => ['userid', 'courseid', 'itemname', 'itemtype', 'itemmodule', 'finalgrade', 'rawgrademax', 'timemodified'],
            'attendance' => ['userid', 'courseid', 'sessdate', 'takenby', 'status'],
            'submissions' => ['userid', 'courseid', 'assignment', 'name', 'status', 'attemptnumber', 'timecreated', 'timemodified', 'latest'],
            'badges' => ['userid', 'badgeid', 'name', 'courseid', 'dateissued', 'visible'],
            'levels' => ['userid', 'points', 'level'],
            'preferences' => ['userid', 'app', 'email', 'text', 'mark', 'absent', 'due', 'badge', 'message', 'weekly', 'phone'],
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
