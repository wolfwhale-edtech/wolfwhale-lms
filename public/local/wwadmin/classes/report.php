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
 * A plain summary of each school and term this person administers.
 *
 * The columns are the same counts as the school snapshot. A school with no
 * terms still has one row, with the term columns empty.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /**
     * Column headings.
     *
     * @return string[]
     */
    public static function headers(): array {
        return [
            get_string('col_school', 'local_wwadmin'),
            get_string('col_term', 'local_wwadmin'),
            get_string('col_termstart', 'local_wwadmin'),
            get_string('col_termend', 'local_wwadmin'),
            get_string('col_students', 'local_wwadmin'),
            get_string('col_teachers', 'local_wwadmin'),
            get_string('col_courses', 'local_wwadmin'),
            get_string('col_attendance', 'local_wwadmin'),
            get_string('col_overdue', 'local_wwadmin'),
        ];
    }

    /**
     * One row per school and term this person may see.
     *
     * @param int|null $userid
     * @return array[]
     */
    public static function rows(?int $userid = null): array {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $rows = [];
        foreach (scope::managed_root_ids($userid) as $categoryid) {
            $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name', MUST_EXIST);
            $terms = [];
            if (\local_wwterms\term::can_manage($categoryid, $userid)) {
                $terms = \local_wwterms\term::records($categoryid);
            }
            if (!$terms) {
                $rows[] = self::line($category->name, null, snapshot::for_category($categoryid));
                continue;
            }
            foreach ($terms as $term) {
                $counts = snapshot::for_category($categoryid, (int) $term->timestart, (int) $term->timeend);
                $rows[] = self::line($category->name, $term, $counts);
            }
        }
        return $rows;
    }

    /**
     * Send the summary as a CSV file and stop.
     */
    public static function download(): void {
        scope::require_admin();
        $writer = new \csv_export_writer();
        $writer->set_filename('school-report');
        $writer->add_data(self::headers());
        foreach (self::rows() as $row) {
            $writer->add_data($row);
        }
        $writer->download_file();
    }

    /**
     * One CSV row.
     *
     * @param string $school
     * @param \stdClass|null $term
     * @param \stdClass $counts
     * @return array
     */
    private static function line(string $school, ?\stdClass $term, \stdClass $counts): array {
        return [
            $school,
            $term->name ?? '',
            $term ? self::day((int) $term->timestart) : '',
            $term ? self::day((int) $term->timeend) : '',
            (string) $counts->students,
            (string) $counts->teachers,
            (string) $counts->courses,
            snapshot::format_rate($counts->attendance),
            (string) $counts->overdue,
        ];
    }

    /**
     * A calendar day in the site timezone.
     *
     * @param int $time
     * @return string
     */
    public static function day(int $time): string {
        $date = new \DateTime('@' . $time);
        $date->setTimezone(new \DateTimeZone(\core_date::get_server_timezone()));
        return $date->format('Y-m-d');
    }
}
