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

namespace local_wwreports;

/**
 * Term windows for a report.
 *
 * A saved school term is used when the category, or a parent category, has
 * one. Otherwise the year is split into January-June and July-December.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class terms {
    /**
     * Terms that apply to these courses.
     *
     * A course uses terms saved on its category, or on the nearest parent
     * category that has terms. Another school's terms are not included.
     *
     * @param \stdClass[] $courses
     * @return array[]
     */
    public static function for_courses(array $courses): array {
        global $DB;

        $found = [];
        $checked = [];
        foreach ($courses as $course) {
            $category = $DB->get_record('course_categories', ['id' => $course->category], 'id, path');
            if (!$category) {
                continue;
            }
            foreach (self::chain((string) $category->path) as $categoryid) {
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
                    $found[(int) $record->id] = self::from_record($record);
                }
                break;
            }
        }
        if (!$found) {
            return self::calendar();
        }
        $terms = array_values($found);
        usort($terms, static function (array $a, array $b): int {
            return $a['from'] <=> $b['from'] ?: strcmp($a['id'], $b['id']);
        });
        return $terms;
    }

    /**
     * Terms for one category, walking up to the nearest saved set.
     *
     * @param int $categoryid
     * @return array[]
     */
    public static function for_category(int $categoryid): array {
        global $DB;

        $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path');
        if (!$category) {
            return self::calendar();
        }
        foreach (self::chain((string) $category->path) as $id) {
            $records = \local_wwterms\term::records($id);
            if (!$records) {
                continue;
            }
            $terms = [];
            foreach ($records as $record) {
                $terms[] = self::from_record($record);
            }
            return $terms;
        }
        return self::calendar();
    }

    /**
     * The January-June and July-December terms for the current year.
     *
     * @param int|null $year
     * @return array[]
     */
    public static function calendar(?int $year = null): array {
        if ($year === null) {
            $year = (int) (new \DateTimeImmutable('now', \core_date::get_user_timezone_object()))->format('Y');
        }
        return \local_wolfwhale\parents::terms_for_year($year);
    }

    /**
     * The requested term, or the term that contains this moment.
     *
     * @param array[] $terms
     * @param string $id
     * @param int|null $time
     * @return array
     */
    public static function pick(array $terms, string $id, ?int $time = null): array {
        foreach ($terms as $term) {
            if ($term['id'] === $id) {
                return $term;
            }
        }
        return self::current($terms, $time);
    }

    /**
     * The term that contains the moment, or the latest one already finished.
     *
     * @param array[] $terms
     * @param int|null $time
     * @return array
     */
    public static function current(array $terms, ?int $time = null): array {
        $time = $time ?? time();
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
     * True when the course dates overlap the term.
     *
     * A course with no start or end overlaps every term.
     *
     * @param \stdClass $course
     * @param array $term
     * @return bool
     */
    public static function overlaps(\stdClass $course, array $term): bool {
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
     * Category ids from the category itself up to the top.
     *
     * @param string $path
     * @return int[]
     */
    private static function chain(string $path): array {
        return array_reverse(array_filter(array_map('intval', explode('/', $path))));
    }

    /**
     * One saved term.
     *
     * @param \stdClass $record
     * @return array
     */
    private static function from_record(\stdClass $record): array {
        $name = trim((string) $record->name);
        return [
            'id' => 's' . (int) $record->id,
            'short' => $name,
            'label' => $name,
            'from' => (int) $record->timestart,
            'until' => (int) $record->timeend,
        ];
    }
}
