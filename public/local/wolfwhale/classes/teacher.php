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

namespace local_wolfwhale;

/**
 * Data for the teacher home.
 *
 * Reads use the logged-in person. A course is included when that person holds
 * a teacher or editing-teacher role on the course, or on a category that
 * contains the course. A role on the whole site does not include every course.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher {
    /** @var string[] Teaching role archetypes. */
    public const ARCHETYPES = ['editingteacher', 'teacher'];

    /** @var int Days without a course visit before a student is listed as stuck. */
    public const AWAY_DAYS = 7;

    /**
     * A teacher or editing teacher in at least one course, and not a site admin.
     *
     * @param int $userid The person to check.
     * @return bool
     */
    public static function is_teacher(int $userid): bool {
        global $DB;

        if ($userid <= 0 || isguestuser($userid) || is_siteadmin($userid)) {
            return false;
        }
        $ids = self::course_ids_for($userid);
        if (!$ids) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        return $DB->record_exists_select('course', "id {$insql}", $params);
    }

    /**
     * Teachers leave the dashboard. Everyone else is left to the student check.
     *
     * @param \moodle_url $url Page they asked for.
     * @param int $userid The logged-in person.
     * @return bool
     */
    public static function should_redirect(\moodle_url $url, int $userid): bool {
        if (!self::is_teacher($userid)) {
            return false;
        }
        $dashboard = new \moodle_url('/my/index.php');
        $my = new \moodle_url('/my/');
        return $url->compare($dashboard, URL_MATCH_BASE) || $url->compare($my, URL_MATCH_BASE);
    }

    /**
     * Where a teacher should land after login, or null to leave the address alone.
     *
     * @param string|null $wantsurl Address stored at login, if any.
     * @param int $userid The person who just signed in.
     * @return string|null
     */
    public static function login_wantsurl(?string $wantsurl, int $userid): ?string {
        if (!self::is_teacher($userid)) {
            return null;
        }
        if ($wantsurl === null || $wantsurl === '' || self::is_homepage_url($wantsurl)) {
            return self::url()->out(false);
        }
        return null;
    }

    /**
     * The teacher home address.
     *
     * @return \moodle_url
     */
    public static function url(): \moodle_url {
        return new \moodle_url('/local/wolfwhale/teacher.php');
    }

    /**
     * Send anyone who is not a teacher away from these pages.
     */
    public static function require_teacher(): void {
        global $USER;

        if (isguestuser() || !self::is_teacher((int) $USER->id)) {
            if (home::is_student((int) $USER->id)) {
                redirect(home::url());
            }
            redirect(new \moodle_url('/my/'));
        }
    }

    /**
     * Page setup, after any data read that might reset the page.
     *
     * @param string $titlekey Language string key.
     * @param \moodle_url $url This page.
     */
    public static function setup_page(string $titlekey, \moodle_url $url): void {
        global $PAGE, $USER;

        $PAGE->set_context(\context_user::instance($USER->id));
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('base');
        $PAGE->set_secondary_navigation(false);
        $title = get_string($titlekey, 'local_wolfwhale');
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
    }

    /**
     * Tiles for the logged-in teacher, in display order.
     *
     * @return array[]
     */
    public static function get_tiles(): array {
        $post = self::post_courses();
        $marking = self::marking_items();
        $attendance = self::attendance_courses();
        $stuck = self::stuck_rows();
        $marks = self::marks_courses();

        $tiles = [
            self::course_tile('post', 'post', $post, new \moodle_url('/local/wolfwhale/post.php')),
            self::count_tile('marking', 'marking', self::marking_total($marking), new \moodle_url('/local/wolfwhale/marking.php')),
            self::course_tile('attendance', 'attendance', $attendance, new \moodle_url('/local/wolfwhale/attendance.php'), true),
            self::messages_tile(),
            self::count_tile('stuck', 'stuck', count($stuck), new \moodle_url('/local/wolfwhale/stuck.php')),
            self::course_tile('marks', 'marks', $marks, new \moodle_url('/local/wolfwhale/marks.php'), false, true),
        ];
        return array_values(array_filter($tiles));
    }

    /**
     * Template data for the teacher home.
     *
     * @return array
     */
    public static function export(): array {
        $tiles = self::get_tiles();
        $speech = [];
        foreach ($tiles as $tile) {
            $speech[] = $tile['speech'];
        }
        return [
            'tiles' => $tiles,
            'screenspeech' => implode(' ', $speech),
            'read' => get_string('read', 'local_wolfwhale'),
        ];
    }

    /**
     * Courses this teacher can add a page, a file, or an assignment to.
     *
     * @return \stdClass[]
     */
    public static function post_courses(): array {
        return self::courses_with('moodle/course:manageactivities');
    }

    /**
     * One-click addresses for adding a lesson page, a file, or an assignment.
     *
     * The addresses open the real add screen. Nothing is created until that
     * screen is saved.
     *
     * @param int $courseid
     * @return array[]
     */
    public static function post_actions(int $courseid): array {
        $courses = self::post_courses();
        $allowed = false;
        foreach ($courses as $course) {
            if ((int) $course->id === $courseid) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return [];
        }
        $mods = [
            'page' => 'lesson',
            'resource' => 'file',
            'assign' => 'assignment',
        ];
        $installed = \core_component::get_plugin_list('mod');
        $actions = [];
        foreach ($mods as $modname => $stringkey) {
            if (!isset($installed[$modname])) {
                continue;
            }
            $actions[] = [
                'mod' => $modname,
                'word' => get_string($stringkey, 'local_wolfwhale'),
                'url' => (new \moodle_url('/course/modedit.php', [
                    'add' => $modname,
                    'course' => $courseid,
                    'section' => 0,
                    'return' => 0,
                ]))->out(false),
            ];
        }
        return $actions;
    }

    /**
     * Courses grouped with the add actions the teacher is allowed to open.
     *
     * @return array[]
     */
    public static function post_page(): array {
        $courses = [];
        foreach (self::post_courses() as $course) {
            $courses[] = [
                'name' => self::plain($course->fullname),
                'actions' => self::post_actions((int) $course->id),
            ];
        }
        return $courses;
    }

    /**
     * Submissions that still need a grade, in this teacher's courses only.
     *
     * Individual submissions are one row each. A team assignment that still
     * needs grading is one row for the whole assignment.
     *
     * @return array[]
     */
    public static function marking_items(): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $items = [];
        foreach (self::courses() as $course) {
            $modinfo = get_fast_modinfo($course);
            foreach ($modinfo->get_instances_of('assign') as $cm) {
                if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
                    continue;
                }
                $context = \context_module::instance($cm->id);
                if (!has_capability('mod/assign:grade', $context)) {
                    continue;
                }
                $assign = new \assign($context, $cm, $course);
                $instance = $assign->get_instance();
                $groupids = self::marking_groups($cm);
                if ($groupids === []) {
                    continue;
                }
                if (!empty($instance->teamsubmission)) {
                    $count = $assign->count_submissions_need_grading_with_groups($groupids ?? []);
                    if ($count < 1) {
                        continue;
                    }
                    $items[] = self::row(
                        self::plain($instance->name),
                        (string) $count,
                        self::plain($course->fullname),
                        (new \moodle_url('/mod/assign/view.php', [
                            'id' => $cm->id,
                            'action' => 'grading',
                        ]))->out(false),
                        $count
                    );
                    continue;
                }

                $params = [
                    'assignid' => $instance->id,
                    'submitted' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                ];
                [$esql, $eparams] = get_enrolled_sql($context, '', $groupids ?? [], true);
                $params += $eparams;
                $scale = ((int) $instance->grade < 0) ? ' OR g.grade = ' . ASSIGN_GRADE_NOT_SET : '';
                $sql = "SELECT s.id, s.userid, u.firstname, u.lastname, u.firstnamephonetic,
                               u.lastnamephonetic, u.middlename, u.alternatename
                          FROM {assign_submission} s
                          JOIN {user} u ON u.id = s.userid
                     LEFT JOIN {assign_grades} g
                            ON s.assignment = g.assignment
                           AND s.userid = g.userid
                           AND g.attemptnumber = s.attemptnumber
                          JOIN ({$esql}) e ON e.id = s.userid
                         WHERE s.assignment = :assignid
                           AND s.status = :submitted
                           AND s.latest = 1
                           AND s.userid > 0
                           AND s.timemodified IS NOT NULL
                           AND (s.timemodified >= g.timemodified OR g.timemodified IS NULL
                                OR g.grade IS NULL{$scale})
                      ORDER BY u.lastname ASC, u.firstname ASC";
                $rows = $DB->get_records_sql($sql, $params);
                $blind = $assign->is_blind_marking();
                foreach ($rows as $row) {
                    $params = [
                        'id' => $cm->id,
                        'action' => 'grader',
                    ];
                    if ($blind) {
                        $params['blindid'] = $assign->get_uniqueid_for_user($row->userid);
                        $word = (string) $params['blindid'];
                    } else {
                        $params['userid'] = $row->userid;
                        $word = self::plain(fullname($row));
                    }
                    $items[] = self::row(
                        $word,
                        self::plain($instance->name),
                        self::plain($course->fullname),
                        (new \moodle_url('/mod/assign/view.php', $params))->out(false),
                        1
                    );
                }
            }
        }
        return $items;
    }

    /**
     * Courses where this teacher can open the grader report.
     *
     * @return \stdClass[]
     */
    public static function marks_courses(): array {
        return self::courses_with('gradereport/grader:view');
    }

    /**
     * Grader report address for one course, or null when this teacher cannot view it.
     *
     * @param int $courseid
     * @return \moodle_url|null
     */
    public static function marks_url(int $courseid): ?\moodle_url {
        foreach (self::marks_courses() as $course) {
            if ((int) $course->id === $courseid) {
                return new \moodle_url('/grade/report/grader/index.php', ['id' => $courseid]);
            }
        }
        return null;
    }

    /**
     * Courses where this teacher can take attendance.
     *
     * @return \stdClass[]
     */
    public static function attendance_courses(): array {
        if (!class_exists(\local_wwattendance\attendance::class)) {
            return [];
        }
        $courses = [];
        foreach (self::courses() as $course) {
            if (\local_wwattendance\attendance::can_take((int) $course->id)) {
                $courses[] = $course;
            }
        }
        return $courses;
    }

    /**
     * Students who are overdue or have not opened the course in 7 days.
     *
     * One row per student per course. Other teachers' courses are not included.
     *
     * @return array[]
     */
    public static function stuck_rows(): array {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $now = time();
        $cutoff = $now - (self::AWAY_DAYS * DAYSECS);
        $rows = [];
        foreach (self::courses() as $course) {
            $students = self::students_in_course((int) $course->id);
            if (!$students) {
                continue;
            }
            $last = self::last_access_map((int) $course->id, array_keys($students));
            $overdue = self::overdue_map($course, $students, $now);
            foreach ($students as $student) {
                $seen = $last[(int) $student->id] ?? null;
                $away = ($seen === null) || ($seen <= $cutoff);
                $work = $overdue[(int) $student->id] ?? null;
                if (!$away && $work === null) {
                    continue;
                }
                $facts = [];
                if ($work !== null) {
                    $facts[] = $work['name'];
                }
                if ($away) {
                    if ($seen === null) {
                        $facts[] = get_string('never', 'local_wolfwhale');
                    } else {
                        $days = (int) floor(($now - $seen) / DAYSECS);
                        $facts[] = get_string('days', 'local_wolfwhale', $days);
                    }
                }
                $url = $work['url'] ?? (new \moodle_url('/user/view.php', [
                    'id' => $student->id,
                    'course' => $course->id,
                ]))->out(false);
                $rows[] = self::row(
                    self::plain(fullname($student)),
                    implode(', ', $facts),
                    self::plain($course->fullname),
                    $url,
                    1
                );
            }
        }
        return $rows;
    }

    /**
     * Students in this teacher's courses, once each, with a link to message them.
     *
     * @return array[]
     */
    public static function message_rows(): array {
        if (!self::messaging_on()) {
            return [];
        }
        $people = [];
        foreach (self::courses() as $course) {
            foreach (self::students_in_course((int) $course->id) as $student) {
                $id = (int) $student->id;
                if (!isset($people[$id])) {
                    $people[$id] = [
                        'user' => $student,
                        'courses' => [],
                    ];
                }
                $people[$id]['courses'][] = self::plain($course->fullname);
            }
        }
        $rows = [];
        foreach ($people as $id => $person) {
            sort($person['courses']);
            $rows[] = self::row(
                self::plain(fullname($person['user'])),
                implode(', ', $person['courses']),
                '',
                (new \moodle_url('/message/index.php', ['id' => $id]))->out(false),
                1
            );
        }
        return $rows;
    }

    /**
     * Unread conversations for the logged-in person.
     *
     * This is the same count the site already uses.
     *
     * @return int
     */
    public static function unread_count(): int {
        global $USER;

        if (!self::messaging_on()) {
            return 0;
        }
        return (int) \core_message\api::count_unread_conversations($USER);
    }

    /**
     * Courses the logged-in person teaches, that they can still open.
     *
     * @return \stdClass[]
     */
    public static function courses(): array {
        global $DB, $USER;

        $ids = self::course_ids_for((int) $USER->id);
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $courses = $DB->get_records_select('course', "id {$insql}", $params, 'fullname ASC');
        $kept = [];
        foreach ($courses as $course) {
            if ((int) $course->id === (int) SITEID) {
                continue;
            }
            $context = \context_course::instance($course->id);
            // A visible course is included. A hidden course is included when this
            // person is allowed to see hidden courses. Enrolment is not required:
            // a teacher role on the category is enough.
            if (empty($course->visible) && !has_capability('moodle/course:viewhiddencourses', $context)) {
                continue;
            }
            $kept[] = $course;
        }
        return $kept;
    }

    /**
     * Course ids where this person teaches, including courses under a category role.
     *
     * @param int $userid
     * @return int[]
     */
    public static function course_ids_for(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }
        $assigns = $DB->get_records_sql(
            "SELECT ctx.id, ctx.contextlevel, ctx.instanceid, ctx.path
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid
                AND (r.archetype = :editing OR r.archetype = :teacher)
                AND ctx.contextlevel IN (:courselevel, :catlevel)",
            [
                'userid' => $userid,
                'editing' => 'editingteacher',
                'teacher' => 'teacher',
                'courselevel' => CONTEXT_COURSE,
                'catlevel' => CONTEXT_COURSECAT,
            ]
        );
        $ids = [];
        foreach ($assigns as $ctx) {
            if ((int) $ctx->contextlevel === CONTEXT_COURSE) {
                if ((int) $ctx->instanceid !== (int) SITEID) {
                    $ids[(int) $ctx->instanceid] = true;
                }
                continue;
            }
            $like = $DB->sql_like('child.path', ':path');
            $childids = $DB->get_fieldset_sql(
                "SELECT child.instanceid
                   FROM {context} child
                  WHERE child.contextlevel = :courselevel
                    AND child.instanceid <> :siteid
                    AND {$like}",
                [
                    'path' => $DB->sql_like_escape($ctx->path) . '/%',
                    'courselevel' => CONTEXT_COURSE,
                    'siteid' => SITEID,
                ]
            );
            foreach ($childids as $id) {
                $ids[(int) $id] = true;
            }
        }
        return array_keys($ids);
    }

    /**
     * True when messaging is switched on and this person may send a message.
     *
     * @return bool
     */
    private static function messaging_on(): bool {
        global $CFG;

        if (empty($CFG->messaging)) {
            return false;
        }
        return has_capability('moodle/site:sendmessage', \context_system::instance());
    }

    /**
     * Teaching courses where the logged-in person has a capability.
     *
     * @param string $capability
     * @return \stdClass[]
     */
    private static function courses_with(string $capability): array {
        $courses = [];
        foreach (self::courses() as $course) {
            $context = \context_course::instance($course->id);
            if (has_capability($capability, $context)) {
                $courses[] = $course;
            }
        }
        return $courses;
    }

    /**
     * Enrolled students in one course. The logged-in teacher is left out.
     *
     * @param int $courseid
     * @return \stdClass[] Keyed by user id.
     */
    private static function students_in_course(int $courseid): array {
        global $DB, $USER;

        $students = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic,
                            u.lastnamephonetic, u.middlename, u.alternatename
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :level
                AND ctx.instanceid = :courseid
                AND r.archetype = :student
                AND u.deleted = 0
                AND u.id <> :me
                AND ue.status = :active
                AND e.status = :enabled
           ORDER BY u.lastname ASC, u.firstname ASC",
            [
                'level' => CONTEXT_COURSE,
                'courseid' => $courseid,
                'student' => 'student',
                'me' => (int) $USER->id,
                'active' => ENROL_USER_ACTIVE,
                'enabled' => ENROL_INSTANCE_ENABLED,
            ]
        );
        return $students;
    }

    /**
     * Last course visit for each student, or absent from the map when they never opened it.
     *
     * @param int $courseid
     * @param int[] $userids
     * @return array<int, int>
     */
    private static function last_access_map(int $courseid, array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        $records = $DB->get_records_select(
            'user_lastaccess',
            "courseid = :courseid AND userid {$insql}",
            $params,
            '',
            'id, userid, timeaccess'
        );
        $map = [];
        foreach ($records as $record) {
            $map[(int) $record->userid] = (int) $record->timeaccess;
        }
        return $map;
    }

    /**
     * The most overdue assignment or quiz for each student.
     *
     * A submitted assignment, or a finished quiz, is not overdue. An extension
     * or a user override that moves the date into the future is not overdue.
     *
     * @param \stdClass $course
     * @param \stdClass[] $students
     * @param int $now
     * @return array<int, array{name: string, url: string, time: int}>
     */
    private static function overdue_map(\stdClass $course, array $students, int $now): array {
        global $DB;

        $map = [];
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_instances_of('assign') as $cm) {
            if (empty($cm->visible) || !empty($cm->deletioninprogress)) {
                continue;
            }
            $context = \context_module::instance($cm->id);
            $assign = new \assign($context, $cm, $course);
            if (!empty($assign->get_instance()->teamsubmission)) {
                continue;
            }
            foreach (self::eligible($students, $cm) as $student) {
                $assign->update_effective_access($student->id);
                $due = (int) $assign->get_instance($student->id)->duedate;
                $flags = $assign->get_user_flags($student->id, false);
                if ($flags && !empty($flags->extensionduedate) && (int) $flags->extensionduedate > $due) {
                    $due = (int) $flags->extensionduedate;
                }
                if ($due <= 0 || $due >= $now) {
                    continue;
                }
                $submission = $assign->get_user_submission($student->id, false);
                if ($submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
                    continue;
                }
                self::keep_earlier($map, (int) $student->id, $due, self::plain($assign->get_instance()->name),
                    (new \moodle_url('/mod/assign/view.php', [
                        'id' => $cm->id,
                        'action' => 'grader',
                        'userid' => $student->id,
                    ]))->out(false));
            }
        }

        foreach ($modinfo->get_instances_of('quiz') as $cm) {
            if (empty($cm->visible) || !empty($cm->deletioninprogress)) {
                continue;
            }
            $base = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            foreach (self::eligible($students, $cm) as $student) {
                $quiz = quiz_update_effective_access(clone $base, $student->id);
                $deadline = self::quiz_deadline($quiz);
                if ($deadline <= 0 || $deadline >= $now) {
                    continue;
                }
                $finished = $DB->record_exists('quiz_attempts', [
                    'quiz' => $base->id,
                    'userid' => $student->id,
                    'state' => 'finished',
                    'preview' => 0,
                ]);
                if ($finished) {
                    continue;
                }
                self::keep_earlier($map, (int) $student->id, $deadline, self::plain($base->name),
                    (new \moodle_url('/mod/quiz/view.php', ['id' => $cm->id]))->out(false));
            }
        }
        return $map;
    }

    /**
     * Groups this teacher may mark, or null when every group is allowed.
     *
     * An empty array means separate groups and this teacher is in none of them,
     * so the queue is empty. An empty list passed to the enrolment query would
     * mean the opposite: every group.
     *
     * @param \cm_info $cm
     * @return int[]|null
     */
    private static function marking_groups(\cm_info $cm): ?array {
        if (groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
            return null;
        }
        $context = \context_module::instance($cm->id);
        if (has_capability('moodle/site:accessallgroups', $context)) {
            return null;
        }
        $groups = groups_get_activity_allowed_groups($cm);
        if (!$groups) {
            return [];
        }
        return array_map('intval', array_keys($groups));
    }

    /**
     * Students who are allowed to see this activity.
     *
     * A group or grouping restriction is applied here. Course absence is not.
     *
     * @param \stdClass[] $students
     * @param \cm_info $cm
     * @return \stdClass[] Keyed by user id.
     */
    private static function eligible(array $students, \cm_info $cm): array {
        $info = new \core_availability\info_module($cm);
        $kept = [];
        foreach ($info->filter_user_list($students) as $student) {
            $kept[(int) $student->id] = $student;
        }
        return $kept;
    }

    /**
     * When a quiz becomes late: the due date, the close date, or the earlier of the two.
     *
     * @param \stdClass $quiz
     * @return int
     */
    private static function quiz_deadline(\stdClass $quiz): int {
        $due = (int) ($quiz->duedate ?? 0);
        $close = (int) ($quiz->timeclose ?? 0);
        if ($due > 0 && $close > 0) {
            return min($due, $close);
        }
        return max($due, $close);
    }

    /**
     * Keep the earlier due item for one student.
     *
     * @param array $map
     * @param int $userid
     * @param int $time
     * @param string $name
     * @param string $url
     */
    private static function keep_earlier(array &$map, int $userid, int $time, string $name, string $url): void {
        if (isset($map[$userid]) && $map[$userid]['time'] <= $time) {
            return;
        }
        $map[$userid] = [
            'name' => $name,
            'url' => $url,
            'time' => $time,
        ];
    }

    /**
     * Messages tile, or null when messaging is switched off.
     *
     * @return array|null
     */
    private static function messages_tile(): ?array {
        if (!self::messaging_on()) {
            return null;
        }
        $count = self::unread_count();
        $word = get_string('messages', 'local_wolfwhale');
        $url = (new \moodle_url('/local/wolfwhale/students.php'))->out(false);
        if ($count < 1) {
            return self::tile('messages', $word, get_string('none', 'local_wolfwhale'), true, $url);
        }
        return self::tile('messages', $word, (string) $count, false, $url);
    }

    /**
     * A tile whose fact is a count.
     *
     * @param string $id
     * @param string $stringkey
     * @param int $count
     * @param \moodle_url $url
     * @return array
     */
    private static function count_tile(string $id, string $stringkey, int $count, \moodle_url $url): array {
        $word = get_string($stringkey, 'local_wolfwhale');
        if ($count < 1) {
            return self::tile($id, $word, get_string('none', 'local_wolfwhale'), true, $url->out(false));
        }
        return self::tile($id, $word, (string) $count, false, $url->out(false));
    }

    /**
     * A tile for a set of courses. One course opens it directly when asked.
     *
     * @param string $id
     * @param string $stringkey
     * @param \stdClass[] $courses
     * @param \moodle_url $listurl
     * @param bool $directattendance Open the take-attendance page when there is one course.
     * @param bool $directmarks Open the grader report when there is one course.
     * @return array
     */
    private static function course_tile(
        string $id,
        string $stringkey,
        array $courses,
        \moodle_url $listurl,
        bool $directattendance = false,
        bool $directmarks = false
    ): array {
        $word = get_string($stringkey, 'local_wolfwhale');
        $count = count($courses);
        if ($count === 0) {
            return self::tile($id, $word, get_string('none', 'local_wolfwhale'), true, $listurl->out(false));
        }
        $course = reset($courses);
        if ($count === 1 && $directattendance) {
            $url = (new \moodle_url('/local/wwattendance/take.php', ['courseid' => $course->id]))->out(false);
            return self::tile($id, $word, self::plain($course->fullname), false, $url);
        }
        if ($count === 1 && $directmarks) {
            $url = (new \moodle_url('/grade/report/grader/index.php', ['id' => $course->id]))->out(false);
            return self::tile($id, $word, self::plain($course->fullname), false, $url);
        }
        if ($count === 1) {
            return self::tile($id, $word, self::plain($course->fullname), false, $listurl->out(false));
        }
        return self::tile($id, $word, (string) $count, false, $listurl->out(false));
    }

    /**
     * How many submissions the marking list represents.
     *
     * @param array[] $items
     * @return int
     */
    private static function marking_total(array $items): int {
        $total = 0;
        foreach ($items as $item) {
            $total += (int) $item['count'];
        }
        return $total;
    }

    /**
     * One list row.
     *
     * @param string $word
     * @param string $fact
     * @param string $course
     * @param string $url
     * @param int $count
     * @return array
     */
    private static function row(string $word, string $fact, string $course, string $url, int $count): array {
        return [
            'word' => $word,
            'fact' => $fact,
            'course' => $course,
            'url' => $url,
            'count' => $count,
        ];
    }

    /**
     * One tile.
     *
     * @param string $id
     * @param string $word
     * @param string $fact
     * @param bool $empty
     * @param string $url
     * @return array
     */
    private static function tile(string $id, string $word, string $fact, bool $empty, string $url): array {
        $speech = get_string('speech', 'local_wolfwhale', (object) ['word' => $word, 'fact' => $fact]);
        return [
            'id' => $id,
            'word' => $word,
            'fact' => $fact,
            'empty' => $empty,
            'url' => $url,
            'speech' => $speech,
            'ispost' => $id === 'post',
            'ismarking' => $id === 'marking',
            'isattendance' => $id === 'attendance',
            'ismessages' => $id === 'messages',
            'isstuck' => $id === 'stuck',
            'ismarks' => $id === 'marks',
        ];
    }

    /**
     * A name for the screen, with no HTML and no encoded ampersand.
     *
     * @param string $value
     * @return string
     */
    private static function plain(string $value): string {
        $text = format_string($value, true, ['escape' => false, 'filter' => false]);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }

    /**
     * True when the address is the site front page or the dashboard.
     *
     * @param string $wantsurl
     * @return bool
     */
    private static function is_homepage_url(string $wantsurl): bool {
        global $CFG;

        $path = parse_url($wantsurl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $root = parse_url($CFG->wwwroot, PHP_URL_PATH);
        if (is_string($root) && $root !== '' && $root !== '/' && str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }
        if ($path === '' || $path === '/') {
            $path = '/';
        } else {
            $path = rtrim($path, '/');
        }
        return in_array($path, ['/', '/index.php', '/my', '/my/index.php'], true);
    }
}
