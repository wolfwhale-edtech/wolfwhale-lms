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
 * Tiles for the admin home.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class home {
    /**
     * Page setup.
     *
     * @param string $titlekey
     * @param \moodle_url $url
     * @param \context|null $context
     */
    public static function setup(string $titlekey, \moodle_url $url, ?\context $context = null): void {
        global $PAGE, $USER;

        $PAGE->set_context($context ?? \context_user::instance($USER->id));
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('base');
        $PAGE->set_secondary_navigation(false);
        $title = get_string($titlekey, 'local_wwadmin');
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
    }

    /**
     * Template data for the admin home.
     *
     * @return array
     */
    public static function export(): array {
        $tiles = self::tiles();
        $speech = [];
        foreach ($tiles as $tile) {
            $speech[] = $tile['speech'];
        }
        return [
            'tiles' => $tiles,
            'screenspeech' => implode(' ', $speech),
            'read' => get_string('read', 'local_wwadmin'),
        ];
    }

    /**
     * The tiles, in display order.
     *
     * @return array[]
     */
    public static function tiles(): array {
        global $USER;

        $roots = scope::managed_root_ids((int) $USER->id);
        $tree = [];
        foreach ($roots as $root) {
            foreach (scope::tree_ids($root) as $id) {
                $tree[$id] = $id;
            }
        }
        $tree = array_values($tree);
        $people = count(scope::users($tree));
        $courses = count(scope::courses($tree));
        $licence = seats::totals();
        $exports = exporter::list_for_current();
        $ready = 0;
        foreach ($exports as $export) {
            if ($export->status === exporter::READY) {
                $ready++;
            }
        }

        $schoolfact = (string) count($roots);
        $schoolempty = count($roots) < 1;
        if (count($roots) === 1) {
            $rate = snapshot::attendance_rate($tree, null, null);
            $schoolfact = snapshot::format_rate($rate);
            $schoolempty = $rate === null;
        }

        $tiles = [
            self::tile('people', 'people', $people > 0 ? (string) $people : get_string('none', 'local_wwadmin'),
                $people < 1, new \moodle_url('/local/wwadmin/people.php')),
            self::tile('classes', 'classes', $courses > 0 ? (string) $courses : get_string('none', 'local_wwadmin'),
                $courses < 1, new \moodle_url('/local/wwadmin/classes.php')),
            self::tile('school', 'school', $schoolfact, $schoolempty, new \moodle_url('/local/wwadmin/school.php')),
            self::tile('reports', 'reports', count($roots) > 0 ? (string) count($roots) : get_string('none', 'local_wwadmin'),
                count($roots) < 1, new \moodle_url('/local/wwadmin/report.php')),
            self::tile('export', 'export', $ready > 0 ? (string) $ready : get_string('none', 'local_wwadmin'),
                $ready < 1, new \moodle_url('/local/wwadmin/export.php')),
            self::tile('licences', 'licences', seats::fact($licence['active'], $licence['seats']),
                false, new \moodle_url('/local/wwadmin/licences.php')),
            self::goal_tile($roots),
            self::week_tile($roots),
        ];
        $funder = self::funder_tile();
        if ($funder) {
            $tiles[] = $funder;
        }
        $cards = self::cards_tile();
        if ($cards) {
            $tiles[] = $cards;
        }
        return $tiles;
    }

    /**
     * The funder report for the schools this person administers.
     *
     * @return array|null
     */
    private static function funder_tile(): ?array {
        if (!class_exists(\local_wwreports\funder::class)) {
            return null;
        }
        $scopes = \local_wwreports\funder::scopes();
        $url = new \moodle_url('/local/wwreports/funder.php');
        if (count($scopes) === 1) {
            return self::tile('funder', 'funder', $scopes[0]['name'], false, $url);
        }
        if (!$scopes) {
            return self::tile('funder', 'funder', get_string('none', 'local_wwadmin'), true, $url);
        }
        return self::tile('funder', 'funder', (string) count($scopes), false, $url);
    }

    /**
     * Report cards for students in the schools this person administers.
     *
     * @return array|null
     */
    private static function cards_tile(): ?array {
        global $USER;

        if (!class_exists(\local_wwreports\access::class)) {
            return null;
        }
        $students = \local_wwreports\access::students_administered((int) $USER->id);
        $url = new \moodle_url('/local/wwreports/card.php');
        $count = count($students);
        if ($count === 0) {
            return self::tile('cards', 'cards', get_string('none', 'local_wwadmin'), true, $url);
        }
        if ($count === 1) {
            $student = reset($students);
            return self::tile('cards', 'cards', \local_wwpoints\points::plain(fullname($student)), false, $url);
        }
        return self::tile('cards', 'cards', (string) $count, false, $url);
    }

    /**
     * The school goal for the schools this person administers.
     *
     * @param int[] $roots
     * @return array
     */
    private static function goal_tile(array $roots): array {
        $names = [];
        foreach ($roots as $root) {
            $goal = \local_wwpoints\goal::for_category((int) $root);
            if ($goal) {
                $names[] = \local_wwpoints\points::plain($goal->name);
            }
        }
        if (count($roots) === 1) {
            $fact = $names ? $names[0] : get_string('none', 'local_wwadmin');
            $empty = !$names;
        } else {
            $fact = $names ? (string) count($names) : get_string('none', 'local_wwadmin');
            $empty = !$names;
        }
        return self::tile('goal', 'goal', $fact, $empty, new \moodle_url('/local/wwpoints/schoolgoal.php'));
    }

    /**
     * The day and hour of the weekly summary.
     *
     * @param int[] $roots
     * @return array
     */
    private static function week_tile(array $roots): array {
        $url = new \moodle_url('/local/wwupdates/schedule.php');
        if (!class_exists(\local_wwupdates\schedule::class) || !$roots) {
            return self::tile('week', 'week', get_string('none', 'local_wwadmin'), true, $url);
        }
        if (count($roots) === 1) {
            return self::tile('week', 'week', \local_wwupdates\schedule::label((int) $roots[0]), false, $url);
        }
        return self::tile('week', 'week', (string) count($roots), false, $url);
    }

    /**
     * One tile.
     *
     * @param string $id
     * @param string $stringkey
     * @param string $fact
     * @param bool $empty
     * @param \moodle_url $url
     * @return array
     */
    private static function tile(string $id, string $stringkey, string $fact, bool $empty, \moodle_url $url): array {
        $word = get_string($stringkey, 'local_wwadmin');
        return [
            'id' => $id,
            'word' => $word,
            'fact' => $fact,
            'empty' => $empty,
            'url' => $url->out(false),
            'speech' => get_string('speech', 'local_wwadmin', (object) ['word' => $word, 'fact' => $fact]),
            'is' . $id => true,
        ];
    }
}
