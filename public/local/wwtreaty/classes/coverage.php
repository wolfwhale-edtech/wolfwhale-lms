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

namespace local_wwtreaty;

/**
 * Which outcomes are tagged, which are met, and who still needs coverage.
 *
 * A term limits evidence by the time it was recorded. Tags themselves are
 * not dated. With no term selected, a term that contains today is used when
 * the school has saved one.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coverage {
    /**
     * Coverage for a course this person may see.
     *
     * @param int $courseid
     * @param int|null $termid Null uses the term that contains today, or all evidence.
     * @return array
     */
    public static function summary(int $courseid, ?int $termid = null): array {
        school::require_view($courseid);
        return self::calculate($courseid, $termid);
    }

    /**
     * Coverage numbers. The caller has already checked view.
     *
     * @param int $courseid
     * @param int|null $termid
     * @return array
     */
    public static function calculate(int $courseid, ?int $termid = null): array {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');
        self::reconcile_auto($courseid);
        $schoolid = school::school_for_course($courseid);
        [$start, $end, $usedterm] = self::window($schoolid, $termid);
        $students = self::students($courseid);
        $studentids = array_map('intval', array_keys($students));
        $outcomes = outcome::records($schoolid, true);
        $tags = $DB->get_records('local_wwtreaty_tag', ['courseid' => $courseid]);
        $live = [];
        foreach (tag::activities($courseid) as $cm) {
            $live[$cm->id] = $cm;
        }
        $lessons = [];
        foreach ($tags as $tag) {
            if (!isset($live[(int) $tag->cmid])) {
                continue;
            }
            $lessons[(int) $tag->outcomeid] = ($lessons[(int) $tag->outcomeid] ?? 0) + 1;
        }

        $met = [];
        if ($studentids) {
            [$insql, $params] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED);
            $params['courseid'] = $courseid;
            $params['complete'] = COMPLETION_COMPLETE;
            $params['pass'] = COMPLETION_COMPLETE_PASS;
            $finished = [];
            $statesql = "SELECT cmc.id, cmc.coursemoduleid, cmc.userid
                           FROM {course_modules_completion} cmc
                           JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                          WHERE cm.course = :courseid
                            AND cmc.completionstate IN (:complete, :pass)
                            AND cmc.userid {$insql}";
            foreach ($DB->get_records_sql($statesql, $params) as $state) {
                $finished[(int) $state->coursemoduleid . ':' . (int) $state->userid] = true;
            }
            unset($params['complete'], $params['pass']);
            $sql = "SELECT id, outcomeid, userid, timecreated, cmid, termid, usermodified
                      FROM {local_wwtreaty_evidence}
                     WHERE courseid = :courseid
                       AND userid {$insql}";
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $when = (int) $row->timecreated;
                $automatic = (int) $row->usermodified === 0 && (int) $row->cmid > 0;
                if ($automatic && empty($finished[(int) $row->cmid . ':' . (int) $row->userid])) {
                    continue;
                }
                $inwindow = !$start || ($when >= $start && $when <= $end);
                $forthisterm = $usedterm && (int) $row->cmid === 0 && (int) $row->termid === $usedterm;
                if (!$inwindow && !$forthisterm) {
                    continue;
                }
                $met[(int) $row->outcomeid . ':' . (int) $row->userid] = true;
            }
        }

        $rows = [];
        $gaps = 0;
        $missing = [];
        foreach ($outcomes as $outcome) {
            $id = (int) $outcome->id;
            $tagged = !empty($lessons[$id]);
            $metcount = 0;
            if ($tagged) {
                foreach ($studentids as $userid) {
                    if (!empty($met[$id . ':' . $userid])) {
                        $metcount++;
                        continue;
                    }
                    $missing[$userid][] = ['id' => $id, 'code' => $outcome->code];
                }
            }
            $studentcount = count($studentids);
            if (!$tagged) {
                $status = 'open';
                $needs = 0;
                $percent = 0;
            } else if ($studentcount === 0 || $metcount === $studentcount) {
                $status = 'met';
                $needs = 0;
                $percent = 100;
            } else {
                $status = 'gap';
                $needs = $studentcount - $metcount;
                $percent = (int) floor(100 * $metcount / $studentcount);
            }
            if ($status !== 'met') {
                $gaps++;
            }
            $rows[] = [
                'id' => $id,
                'code' => $outcome->code,
                'title' => $outcome->title,
                'grade' => $outcome->grade,
                'theme' => $outcome->theme,
                'tagged' => $tagged,
                'lessons' => $lessons[$id] ?? 0,
                'students' => $studentcount,
                'met' => $metcount,
                'needs' => $needs,
                'status' => $status,
                'percent' => $percent,
            ];
        }

        $needs = [];
        foreach ($missing as $userid => $items) {
            $needs[] = [
                'userid' => (int) $userid,
                'name' => fullname($students[$userid]),
                'codes' => array_column($items, 'code'),
                'outcomeid' => (int) $items[0]['id'],
            ];
        }
        usort($needs, static function ($a, $b) {
            return [$a['name'], $a['userid']] <=> [$b['name'], $b['userid']];
        });

        return [
            'courseid' => $courseid,
            'schoolid' => $schoolid,
            'termid' => $usedterm,
            'gaps' => $gaps,
            'outcomes' => $rows,
            'needs' => $needs,
        ];
    }

    /**
     * Record that a teacher saw evidence for one student.
     *
     * The outcome has to be tagged in this course, and the student has to be
     * one this teacher is allowed to see.
     *
     * @param int $courseid
     * @param int $outcomeid
     * @param int $userid
     * @param int $termid A later term can be recorded again. The earlier mark stays.
     */
    public static function meet(int $courseid, int $outcomeid, int $userid, int $termid = 0): void {
        global $DB, $USER;

        school::require_tag($courseid);
        $students = self::students($courseid);
        if (!isset($students[$userid])) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $schoolid = school::school_for_course($courseid);
        $outcome = $DB->get_record('local_wwtreaty', [
            'id' => $outcomeid,
            'categoryid' => $schoolid,
            'active' => 1,
        ], '*', IGNORE_MISSING);
        if (!$outcome) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        if (!$DB->record_exists('local_wwtreaty_tag', ['outcomeid' => $outcomeid, 'courseid' => $courseid])) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        $start = 0;
        $end = 0;
        if ($termid > 0) {
            $term = $DB->get_record('local_wwterms', ['id' => $termid, 'categoryid' => $schoolid]);
            if (!$term) {
                throw new \moodle_exception('invalidrecord', 'error');
            }
            $start = (int) $term->timestart;
            $end = (int) $term->timeend;
        } else {
            $termid = 0;
        }
        $manual = $DB->get_records('local_wwtreaty_evidence', [
            'outcomeid' => $outcomeid,
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => 0,
        ]);
        foreach ($manual as $row) {
            if ($termid > 0 && (int) $row->termid === $termid) {
                return;
            }
            $when = (int) $row->timecreated;
            if ($termid === 0 || ($when >= $start && $when <= $end)) {
                return;
            }
        }
        self::store($outcomeid, $userid, $courseid, 0, (int) $USER->id, $termid);
    }

    /**
     * Store one evidence row. A second row for the same slot is ignored.
     *
     * A cmid of 0 is a mark recorded by a teacher. Automatic evidence uses the
     * activity id and a modifier of 0, so clearing completion can remove it.
     *
     * @param int $outcomeid
     * @param int $userid
     * @param int $courseid
     * @param int $cmid
     * @param int $editor
     * @param int $termid
     */
    public static function store(
        int $outcomeid,
        int $userid,
        int $courseid,
        int $cmid,
        int $editor,
        int $termid = 0
    ): void {
        global $DB;

        if ($outcomeid <= 0 || $userid <= 0 || $courseid <= 0 || $cmid < 0) {
            return;
        }
        $existing = $DB->get_record('local_wwtreaty_evidence', [
            'outcomeid' => $outcomeid,
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'termid' => $termid,
        ]);
        if ($existing) {
            return;
        }
        $DB->insert_record('local_wwtreaty_evidence', (object) [
            'outcomeid' => $outcomeid,
            'userid' => $userid,
            'courseid' => $courseid,
            'cmid' => $cmid,
            'termid' => $termid,
            'usermodified' => $editor,
            'timecreated' => time(),
        ]);
    }

    /**
     * Drop automatic evidence whose activity is no longer complete.
     *
     * A course reset deletes the completion rows without an event for each
     * activity. A teacher's own mark is left alone.
     *
     * @param int $courseid
     */
    public static function reconcile_auto(int $courseid): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/completionlib.php');
        $rows = $DB->get_records_select(
            'local_wwtreaty_evidence',
            'courseid = :courseid AND cmid > 0 AND usermodified = 0',
            ['courseid' => $courseid]
        );
        foreach ($rows as $row) {
            $state = $DB->get_field('course_modules_completion', 'completionstate', [
                'coursemoduleid' => $row->cmid,
                'userid' => $row->userid,
            ]);
            if ((int) $state === COMPLETION_COMPLETE || (int) $state === COMPLETION_COMPLETE_PASS) {
                continue;
            }
            $DB->delete_records('local_wwtreaty_evidence', ['id' => $row->id]);
        }
    }

    /**
     * Remove automatic evidence for one activity. A teacher's own mark stays.
     *
     * @param int $userid
     * @param int $cmid
     */
    public static function clear_auto(int $userid, int $cmid): void {
        global $DB;

        if ($userid <= 0 || $cmid <= 0) {
            return;
        }
        $DB->delete_records('local_wwtreaty_evidence', [
            'userid' => $userid,
            'cmid' => $cmid,
            'usermodified' => 0,
        ]);
    }

    /**
     * Enrolled students in the course.
     *
     * In separate groups, a teacher who cannot see every group only sees the
     * groups they belong to.
     *
     * @param int $courseid
     * @return \stdClass[] Keyed by user id.
     */
    public static function students(int $courseid): array {
        global $DB, $USER;

        $context = school::require_view($courseid);
        $now = \core\di::get(\core\clock::class)->time();
        $users = $DB->get_records_sql(
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
                AND u.suspended = 0
                AND ue.status = :active
                AND ue.timestart < :now1
                AND (ue.timeend = 0 OR ue.timeend > :now2)
                AND e.status = :enabled
           ORDER BY u.lastname ASC, u.firstname ASC",
            [
                'level' => CONTEXT_COURSE,
                'courseid' => $courseid,
                'student' => 'student',
                'active' => ENROL_USER_ACTIVE,
                'enabled' => ENROL_INSTANCE_ENABLED,
                'now1' => $now,
                'now2' => $now,
            ]
        );
        $course = get_course($courseid);
        $separate = (int) $course->groupmode === SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $context);
        $allowed = [];
        if ($separate) {
            foreach (groups_get_all_groups($courseid, (int) $USER->id) as $group) {
                foreach (groups_get_members($group->id, 'u.id') as $member) {
                    $allowed[(int) $member->id] = true;
                }
            }
        }
        $kept = [];
        foreach ($users as $user) {
            if (has_capability('local/wwtreaty:tag', $context, $user->id)) {
                continue;
            }
            if ($separate && empty($allowed[(int) $user->id])) {
                continue;
            }
            $kept[(int) $user->id] = $user;
        }
        return $kept;
    }

    /**
     * The date window, and the term id that produced it. A zero start means all evidence.
     *
     * @param int $schoolid
     * @param int|null $termid
     * @return int[]
     */
    private static function window(int $schoolid, ?int $termid): array {
        global $DB;

        if ($termid === 0) {
            return [0, 0, 0];
        }
        if ($termid === null) {
            $now = time();
            $terms = $DB->get_records('local_wwterms', ['categoryid' => $schoolid], 'timestart ASC, id ASC');
            foreach ($terms as $term) {
                if ((int) $term->timestart <= $now && $now <= (int) $term->timeend) {
                    return [(int) $term->timestart, (int) $term->timeend, (int) $term->id];
                }
            }
            return [0, 0, 0];
        }
        $term = $DB->get_record('local_wwterms', ['id' => $termid, 'categoryid' => $schoolid]);
        if (!$term) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        return [(int) $term->timestart, (int) $term->timeend, (int) $term->id];
    }
}
