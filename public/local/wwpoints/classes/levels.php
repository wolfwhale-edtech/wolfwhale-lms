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
 * Level numbers come from the stored threshold table.
 *
 * Each line is a level, an equals sign, and the points that level needs.
 * An administrator edits that text. The pages do not keep their own copy.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class levels {
    /** @var string Level number = points needed. */
    public const DEFAULT_TABLE = "1=0\n2=25\n3=75\n4=150\n5=300";

    /**
     * Level for a point total.
     *
     * The level is the highest stored threshold the total has reached.
     * A total below every threshold stays on the first level.
     *
     * @param int $points
     * @return int
     */
    public static function for_points(int $points): int {
        $table = self::table();
        $current = array_key_first($table);
        foreach ($table as $level => $need) {
            if ($points >= $need) {
                $current = $level;
            }
        }
        return (int) $current;
    }

    /**
     * The stored table, lowest threshold first.
     *
     * @return array<int, int> Level number => points needed.
     */
    public static function table(): array {
        $raw = get_config('local_wwpoints', 'levels');
        if (!is_string($raw) || trim($raw) === '') {
            $raw = self::DEFAULT_TABLE;
        }
        $table = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$level, $need] = array_map('trim', explode('=', $line, 2));
            if (ctype_digit($level) && preg_match('/^-?\d+$/', $need)) {
                $table[(int) $level] = (int) $need;
            }
        }
        if (!$table) {
            $table = [1 => 0];
        }
        asort($table);
        return $table;
    }
}
