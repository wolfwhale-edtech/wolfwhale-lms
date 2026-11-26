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
 * Parent accounts and the parent home.
 *
 * A parent is a person who holds the parent role in a child's user context.
 * Every read checks that link before it returns course data.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class parents {
    /** @var string Role shortname assigned in the child's user context. */
    public const SHORTNAME = 'parent';

    /** @var string[] Capabilities granted to that role. */
    private const ROLE_CAPABILITIES = [
        'moodle/user:viewdetails',
        'moodle/user:viewalldetails',
        'moodle/user:readuserposts',
        'moodle/user:readuserblogs',
        'moodle/user:viewuseractivitiesreport',
        'moodle/grade:viewall',
        'moodle/badges:viewotherbadges',
    ];

    /**
     * Create the parent role when it is missing, and keep its user-context rules.
     */
    public static function install_role(): void {
        global $DB;

        $roleid = self::role_id();
        if (!$roleid) {
            $roleid = (int) create_role(
                get_string('parent', 'local_wolfwhale'),
                self::SHORTNAME,
                get_string('parentroledesc', 'local_wolfwhale')
            );
        }
        set_role_contextlevels($roleid, [CONTEXT_USER]);
        $systemid = \context_system::instance()->id;
        foreach (self::ROLE_CAPABILITIES as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $systemid, true);
        }
        foreach (['manager', 'editingteacher', 'teacher'] as $shortname) {
            $fromid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
            if (!$fromid) {
                continue;
            }
            if (!$DB->record_exists('role_allow_assign', ['roleid' => $fromid, 'allowassign' => $roleid])) {
                core_role_set_assign_allowed((int) $fromid, $roleid);
            }
        }
        accesslib_clear_all_caches(true);
    }

    /**
     * The parent role id, or 0 when it has not been created.
     *
     * @return int
     */
    public static function role_id(): int {
        global $DB;

        $id = $DB->get_field('role', 'id', ['shortname' => self::SHORTNAME]);
        return $id ? (int) $id : 0;
    }

    /**
     * True when this person is linked to at least one child.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_parent(int $userid): bool {
        return (bool) self::children_of($userid);
    }

    /**
     * Parents who are not teachers leave the dashboard.
     *
     * @param \moodle_url $url
     * @param int $userid
     * @return bool
     */
    public static function should_redirect(\moodle_url $url, int $userid): bool {
        if (!self::is_parent($userid) || teacher::is_teacher($userid)) {
            return false;
        }
        $dashboard = new \moodle_url('/my/index.php');
        $my = new \moodle_url('/my/');
        return $url->compare($dashboard, URL_MATCH_BASE) || $url->compare($my, URL_MATCH_BASE);
    }

    /**
     * Where a parent should land after login, or null to leave the address alone.
     *
     * @param string|null $wantsurl
     * @param int $userid
     * @return string|null
     */
    public static function login_wantsurl(?string $wantsurl, int $userid): ?string {
        if (!self::is_parent($userid) || teacher::is_teacher($userid)) {
            return null;
        }
        if ($wantsurl === null || $wantsurl === '' || self::is_homepage_url($wantsurl)) {
            return self::url()->out(false);
        }
        return null;
    }

    /**
     * The parent home address.
     *
     * @return \moodle_url
     */
    public static function url(): \moodle_url {
        return new \moodle_url('/local/wolfwhale/parent.php');
    }

    /**
     * Send anyone who is not a parent away from these pages.
     */
    public static function require_parent(): void {
        global $USER;

        if (isguestuser() || !self::is_parent((int) $USER->id)) {
            if (teacher::is_teacher((int) $USER->id)) {
                redirect(teacher::url());
            }
            if (home::is_student((int) $USER->id)) {
                redirect(home::url());
            }
            redirect(new \moodle_url('/my/'));
        }
    }

    /**
     * Page setup, after any data read that might reset the page.
     *
     * @param string $titlekey
     * @param \moodle_url $url
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
     * The logged-in person's linked children.
     *
     * @return \stdClass[]
     */
    public static function children(): array {
        global $USER;

        return self::children_of((int) $USER->id);
    }

    /**
     * The requested child when they are linked, otherwise the first linked child.
     *
     * A child id that is not linked is refused. It is not treated as empty.
     *
     * @param int $childid
     * @return \stdClass
     */
    public static function resolve(int $childid): \stdClass {
        $children = self::children();
        if (!$children) {
            throw new \moodle_exception('cannotview', 'local_wolfwhale');
        }
        if ($childid > 0) {
            foreach ($children as $child) {
                if ((int) $child->id === $childid) {
                    return $child;
                }
            }
            throw new \moodle_exception('cannotview', 'local_wolfwhale');
        }
        return reset($children);
    }

    /**
     * Refuse unless the logged-in person is linked to this child.
     *
     * @param int $childid
     * @return \stdClass
     */
    public static function require_linked(int $childid): \stdClass {
        global $USER;

        foreach (self::children_of((int) $USER->id) as $child) {
            if ((int) $child->id === $childid) {
                return $child;
            }
        }
        throw new \moodle_exception('cannotview', 'local_wolfwhale');
    }

    /**
     * Template data for the parent home.
     *
     * @param int $childid
     * @return array
     */
    public static function export(int $childid): array {
        $child = self::require_linked($childid);
        $tiles = self::get_tiles($childid);
        $speech = [];
        foreach ($tiles as $tile) {
            $speech[] = $tile['speech'];
        }
        $picker = self::child_picker($childid, self::url());
        if (!empty($picker['several'])) {
            array_unshift($speech, self::plain(fullname($child)) . '.');
        }
        return [
            'tiles' => $tiles,
            'screenspeech' => implode(' ', $speech),
            'read' => get_string('read', 'local_wolfwhale'),
            'several' => $picker['several'],
            'children' => $picker['children'],
        ];
    }

    /**
     * Tiles for one linked child, in display order.
     *
     * @param int $childid
     * @return array[]
     */
    public static function get_tiles(int $childid): array {
        $tiles = [
            self::marks_tile($childid),
            self::attendance_tile($childid),
            self::due_tile($childid),
            self::badges_tile($childid),
            self::messages_tile($childid),
            self::report_tile($childid),
            self::updates_tile(),
        ];
        return array_values(array_filter($tiles));
    }

    /**
     * Draw a list page for one linked child.
     *
     * @param string $titlekey
     * @param \moodle_url $url
     * @param int $childid
     * @param array[] $rows
     */
    public static function render_list(string $titlekey, \moodle_url $url, int $childid, array $rows): void {
        global $OUTPUT;

        self::setup_page($titlekey, $url);
        $picker = self::child_picker($childid, $url);
        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template('local_wolfwhale/list', [
            'rows' => $rows,
            'empty' => $rows === [],
            'none' => get_string('none', 'local_wolfwhale'),
            'several' => $picker['several'],
            'children' => $picker['children'],
        ]);
        echo $OUTPUT->footer();
    }

    /**
     * Course marks for one linked child.
     *
     * A course is included only when the grade report would allow this parent
     * to see that child's mark. Other people in the course are not read.
     *
     * @param int $childid
     * @return array[]
     */
    public static function marks_rows(int $childid): array {
        $rows = [];
        foreach (self::child_courses($childid) as $course) {
            $grade = self::grade_text($childid, $course);
            if ($grade === null) {
                continue;
            }
            $rows[] = self::row(self::plain($course->fullname), $grade, '', '');
        }
        return $rows;
    }

    /**
     * Upcoming and overdue assignments for one linked child.
     *
     * The date is the child's own date: a user or group override, then an
     * extension when that is later. A team assignment is submitted when the
     * group's submission is submitted.
     *
     * @param int $childid
     * @return array[]
     */
    public static function due_rows(int $childid): array {
        global $CFG;

        $courses = self::child_courses($childid);
        if (!$courses) {
            return [];
        }
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $items = [];
        $now = time();
        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course->id, $childid);
            foreach ($modinfo->get_instances_of('assign') as $cm) {
                if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
                    continue;
                }
                $context = \context_module::instance($cm->id);
                $assign = new \assign($context, $cm, $course);
                $assign->update_effective_access($childid);
                $instance = $assign->get_instance($childid);
                $due = (int) $instance->duedate;
                $flags = $assign->get_user_flags($childid, false);
                if ($flags && !empty($flags->extensionduedate) && (int) $flags->extensionduedate > $due) {
                    $due = (int) $flags->extensionduedate;
                }
                if ($due <= 0) {
                    continue;
                }
                if (!empty($instance->teamsubmission)) {
                    $submission = $assign->get_group_submission($childid, 0, false);
                } else {
                    $submission = $assign->get_user_submission($childid, false);
                }
                $submitted = $submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED;
                if ($due >= $now) {
                    $overdue = false;
                    $fact = userdate($due, get_string('strftimedateshort', 'langconfig'));
                } else if (!$submitted) {
                    $overdue = true;
                    $fact = get_string('overdue', 'local_wolfwhale');
                } else {
                    continue;
                }
                $items[] = [
                    'overdue' => $overdue,
                    'due' => $due,
                    'row' => self::row(
                        self::plain($cm->name),
                        self::plain($fact),
                        self::plain($course->fullname),
                        ''
                    ),
                ];
            }
        }
        usort($items, function (array $a, array $b): int {
            if ($a['overdue'] !== $b['overdue']) {
                return $a['overdue'] ? -1 : 1;
            }
            return $a['due'] <=> $b['due'];
        });
        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item['row'];
        }
        return $rows;
    }

    /**
     * Attendance marks for one linked child.
     *
     * @param int $childid
     * @return array[]
     */
    public static function attendance_rows(int $childid): array {
        self::require_linked($childid);
        if (!self::attendance_available()) {
            return [];
        }
        $rows = [];
        foreach (\local_wwattendance\attendance::records_for_user($childid) as $record) {
            $status = self::status_word((string) $record->status);
            if ($status === '') {
                continue;
            }
            $rows[] = self::row(
                self::plain($record->fullname),
                $status,
                userdate((int) $record->sessdate, get_string('strftimedate', 'langconfig')),
                ''
            );
        }
        return $rows;
    }

    /**
     * Badges this child has earned that are visible to someone else.
     *
     * @param int $childid
     * @return array[]
     */
    public static function badge_rows(int $childid): array {
        global $CFG;

        self::require_linked($childid);
        if (empty($CFG->enablebadges)) {
            return [];
        }
        $context = \context_user::instance($childid);
        if (!has_capability('moodle/badges:viewotherbadges', $context)) {
            return [];
        }
        require_once($CFG->libdir . '/badgeslib.php');
        $rows = [];
        foreach (badges_get_user_badges($childid, 0, 0, 0, '', true) as $badge) {
            $rows[] = self::row(
                self::plain($badge->name),
                '',
                '',
                (new \moodle_url('/badges/badge.php', ['hash' => $badge->uniquehash]))->out(false)
            );
        }
        return $rows;
    }

    /**
     * The child's teachers, with a message link when core messaging allows it.
     *
     * When the teacher has not blocked the parent, and the site would otherwise
     * refuse because they share no course, the parent is added as that teacher's
     * contact. No message is sent. A block is left in place.
     *
     * @param int $childid
     * @return array[]
     */
    public static function message_rows(int $childid): array {
        global $CFG, $USER;

        self::require_linked($childid);
        if (empty($CFG->messaging) || !has_capability('moodle/site:sendmessage', \context_system::instance())) {
            return [];
        }
        $rows = [];
        foreach (self::teachers_of($childid) as $teacher) {
            $url = self::message_url((int) $teacher->id, (int) $USER->id);
            if ($url === null) {
                continue;
            }
            $courses = array_values(array_unique($teacher->courses));
            sort($courses);
            $rows[] = self::row(self::plain(fullname($teacher)), implode(', ', $courses), '', $url);
        }
        return $rows;
    }

    /**
     * A printable summary of one term for one linked child.
     *
     * @param int $childid
     * @param array $term
     * @return array
     */
    public static function report(int $childid, array $term): array {
        $child = self::require_linked($childid);
        $records = [];
        if (self::attendance_available()) {
            $records = \local_wwattendance\attendance::records_for_user(
                $childid,
                (int) $term['from'],
                (int) $term['until']
            );
        }
        $rows = [];
        foreach (self::child_courses($childid) as $course) {
            if (!self::course_in_term($course, $term)) {
                continue;
            }
            $grade = self::grade_text($childid, $course);
            if ($grade === null) {
                continue;
            }
            $rows[] = [
                'course' => self::plain($course->fullname),
                'grade' => $grade,
                'attendance' => self::attendance_summary($records, (int) $course->id),
            ];
        }
        $terms = [];
        foreach (self::terms_for_child($childid, $term) as $candidate) {
            $terms[] = [
                'name' => $candidate['short'],
                'url' => (new \moodle_url('/local/wolfwhale/reportcard.php', [
                    'child' => $childid,
                    'term' => $candidate['id'],
                ]))->out(false),
                'current' => $candidate['id'] === $term['id'],
            ];
        }
        $picker = self::child_picker($childid, new \moodle_url('/local/wolfwhale/reportcard.php', [
            'term' => $term['id'],
        ]));
        $speech = [self::plain(fullname($child)) . '.', $term['label'] . '.'];
        if (!$rows) {
            $speech[] = get_string('none', 'local_wolfwhale') . '.';
        }
        foreach ($rows as $row) {
            $speech[] = $row['course'] . '. ' . $row['grade'] . '. ' . $row['attendance'] . '.';
        }
        return [
            'childname' => self::plain(fullname($child)),
            'termname' => $term['label'],
            'terms' => $terms,
            'rows' => $rows,
            'empty' => $rows === [],
            'none' => get_string('none', 'local_wolfwhale'),
            'print' => get_string('print', 'local_wolfwhale'),
            'read' => get_string('read', 'local_wolfwhale'),
            'screenspeech' => implode(' ', $speech),
            'several' => $picker['several'],
            'children' => $picker['children'],
        ];
    }

    /**
     * The two terms of one calendar year, in the viewer's time zone.
     *
     * Term 1 is January through June. Term 2 is July through December.
     *
     * @param int $year
     * @return array[]
     */
    public static function terms_for_year(int $year): array {
        $tz = \core_date::get_user_timezone_object();
        $from1 = (new \DateTimeImmutable($year . '-01-01 00:00:00', $tz))->getTimestamp();
        $from2 = (new \DateTimeImmutable($year . '-07-01 00:00:00', $tz))->getTimestamp();
        $next = (new \DateTimeImmutable(($year + 1) . '-01-01 00:00:00', $tz))->getTimestamp();
        return [
            [
                'id' => $year . '-1',
                'short' => get_string('term1', 'local_wolfwhale'),
                'label' => get_string('term1', 'local_wolfwhale') . ' ' . $year,
                'from' => $from1,
                'until' => $from2 - 1,
            ],
            [
                'id' => $year . '-2',
                'short' => get_string('term2', 'local_wolfwhale'),
                'label' => get_string('term2', 'local_wolfwhale') . ' ' . $year,
                'from' => $from2,
                'until' => $next - 1,
            ],
        ];
    }

    /**
     * The term that contains this moment.
     *
     * When the child has a school with saved terms, those dates are used.
     * The January-June and July-December split is used only when that school
     * has none.
     *
     * @param int|null $time
     * @param int|null $childid
     * @return array
     */
    public static function current_term(?int $time = null, ?int $childid = null): array {
        $time = $time ?? time();
        if ($childid) {
            $school = self::school_terms($childid);
            if ($school) {
                return self::term_containing($school, $time);
            }
        }
        $tz = \core_date::get_user_timezone_object();
        $year = (int) (new \DateTimeImmutable('@' . $time))->setTimezone($tz)->format('Y');
        return self::term_containing(self::terms_for_year($year), $time);
    }

    /**
     * A term id, or null when it is not one this child should see.
     *
     * A saved school term uses an id such as s12. A calendar term uses 2026-2.
     * Calendar ids are not returned once the child's school has saved terms.
     *
     * @param string $id
     * @param int|null $childid
     * @return array|null
     */
    public static function term_by_id(string $id, ?int $childid = null): ?array {
        if ($childid) {
            foreach (self::school_terms($childid) as $term) {
                if ($term['id'] === $id) {
                    return $term;
                }
            }
            if (self::school_terms($childid)) {
                return null;
            }
        } else if (preg_match('/^s(\d+)$/', $id, $matches)) {
            global $DB;
            $record = $DB->get_record('local_wwterms', ['id' => (int) $matches[1]]);
            return $record ? self::term_from_school($record) : null;
        }
        if (!preg_match('/^(\d{4})-([12])$/', $id, $matches)) {
            return null;
        }
        foreach (self::terms_for_year((int) $matches[1]) as $term) {
            if ($term['id'] === $id) {
                return $term;
            }
        }
        return null;
    }

    /**
     * Saved terms for this child's school, or the calendar split for the selected term.
     *
     * @param int $childid
     * @param array $selected
     * @return array[]
     */
    private static function terms_for_child(int $childid, array $selected): array {
        $school = self::school_terms($childid);
        if ($school) {
            return $school;
        }
        $year = (int) substr((string) ($selected['id'] ?? ''), 0, 4);
        if ($year < 2000) {
            $year = (int) (new \DateTimeImmutable('@' . time()))
                ->setTimezone(\core_date::get_user_timezone_object())
                ->format('Y');
        }
        return self::terms_for_year($year);
    }

    /**
     * Terms saved for the schools this child is enrolled in.
     *
     * A course in a child category uses terms saved on that category, or on
     * the nearest parent category that has terms. Another school's terms are
     * not included.
     *
     * @param int $childid
     * @return array[]
     */
    private static function school_terms(int $childid): array {
        global $DB;

        if (!class_exists(\local_wwterms\term::class)) {
            return [];
        }
        $found = [];
        $checked = [];
        foreach (self::child_courses($childid) as $course) {
            $category = $DB->get_record('course_categories', ['id' => $course->category], 'id, path');
            if (!$category) {
                continue;
            }
            $chain = array_reverse(array_filter(array_map('intval', explode('/', (string) $category->path))));
            foreach ($chain as $categoryid) {
                if (array_key_exists($categoryid, $checked)) {
                    if ($checked[$categoryid]) {
                        break;
                    }
                    continue;
                }
                $records = \local_wwterms\term::records($categoryid);
                $checked[$categoryid] = $records !== [];
                if (!$records) {
                    continue;
                }
                foreach ($records as $record) {
                    $found[(int) $record->id] = self::term_from_school($record);
                }
                break;
            }
        }
        $terms = array_values($found);
        usort($terms, static function (array $a, array $b): int {
            return $a['from'] <=> $b['from'] ?: strcmp($a['id'], $b['id']);
        });
        return $terms;
    }

    /**
     * One saved term in the shape the report already uses.
     *
     * @param \stdClass $record
     * @return array
     */
    private static function term_from_school(\stdClass $record): array {
        $name = trim((string) $record->name);
        return [
            'id' => 's' . (int) $record->id,
            'short' => $name,
            'label' => $name,
            'from' => (int) $record->timestart,
            'until' => (int) $record->timeend,
        ];
    }

    /**
     * The term that contains the moment, or the latest one that has already ended.
     *
     * @param array[] $terms
     * @param int $time
     * @return array
     */
    private static function term_containing(array $terms, int $time): array {
        $past = null;
        foreach ($terms as $term) {
            if ($time >= $term['from'] && $time <= $term['until']) {
                return $term;
            }
            if ($term['until'] < $time) {
                $past = $term;
            }
        }
        return $past ?? $terms[0];
    }

    /**
     * True when this person may open the link screen.
     *
     * @param int|null $userid
     * @return bool
     */
    public static function can_link(?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        if ($userid <= 0 || isguestuser($userid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        if (has_capability('local/wolfwhale:linkparent', \context_system::instance(), $userid)) {
            return true;
        }
        $courses = get_user_capability_course('local/wolfwhale:linkparent', $userid, false, '', 'id', 1);
        return !empty($courses);
    }

    /**
     * True when this person may link a parent to this child.
     *
     * A site manager may link any real person. A teacher may link a student
     * in a course where they hold the link capability.
     *
     * @param int $childid
     * @param int|null $userid
     * @return bool
     */
    public static function can_link_child(int $childid, ?int $userid = null): bool {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        if ($childid <= 0 || isguestuser($childid)) {
            return false;
        }
        if (!$DB->record_exists('user', ['id' => $childid, 'deleted' => 0])) {
            return false;
        }
        if (is_siteadmin($userid) || has_capability('local/wolfwhale:linkparent', \context_system::instance(), $userid)) {
            return true;
        }
        $courses = get_user_capability_course('local/wolfwhale:linkparent', $userid, false, '', 'id');
        if (!$courses) {
            return false;
        }
        $ids = [];
        foreach ($courses as $course) {
            $ids[] = (int) $course->id;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $params['child'] = $childid;
        $params['level'] = CONTEXT_COURSE;
        $params['student'] = 'student';
        $params['active'] = ENROL_USER_ACTIVE;
        $params['enabled'] = ENROL_INSTANCE_ENABLED;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user_enrolments} ue ON ue.userid = ra.userid
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ctx.instanceid
              WHERE ra.userid = :child
                AND ctx.contextlevel = :level
                AND ctx.instanceid {$insql}
                AND r.archetype = :student
                AND ue.status = :active
                AND e.status = :enabled",
            $params
        );
    }

    /**
     * Students this person may attach to a parent.
     *
     * @return \stdClass[]
     */
    public static function linkable_children(): array {
        global $DB, $USER;

        if (is_siteadmin() || has_capability('local/wolfwhale:linkparent', \context_system::instance())) {
            $courseids = $DB->get_fieldset_select('course', 'id', 'id <> ?', [SITEID]);
        } else {
            $courses = get_user_capability_course('local/wolfwhale:linkparent', (int) $USER->id, false, '', 'id');
            if (!$courses) {
                return [];
            }
            $courseids = [];
            foreach ($courses as $course) {
                $courseids[] = (int) $course->id;
            }
        }
        if (!$courseids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
        $params['level'] = CONTEXT_COURSE;
        $params['student'] = 'student';
        $params['active'] = ENROL_USER_ACTIVE;
        $params['enabled'] = ENROL_INSTANCE_ENABLED;
        return $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic,
                            u.lastnamephonetic, u.middlename, u.alternatename
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {role} r ON r.id = ra.roleid
               JOIN {user_enrolments} ue ON ue.userid = u.id
               JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = ctx.instanceid
              WHERE ctx.contextlevel = :level
                AND ctx.instanceid {$insql}
                AND r.archetype = :student
                AND u.deleted = 0
                AND ue.status = :active
                AND e.status = :enabled
           ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC",
            $params
        );
    }

    /**
     * Links this person is allowed to see and remove.
     *
     * @return array[]
     */
    public static function managed_links(): array {
        global $DB;

        $roleid = self::role_id();
        if (!$roleid) {
            return [];
        }
        $records = $DB->get_records_sql(
            "SELECT ra.id, ra.userid AS parentid, ctx.instanceid AS childid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.roleid = :roleid
                AND ctx.contextlevel = :level",
            ['roleid' => $roleid, 'level' => CONTEXT_USER]
        );
        $links = [];
        foreach ($records as $record) {
            $childid = (int) $record->childid;
            $parentid = (int) $record->parentid;
            if ($parentid === $childid || !self::can_link_child($childid)) {
                continue;
            }
            $parent = $DB->get_record('user', ['id' => $parentid, 'deleted' => 0]);
            $child = $DB->get_record('user', ['id' => $childid, 'deleted' => 0]);
            if (!$parent || !$child || isguestuser($parent) || isguestuser($child)) {
                continue;
            }
            $links[] = [
                'parentname' => self::plain(fullname($parent)),
                'childname' => self::plain(fullname($child)),
                'parentid' => $parentid,
                'childid' => $childid,
            ];
        }
        usort($links, function (array $a, array $b): int {
            return [$a['childname'], $a['parentname']] <=> [$b['childname'], $b['parentname']];
        });
        return $links;
    }

    /**
     * A real user with this username, or null.
     *
     * @param string $username
     * @return \stdClass|null
     */
    public static function user_by_username(string $username): ?\stdClass {
        global $CFG, $DB;

        $username = core_text::strtolower(trim($username));
        if ($username === '') {
            return null;
        }
        $user = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        if (!$user || isguestuser($user)) {
            return null;
        }
        return $user;
    }

    /**
     * Assign the parent role in the child's user context.
     *
     * @param int $parentid
     * @param int $childid
     */
    public static function link(int $parentid, int $childid): void {
        global $DB;

        if ($parentid <= 0 || $parentid === $childid || !self::can_link_child($childid)) {
            throw new \moodle_exception('cannotview', 'local_wolfwhale');
        }
        $parent = $DB->get_record('user', ['id' => $parentid, 'deleted' => 0]);
        $child = $DB->get_record('user', ['id' => $childid, 'deleted' => 0]);
        if (!$parent || !$child || isguestuser($parent) || isguestuser($child)) {
            throw new \moodle_exception('cannotview', 'local_wolfwhale');
        }
        $roleid = self::role_id();
        if (!$roleid) {
            self::install_role();
            $roleid = self::role_id();
        }
        if (!$roleid) {
            throw new \moodle_exception('cannotview', 'local_wolfwhale');
        }
        role_assign($roleid, $parentid, \context_user::instance($childid)->id);
    }

    /**
     * Remove the parent role from the child's user context.
     *
     * @param int $parentid
     * @param int $childid
     */
    public static function unlink(int $parentid, int $childid): void {
        if ($parentid <= 0 || !self::can_link_child($childid)) {
            throw new \moodle_exception('cannotview', 'local_wolfwhale');
        }
        $roleid = self::role_id();
        if (!$roleid) {
            return;
        }
        role_unassign($roleid, $parentid, \context_user::instance($childid)->id);
    }

    /**
     * Name buttons, shown only when there is more than one child.
     *
     * @param int $currentid
     * @param \moodle_url $base
     * @return array
     */
    public static function child_picker(int $currentid, \moodle_url $base): array {
        $children = self::children();
        if (count($children) < 2) {
            return ['several' => false, 'children' => []];
        }
        $out = [];
        foreach ($children as $child) {
            $url = new \moodle_url($base, ['child' => $child->id]);
            $out[] = [
                'name' => self::plain(fullname($child)),
                'url' => $url->out(false),
                'current' => (int) $child->id === $currentid,
            ];
        }
        return ['several' => true, 'children' => $out];
    }

    /**
     * Linked children of one person.
     *
     * @param int $userid
     * @return \stdClass[]
     */
    private static function children_of(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }
        $roleid = self::role_id();
        if (!$roleid) {
            return [];
        }
        return $DB->get_records_sql(
            "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic,
                    u.lastnamephonetic, u.middlename, u.alternatename
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
               JOIN {user} u ON u.id = ctx.instanceid
              WHERE ra.userid = :userid
                AND ra.roleid = :roleid
                AND ctx.contextlevel = :level
                AND u.deleted = 0
                AND u.id <> :self
           ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC",
            [
                'userid' => $userid,
                'roleid' => $roleid,
                'level' => CONTEXT_USER,
                'self' => $userid,
            ]
        );
    }

    /**
     * Active courses for one linked child, skipping hidden courses.
     *
     * @param int $childid
     * @return \stdClass[]
     */
    private static function child_courses(int $childid): array {
        self::require_linked($childid);
        $courses = enrol_get_users_courses(
            $childid,
            true,
            'id, fullname, shortname, showgrades, visible, startdate, enddate, category'
        );
        $kept = [];
        foreach ($courses as $course) {
            if ((int) $course->id === (int) SITEID) {
                continue;
            }
            if (empty($course->visible)) {
                $context = \context_course::instance($course->id);
                if (!has_capability('moodle/course:viewhiddencourses', $context)) {
                    continue;
                }
            }
            $kept[] = $course;
        }
        return $kept;
    }

    /**
     * The course total for this child, or null when this parent may not see it.
     *
     * @param int $childid
     * @param \stdClass $course
     * @return string|null
     */
    private static function grade_text(int $childid, \stdClass $course): ?string {
        if (empty($course->showgrades)) {
            return null;
        }
        self::load_grade_libs();
        $system = \context_system::instance();
        $personal = \context_user::instance($childid);
        $coursecontext = \context_course::instance($course->id);
        if (!\grade_report_overview::check_access($system, $coursecontext, $personal, $course, $childid)) {
            return null;
        }
        $grades = self::overview_course_grades($childid);
        if (!isset($grades[$course->id]) || $grades[$course->id]['finalgrade'] === null) {
            return get_string('none', 'local_wolfwhale');
        }
        $item = $grades[$course->id]['courseitem'];
        $text = self::plain(grade_format_gradevalue(
            (float) $grades[$course->id]['finalgrade'],
            $item,
            true,
            GRADE_DISPLAY_TYPE_REAL
        ));
        return $text === '' ? get_string('none', 'local_wolfwhale') : $text;
    }

    /**
     * Course totals for one child, after the grade report has removed hidden parts.
     *
     * The overview report blanks a total that contains a hidden mark, or rebuilds
     * it without those marks, using the course's own setting.
     *
     * @param int $childid
     * @return array
     */
    private static function overview_course_grades(int $childid): array {
        global $COURSE;

        if (empty($COURSE->id)) {
            $COURSE = get_course(SITEID);
        }
        $gpr = new \grade_plugin_return([
            'type' => 'report',
            'plugin' => 'overview',
            'courseid' => SITEID,
            'userid' => $childid,
            'groupid' => 0,
        ]);
        $report = new \grade_report_overview($childid, $gpr, \context_course::instance(SITEID));
        foreach ($report->courses as $course) {
            grade_regrade_final_grades($course->id);
        }
        return $report->setup_courses_data(true);
    }

    /**
     * Teachers of one linked child, limited to courses this parent may see.
     *
     * @param int $childid
     * @return \stdClass[] Keyed by user id. Each user has a courses list.
     */
    private static function teachers_of(int $childid): array {
        global $DB;

        $people = [];
        foreach (self::child_courses($childid) as $course) {
            $context = \context_course::instance($course->id);
            $contextids = [];
            $current = $context;
            while ($current && (int) $current->contextlevel >= CONTEXT_COURSECAT) {
                $contextids[] = $current->id;
                $current = $current->get_parent_context();
            }
            if (!$contextids) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
            $params['editing'] = 'editingteacher';
            $params['teacher'] = 'teacher';
            $users = $DB->get_records_sql(
                "SELECT DISTINCT u.id, u.firstname, u.lastname, u.firstnamephonetic,
                                u.lastnamephonetic, u.middlename, u.alternatename
                   FROM {user} u
                   JOIN {role_assignments} ra ON ra.userid = u.id
                   JOIN {role} r ON r.id = ra.roleid
                  WHERE ra.contextid {$insql}
                    AND r.archetype IN (:editing, :teacher)
                    AND u.deleted = 0",
                $params
            );
            foreach ($users as $user) {
                if ((int) $user->id === $childid) {
                    continue;
                }
                if (!isset($people[$user->id])) {
                    $user->courses = [];
                    $people[$user->id] = $user;
                }
                $people[$user->id]->courses[] = self::plain($course->fullname);
            }
        }
        return $people;
    }

    /**
     * A core message address, or null when this parent cannot write to this teacher.
     *
     * @param int $teacherid
     * @param int $parentid
     * @return string|null
     */
    private static function message_url(int $teacherid, int $parentid): ?string {
        if ($teacherid <= 0 || $teacherid === $parentid) {
            return null;
        }
        if (\core_message\api::is_blocked($teacherid, $parentid)) {
            return null;
        }
        if (!\core_message\api::can_send_message($teacherid, $parentid)) {
            if (!\core_message\api::is_contact($parentid, $teacherid)) {
                \core_message\api::add_contact($parentid, $teacherid);
            }
            if (!\core_message\api::can_send_message($teacherid, $parentid)) {
                return null;
            }
        }
        return (new \moodle_url('/message/index.php', ['id' => $teacherid]))->out(false);
    }

    /**
     * True when the course overlaps the term.
     *
     * @param \stdClass $course
     * @param array $term
     * @return bool
     */
    private static function course_in_term(\stdClass $course, array $term): bool {
        $start = (int) ($course->startdate ?? 0);
        $end = (int) ($course->enddate ?? 0);
        if ($start > 0 && $start > (int) $term['until']) {
            return false;
        }
        if ($end > 0 && $end < (int) $term['from']) {
            return false;
        }
        return true;
    }

    /**
     * Present, absent, and late counts for one course.
     *
     * @param \stdClass[] $records
     * @param int $courseid
     * @return string
     */
    private static function attendance_summary(array $records, int $courseid): string {
        if (!self::attendance_available()) {
            return get_string('none', 'local_wolfwhale');
        }
        $counts = ['present' => 0, 'absent' => 0, 'late' => 0];
        foreach ($records as $record) {
            if ((int) $record->courseid !== $courseid || !isset($counts[$record->status])) {
                continue;
            }
            $counts[$record->status]++;
        }
        return get_string('present', 'local_wolfwhale') . ' ' . $counts['present'] . ' '
            . get_string('absent', 'local_wolfwhale') . ' ' . $counts['absent'] . ' '
            . get_string('late', 'local_wolfwhale') . ' ' . $counts['late'];
    }

    /**
     * The word for a stored attendance status.
     *
     * @param string $status
     * @return string
     */
    private static function status_word(string $status): string {
        if (!in_array($status, ['present', 'absent', 'late'], true)) {
            return '';
        }
        return get_string($status, 'local_wolfwhale');
    }

    /**
     * True when the attendance plugin is installed and its table exists.
     *
     * @return bool
     */
    private static function attendance_available(): bool {
        global $DB;

        if (!class_exists(\local_wwattendance\attendance::class)) {
            return false;
        }
        return $DB->get_manager()->table_exists('local_wwattendance_log');
    }

    /**
     * Marks tile.
     *
     * @param int $childid
     * @return array
     */
    private static function marks_tile(int $childid): array {
        $rows = self::marks_rows($childid);
        $word = get_string('marks', 'local_wolfwhale');
        $url = (new \moodle_url('/local/wolfwhale/parentmarks.php', ['child' => $childid]))->out(false);
        $count = count($rows);
        if ($count === 0) {
            return self::tile('marks', $word, get_string('none', 'local_wolfwhale'), true, $url);
        }
        if ($count === 1) {
            $fact = (string) $rows[0]['fact'];
            return self::tile('marks', $word, $fact, $fact === get_string('none', 'local_wolfwhale'), $url);
        }
        return self::tile('marks', $word, (string) $count, false, $url);
    }

    /**
     * Attendance tile, or null when the plugin is not installed.
     *
     * @param int $childid
     * @return array|null
     */
    private static function attendance_tile(int $childid): ?array {
        if (!self::attendance_available()) {
            return null;
        }
        $rows = self::attendance_rows($childid);
        $word = get_string('attendance', 'local_wolfwhale');
        $url = (new \moodle_url('/local/wolfwhale/parentattendance.php', ['child' => $childid]))->out(false);
        $count = count($rows);
        if ($count === 0) {
            return self::tile('attendance', $word, get_string('none', 'local_wolfwhale'), true, $url);
        }
        if ($count === 1) {
            return self::tile('attendance', $word, (string) $rows[0]['fact'], false, $url);
        }
        return self::tile('attendance', $word, (string) $count, false, $url);
    }

    /**
     * Due tile.
     *
     * @param int $childid
     * @return array
     */
    private static function due_tile(int $childid): array {
        $rows = self::due_rows($childid);
        $word = get_string('due', 'local_wolfwhale');
        $url = (new \moodle_url('/local/wolfwhale/parentdue.php', ['child' => $childid]))->out(false);
        $count = count($rows);
        if ($count === 0) {
            return self::tile('due', $word, get_string('nothingdue', 'local_wolfwhale'), true, $url);
        }
        return self::tile('due', $word, (string) $count, false, $url);
    }

    /**
     * Badges tile, or null when badges are switched off.
     *
     * @param int $childid
     * @return array|null
     */
    private static function badges_tile(int $childid): ?array {
        global $CFG;

        if (empty($CFG->enablebadges)) {
            return null;
        }
        $rows = self::badge_rows($childid);
        $word = get_string('badges', 'local_wolfwhale');
        $url = (new \moodle_url('/local/wolfwhale/parentbadges.php', ['child' => $childid]))->out(false);
        $count = count($rows);
        if ($count === 0) {
            $tile = self::tile('badges', $word, get_string('none', 'local_wolfwhale'), true, $url);
        } else if ($count === 1) {
            $tile = self::tile('badges', $word, (string) $rows[0]['word'], false, $url);
        } else {
            $tile = self::tile('badges', $word, (string) $count, false, $url);
        }
        $total = \local_wwpoints\points::total($childid);
        $level = \local_wwpoints\levels::for_points($total);
        $tile['pointsword'] = get_string('points', 'local_wwpoints');
        $tile['points'] = (string) $total;
        $tile['levelword'] = get_string('level', 'local_wwpoints');
        $tile['level'] = (string) $level;
        $tile['speech'] .= ' ' . get_string('pointspeech', 'local_wwpoints', (object) [
            'points' => $total,
            'level' => $level,
        ]);
        return $tile;
    }

    /**
     * Messages tile, or null when messaging is switched off.
     *
     * @param int $childid
     * @return array|null
     */
    private static function messages_tile(int $childid): ?array {
        global $CFG;

        if (empty($CFG->messaging) || !has_capability('moodle/site:sendmessage', \context_system::instance())) {
            return null;
        }
        $rows = self::message_rows($childid);
        $word = get_string('messages', 'local_wolfwhale');
        $url = (new \moodle_url('/local/wolfwhale/parentmessages.php', ['child' => $childid]))->out(false);
        $count = count($rows);
        if ($count === 0) {
            return self::tile('messages', $word, get_string('none', 'local_wolfwhale'), true, $url);
        }
        return self::tile('messages', $word, (string) $count, false, $url);
    }

    /**
     * Report tile. The fact is the current term.
     *
     * @param int $childid
     * @return array
     */
    private static function report_tile(int $childid): array {
        self::require_linked($childid);
        $term = self::current_term(null, $childid);
        if (class_exists(\local_wwreports\card::class)) {
            $url = (new \moodle_url('/local/wwreports/card.php', [
                'student' => $childid,
                'term' => $term['id'],
            ]))->out(false);
        } else {
            $url = (new \moodle_url('/local/wolfwhale/reportcard.php', [
                'child' => $childid,
                'term' => $term['id'],
            ]))->out(false);
        }
        return self::tile('report', get_string('report', 'local_wolfwhale'), $term['short'], false, $url);
    }

    /**
     * The update switches, or null when that plugin is not installed.
     *
     * @return array|null
     */
    private static function updates_tile(): ?array {
        global $USER;

        if (!class_exists(\local_wwupdates\preferences::class)) {
            return null;
        }
        $on = \local_wwupdates\preferences::any_channel((int) $USER->id);
        $fact = get_string($on ? 'on' : 'off', 'local_wwupdates');
        $url = (new \moodle_url('/local/wwupdates/preferences.php'))->out(false);
        return self::tile('updates', get_string('updates', 'local_wwupdates'), $fact, !$on, $url);
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
        return [
            'id' => $id,
            'word' => $word,
            'fact' => $fact,
            'empty' => $empty,
            'url' => $url,
            'speech' => get_string('speech', 'local_wolfwhale', (object) ['word' => $word, 'fact' => $fact]),
            'ismarks' => $id === 'marks',
            'isattendance' => $id === 'attendance',
            'isdue' => $id === 'due',
            'isbadges' => $id === 'badges',
            'ismessages' => $id === 'messages',
            'isreport' => $id === 'report',
            'isupdates' => $id === 'updates',
        ];
    }

    /**
     * One list row.
     *
     * @param string $word
     * @param string $fact
     * @param string $course
     * @param string $url
     * @return array
     */
    private static function row(string $word, string $fact, string $course, string $url): array {
        $row = [
            'word' => $word,
            'fact' => $fact,
            'course' => $course,
        ];
        if ($url !== '') {
            $row['url'] = $url;
        }
        return $row;
    }

    /**
     * Load the grade libraries once.
     */
    private static function load_grade_libs(): void {
        global $CFG;

        static $loaded = false;
        if ($loaded) {
            return;
        }
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/grade/report/overview/lib.php');
        $loaded = true;
    }

    /**
     * A name for a tile and for speech, with no HTML.
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
        $path = self::path_inside_wwwroot($wantsurl);
        return in_array($path, ['/', '/index.php', '/my', '/my/index.php'], true);
    }

    /**
     * Path of a site address, without the wwwroot directory.
     *
     * @param string $url
     * @return string
     */
    private static function path_inside_wwwroot(string $url): string {
        global $CFG;

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        $root = parse_url($CFG->wwwroot, PHP_URL_PATH);
        if (is_string($root) && $root !== '' && $root !== '/' && str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }
        if ($path === '' || $path === '/') {
            return '/';
        }
        return rtrim($path, '/');
    }
}
