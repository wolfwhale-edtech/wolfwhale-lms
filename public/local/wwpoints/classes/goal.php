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

namespace local_wwpoints;

/**
 * A class goal or a school goal.
 *
 * A class goal counts points whose course is that class. A school goal counts
 * points whose course is in that category or a category under it. Points from
 * any other category are not counted.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class goal {
    /**
     * Save the one goal for a course this person teaches.
     *
     * @param int $courseid
     * @param string $name
     * @param int $target
     * @param int $timeend
     */
    public static function save_course(int $courseid, string $name, int $target, int $timeend): void {
        self::require_course($courseid);
        self::store($courseid, 0, $name, $target, $timeend);
    }

    /**
     * Save the one goal for a category this person administers.
     *
     * @param int $categoryid
     * @param string $name
     * @param int $target
     * @param int $timeend
     */
    public static function save_category(int $categoryid, string $name, int $target, int $timeend): void {
        global $USER;

        $context = \context_coursecat::instance($categoryid);
        require_capability('local/wwpoints:setcategorygoal', $context);
        \local_wwnation\nation::require_category((int) $USER->id, $categoryid);
        self::store(0, $categoryid, $name, $target, $timeend);
    }

    /**
     * The goal for one course, if one has been saved.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function for_course(int $courseid): ?\stdClass {
        global $DB;

        if ($courseid <= 0) {
            return null;
        }
        $goal = $DB->get_record('local_wwpoints_goal', ['courseid' => $courseid, 'categoryid' => 0]);
        return $goal ?: null;
    }

    /**
     * The goal for one category, if one has been saved.
     *
     * @param int $categoryid
     * @return \stdClass|null
     */
    public static function for_category(int $categoryid): ?\stdClass {
        global $DB;

        if ($categoryid <= 0) {
            return null;
        }
        $goal = $DB->get_record('local_wwpoints_goal', ['courseid' => 0, 'categoryid' => $categoryid]);
        return $goal ?: null;
    }

    /**
     * Progress bars for the courses this person is enrolled in.
     *
     * A school goal is included when the course sits in that category or under it.
     *
     * @param int $userid
     * @return array[]
     */
    public static function bars_for_user(int $userid): array {
        $courses = enrol_get_users_courses($userid, true, 'id, category');
        return self::bars_for_courses($courses);
    }

    /**
     * Progress bars for the courses this teacher teaches.
     *
     * @param int $userid
     * @return array[]
     */
    public static function bars_for_teacher(int $userid): array {
        global $DB;

        $ids = points::teaching_ids($userid);
        if (!$ids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $courses = $DB->get_records_select('course', "id {$insql}", $params, '', 'id, category');
        return self::bars_for_courses($courses);
    }

    /**
     * One goal drawn as a name and a bar.
     *
     * @param \stdClass $goal
     * @return array
     */
    public static function progress(\stdClass $goal): array {
        return self::bar($goal);
    }

    /**
     * A stored end time as Y-m-d in the site timezone.
     *
     * @param int $timeend
     * @return string
     */
    public static function day_value(int $timeend): string {
        $date = new \DateTime('@' . $timeend);
        $date->setTimezone(new \DateTimeZone(\core_date::get_server_timezone()));
        return $date->format('Y-m-d');
    }

    /**
     * End of a Y-m-d day in the site timezone, or 0 when the day is not valid.
     *
     * @param string $day
     * @return int
     */
    public static function day_end(string $day): int {
        $day = trim($day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return 0;
        }
        $date = \DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $day . ' 23:59:59',
            new \DateTimeZone(\core_date::get_server_timezone())
        );
        if (!$date) {
            return 0;
        }
        return $date->getTimestamp();
    }

    /**
     * A category and every category under it.
     *
     * @param int $categoryid
     * @return int[]
     */
    public static function category_tree(int $categoryid): array {
        global $DB;

        $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path', IGNORE_MISSING);
        if (!$cat) {
            return [];
        }
        $ids = [];
        $prefix = $cat->path . '/';
        foreach ($DB->get_records('course_categories', null, '', 'id, path') as $row) {
            if ((int) $row->id === $categoryid || str_starts_with($row->path, $prefix)) {
                $ids[] = (int) $row->id;
            }
        }
        return $ids;
    }

    /**
     * Goals for a school export: class goals in these courses, and school goals
     * on these categories.
     *
     * @param int[] $courseids
     * @param int[] $categoryids
     * @return array[]
     */
    public static function export_rows(array $courseids, array $categoryids): array {
        global $DB;

        $courseids = array_values(array_filter(array_map('intval', $courseids)));
        $categoryids = array_values(array_filter(array_map('intval', $categoryids)));
        $where = [];
        $params = [];
        if ($courseids) {
            [$csql, $cparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'c');
            $where[] = "(courseid {$csql} AND categoryid = 0)";
            $params += $cparams;
        }
        if ($categoryids) {
            [$asql, $aparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'a');
            $where[] = "(categoryid {$asql} AND courseid = 0)";
            $params += $aparams;
        }
        if (!$where) {
            return [];
        }
        $records = $DB->get_records_select(
            'local_wwpoints_goal',
            implode(' OR ', $where),
            $params,
            'id ASC'
        );
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'courseid' => (int) $record->courseid,
                'categoryid' => (int) $record->categoryid,
                'name' => $record->name,
                'target' => (int) $record->target,
                'timeend' => (int) $record->timeend,
                'createdby' => (int) $record->createdby,
            ];
        }
        return $rows;
    }

    /**
     * Refuse unless this person may set a goal in this course.
     *
     * @param int $courseid
     * @return \context_course
     */
    public static function require_course(int $courseid): \context_course {
        global $USER;

        $context = \context_course::instance($courseid);
        require_capability('local/wwpoints:setgoal', $context);
        if (!is_siteadmin() && !in_array($courseid, points::teaching_ids((int) $USER->id), true)) {
            throw new \moodle_exception('invalidgoal', 'local_wwpoints');
        }
        return $context;
    }

    /**
     * @param \stdClass[] $courses Objects with id and category.
     * @return array[]
     */
    private static function bars_for_courses(array $courses): array {
        $bars = [];
        $seen = [];
        foreach ($courses as $course) {
            $courseid = (int) $course->id;
            if ($courseid > 0 && !isset($seen['c' . $courseid])) {
                $seen['c' . $courseid] = true;
                $goal = self::for_course($courseid);
                if ($goal) {
                    $bars[] = self::bar($goal);
                }
            }
            foreach (self::ancestors((int) $course->category) as $categoryid) {
                if (isset($seen['a' . $categoryid])) {
                    continue;
                }
                $seen['a' . $categoryid] = true;
                $goal = self::for_category($categoryid);
                if ($goal) {
                    $bars[] = self::bar($goal);
                }
            }
        }
        return $bars;
    }

    /**
     * The category and its parents. Children and siblings are not included.
     *
     * @param int $categoryid
     * @return int[]
     */
    private static function ancestors(int $categoryid): array {
        global $DB;

        if ($categoryid <= 0) {
            return [];
        }
        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if (!is_string($path) || $path === '') {
            return [];
        }
        $ids = [];
        foreach (explode('/', trim($path, '/')) as $id) {
            if ($id !== '') {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * @param \stdClass $goal
     * @return array
     */
    private static function bar(\stdClass $goal): array {
        if ((int) $goal->courseid > 0) {
            $total = points::sum_courses([(int) $goal->courseid], (int) $goal->timeend);
        } else {
            $tree = self::category_tree((int) $goal->categoryid);
            $total = points::sum_courses(self::courses_in($tree), (int) $goal->timeend);
        }
        $target = max(1, (int) $goal->target);
        $percent = (int) min(100, max(0, (int) floor($total * 100 / $target)));
        $name = points::plain($goal->name);
        return [
            'name' => $name,
            'percent' => $percent,
            'speech' => get_string('goalspeech', 'local_wwpoints', (object) [
                'name' => $name,
                'total' => $total,
                'target' => (int) $goal->target,
            ]),
        ];
    }

    /**
     * @param int[] $categoryids
     * @return int[]
     */
    private static function courses_in(array $categoryids): array {
        global $DB;

        if (!$categoryids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($categoryids);
        $ids = $DB->get_fieldset_select('course', 'id', "category {$insql}", $params);
        return array_map('intval', $ids);
    }

    /**
     * @param int $courseid
     * @param int $categoryid
     * @param string $name
     * @param int $target
     * @param int $timeend
     */
    private static function store(int $courseid, int $categoryid, string $name, int $target, int $timeend): void {
        global $DB, $USER;

        $name = trim(clean_param($name, PARAM_TEXT));
        $name = \core_text::substr($name, 0, 255);
        if ($name === '' || $target < 1 || $timeend <= time()) {
            throw new \moodle_exception('invalidgoal', 'local_wwpoints');
        }
        $now = time();
        $existing = $DB->get_record('local_wwpoints_goal', [
            'courseid' => $courseid,
            'categoryid' => $categoryid,
        ]);
        if ($existing) {
            $existing->name = $name;
            $existing->target = $target;
            $existing->timeend = $timeend;
            $existing->createdby = (int) $USER->id;
            $existing->timemodified = $now;
            $DB->update_record('local_wwpoints_goal', $existing);
            return;
        }
        $DB->insert_record('local_wwpoints_goal', (object) [
            'courseid' => $courseid,
            'categoryid' => $categoryid,
            'name' => $name,
            'target' => $target,
            'timeend' => $timeend,
            'createdby' => (int) $USER->id,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
