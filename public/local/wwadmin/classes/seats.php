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
 * Seat count compared with active people.
 *
 * The number of seats is entered by a site administrator. No price is stored.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats {
    /**
     * Active people in one school.
     *
     * @param int $categoryid
     * @return int
     */
    public static function active_count(int $categoryid): int {
        scope::require($categoryid, 'local/wwadmin:view');
        $count = 0;
        foreach (scope::users(scope::tree_ids($categoryid)) as $user) {
            if (!(int) $user->suspended) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * The entered seat count, or null when none has been entered.
     *
     * @param int $categoryid
     * @return int|null
     */
    public static function count(int $categoryid): ?int {
        global $DB;

        scope::require($categoryid, 'local/wwadmin:view');
        $record = $DB->get_record('local_wwadmin_seat', ['categoryid' => $categoryid]);
        if (!$record) {
            return null;
        }
        return (int) $record->seats;
    }

    /**
     * Store a seat count. Only a site administrator may do this.
     *
     * @param int $categoryid
     * @param int $seatcount
     */
    public static function set(int $categoryid, int $seatcount): void {
        global $DB, $USER;

        if (!is_siteadmin()) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        scope::require($categoryid, 'local/wwadmin:view');
        if ($seatcount < 0) {
            throw new \moodle_exception('missing', 'local_wwadmin');
        }
        $existing = $DB->get_record('local_wwadmin_seat', ['categoryid' => $categoryid]);
        if ($existing) {
            $existing->seats = $seatcount;
            $existing->usermodified = (int) $USER->id;
            $existing->timemodified = time();
            $DB->update_record('local_wwadmin_seat', $existing);
            return;
        }
        $DB->insert_record('local_wwadmin_seat', (object) [
            'categoryid' => $categoryid,
            'seats' => $seatcount,
            'usermodified' => (int) $USER->id,
            'timemodified' => time(),
        ]);
    }

    /**
     * Active people and seats for the schools on the home tile.
     *
     * Seats are omitted when any visible school has no number entered.
     *
     * @return array{active: int, seats: ?int}
     */
    public static function totals(): array {
        global $USER;

        $active = 0;
        $seats = 0;
        $complete = true;
        $roots = scope::managed_root_ids((int) $USER->id);
        if (!$roots) {
            return ['active' => 0, 'seats' => null];
        }
        foreach ($roots as $categoryid) {
            $active += self::active_count($categoryid);
            $count = self::count($categoryid);
            if ($count === null) {
                $complete = false;
            } else {
                $seats += $count;
            }
        }
        return [
            'active' => $active,
            'seats' => $complete ? $seats : null,
        ];
    }

    /**
     * The tile fact: active people, a slash, and the seat count or none.
     *
     * @param int $active
     * @param int|null $seatcount
     * @return string
     */
    public static function fact(int $active, ?int $seatcount): string {
        return get_string('seatfact', 'local_wwadmin', (object) [
            'active' => $active,
            'seats' => $seatcount === null ? get_string('none', 'local_wwadmin') : $seatcount,
        ]);
    }
}
