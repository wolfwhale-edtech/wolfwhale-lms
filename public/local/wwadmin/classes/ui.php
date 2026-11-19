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
 * Shared page pieces for the admin screens.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui {
    /**
     * The one school to open, or null when the person must pick.
     *
     * @param string $param
     * @return int|null
     */
    public static function category_param(string $param = 'category'): ?int {
        $categoryid = optional_param($param, 0, PARAM_INT);
        $roots = scope::managed_root_ids((int) $GLOBALS['USER']->id);
        if ($categoryid) {
            scope::require($categoryid);
            return $categoryid;
        }
        if (count($roots) === 1) {
            return $roots[0];
        }
        return null;
    }

    /**
     * Big links for each school.
     *
     * @param \moodle_url $base
     * @return array[]
     */
    public static function school_rows(\moodle_url $base): array {
        global $DB, $USER;

        $rows = [];
        foreach (scope::managed_root_ids((int) $USER->id) as $id) {
            $category = $DB->get_record('course_categories', ['id' => $id], 'id, name', MUST_EXIST);
            $rows[] = [
                'id' => $id,
                'word' => format_string($category->name, true, [
                    'context' => \context_coursecat::instance($id),
                    'escape' => false,
                ]),
                'url' => (new \moodle_url($base, ['category' => $id]))->out(false),
            ];
        }
        return $rows;
    }

    /**
     * Print a list page.
     *
     * @param string $titlekey
     * @param \moodle_url $url
     * @param array $data
     * @param \context|null $context
     */
    public static function render(string $titlekey, \moodle_url $url, array $data, ?\context $context = null): void {
        global $OUTPUT;

        home::setup($titlekey, $url, $context);
        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template('local_wwadmin/page', $data);
        echo $OUTPUT->footer();
    }

    /**
     * A calendar day turned into a timestamp in the site timezone.
     *
     * @param string $day Y-m-d
     * @param bool $end True for the end of that day.
     * @return int
     */
    public static function day_stamp(string $day, bool $end): int {
        $day = trim($day);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            return 0;
        }
        $time = $end ? '23:59:59' : '00:00:00';
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $day . ' ' . $time, new \DateTimeZone(\core_date::get_server_timezone()));
        if (!$date) {
            return 0;
        }
        return $date->getTimestamp();
    }
}
