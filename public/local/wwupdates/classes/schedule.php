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

namespace local_wwupdates;

/**
 * The day and hour a school sends the weekly summary.
 *
 * The clock is the site clock. Until a school saves a time, it is Monday at 08:00.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule {
    /** @var string[] PHP date('w') index to a calendar string. */
    private const DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * Schools this person may set.
     *
     * @param int|null $userid
     * @return int[]
     */
    public static function roots(?int $userid = null): array {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        if (is_siteadmin($userid)) {
            return array_map('intval', $DB->get_fieldset_select('course_categories', 'id', 'parent = 0', []));
        }
        if (class_exists(\local_wwadmin\scope::class)) {
            return \local_wwadmin\scope::managed_root_ids($userid);
        }
        $ids = [];
        foreach ($DB->get_fieldset_select('course_categories', 'id', 'parent = 0', []) as $id) {
            if (self::can((int) $id, $userid)) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * True when this person may set this school's day and hour.
     *
     * @param int $categoryid
     * @param int|null $userid
     * @return bool
     */
    public static function can(int $categoryid, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        if (!self::is_root($categoryid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        if (class_exists(\local_wwadmin\scope::class)) {
            return in_array($categoryid, \local_wwadmin\scope::managed_root_ids($userid), true);
        }
        return has_capability('moodle/category:manage', \context_coursecat::instance($categoryid), $userid);
    }

    /**
     * The saved day and hour, or Monday at 08:00.
     *
     * @param int $categoryid
     * @return \stdClass
     */
    public static function get(int $categoryid): \stdClass {
        global $DB;

        $row = $DB->get_record('local_wwupdates_schedule', ['categoryid' => $categoryid]);
        if (!$row) {
            return (object) ['categoryid' => $categoryid, 'weekday' => 1, 'hour' => 8];
        }
        $row->weekday = (int) $row->weekday;
        $row->hour = (int) $row->hour;
        return $row;
    }

    /**
     * Save a day and hour for a school this person administers.
     *
     * @param int $categoryid
     * @param int $weekday
     * @param int $hour
     */
    public static function save(int $categoryid, int $weekday, int $hour): void {
        if (!self::can($categoryid)) {
            throw new \moodle_exception('cannotview', 'local_wwupdates');
        }
        self::store($categoryid, $weekday, $hour);
    }

    /**
     * True once the site clock has reached today's slot.
     *
     * @param int $categoryid
     * @return bool
     */
    public static function ready(int $categoryid): bool {
        $saved = self::get($categoryid);
        $now = self::now();
        if ((int) $now->format('w') !== (int) $saved->weekday) {
            return false;
        }
        return (int) $now->format('G') >= (int) $saved->hour;
    }

    /**
     * One key for the week, the site date of the day the summary goes out.
     *
     * @return string
     */
    public static function week_key(): string {
        return 'weekly:' . self::now()->format('Ymd');
    }

    /**
     * A short label, such as Monday 08:00.
     *
     * @param int $categoryid
     * @return string
     */
    public static function label(int $categoryid): string {
        $saved = self::get($categoryid);
        return self::day_name((int) $saved->weekday) . ' ' . sprintf('%02d:00', (int) $saved->hour);
    }

    /**
     * Day choices for a select.
     *
     * @param int $selected
     * @return array[]
     */
    public static function day_options(int $selected): array {
        $out = [];
        foreach (self::DAYS as $value => $key) {
            $out[] = [
                'value' => $value,
                'label' => self::day_name($value),
                'selected' => $value === $selected,
            ];
        }
        return $out;
    }

    /**
     * Hour choices for a select.
     *
     * @param int $selected
     * @return array[]
     */
    public static function hour_options(int $selected): array {
        $out = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $out[] = [
                'value' => $hour,
                'label' => sprintf('%02d:00', $hour),
                'selected' => $hour === $selected,
            ];
        }
        return $out;
    }

    /**
     * Write the row.
     *
     * @param int $categoryid
     * @param int $weekday
     * @param int $hour
     */
    private static function store(int $categoryid, int $weekday, int $hour): void {
        global $DB;

        $weekday = max(0, min(6, $weekday));
        $hour = max(0, min(23, $hour));
        $existing = $DB->get_record('local_wwupdates_schedule', ['categoryid' => $categoryid]);
        if ($existing) {
            $existing->weekday = $weekday;
            $existing->hour = $hour;
            $DB->update_record('local_wwupdates_schedule', $existing);
            return;
        }
        $DB->insert_record('local_wwupdates_schedule', (object) [
            'categoryid' => $categoryid,
            'weekday' => $weekday,
            'hour' => $hour,
        ]);
    }

    /**
     * A day name.
     *
     * @param int $weekday
     * @return string
     */
    private static function day_name(int $weekday): string {
        $key = self::DAYS[$weekday] ?? 'monday';
        return get_string($key, 'calendar');
    }

    /**
     * True for a top-level category.
     *
     * @param int $categoryid
     * @return bool
     */
    private static function is_root(int $categoryid): bool {
        global $DB;

        if ($categoryid <= 0) {
            return false;
        }
        return $DB->record_exists('course_categories', ['id' => $categoryid, 'parent' => 0]);
    }

    /**
     * Now, in the site timezone.
     *
     * @return \DateTimeImmutable
     */
    private static function now(): \DateTimeImmutable {
        return new \DateTimeImmutable('now', \core_date::get_server_timezone_object());
    }
}
