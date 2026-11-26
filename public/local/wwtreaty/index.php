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

/**
 * Outcome list for one school.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$categoryid = optional_param('category', 0, PARAM_INT);
$archived = optional_param('archived', 0, PARAM_BOOL);
$roots = \local_wwtreaty\school::managed_root_ids();
if (!$roots) {
    redirect(new moodle_url('/my/'));
}

$homeurl = '/local/wwadmin/index.php';
if (class_exists(\local_wolfwhale\teacher::class) && \local_wolfwhale\teacher::is_teacher((int) $USER->id)) {
    $homeurl = '/local/wolfwhale/teacher.php';
}
$home = \local_wwtreaty\page::link(
    get_string('home', 'local_wwtreaty'),
    '',
    (new moodle_url($homeurl))->out(false)
);

if (!$categoryid) {
    if (count($roots) === 1) {
        $categoryid = $roots[0];
    } else {
        $tiles = [$home];
        foreach ($roots as $id) {
            $category = $DB->get_record('course_categories', ['id' => $id], 'id, name', MUST_EXIST);
            $context = \context_coursecat::instance($id);
            $count = \local_wwtreaty\outcome::active_count([$id]);
            $tiles[] = \local_wwtreaty\page::link(
                \local_wwtreaty\page::plain($category->name, $context),
                $count > 0 ? (string) $count : get_string('none', 'local_wwtreaty'),
                (new moodle_url('/local/wwtreaty/index.php', ['category' => $id]))->out(false),
                $count < 1
            );
        }
        \local_wwtreaty\page::render(
            get_string('outcomes', 'local_wwtreaty'),
            new moodle_url('/local/wwtreaty/index.php'),
            \context_user::instance($USER->id),
            ['tiles' => $tiles]
        );
        exit;
    }
}

$root = \local_wwtreaty\school::root_id($categoryid);
if ($root !== $categoryid) {
    redirect(new moodle_url('/local/wwtreaty/index.php', ['category' => $root, 'archived' => $archived]));
}
$context = \local_wwtreaty\school::require_manage($root);
$url = new moodle_url('/local/wwtreaty/index.php', ['category' => $root]);
if ($archived) {
    $url->param('archived', 1);
}

$tiles = [];
if (count($roots) > 1) {
    $tiles[] = \local_wwtreaty\page::link(
        get_string('outcomes', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/index.php'))->out(false)
    );
} else {
    $tiles[] = $home;
}

$rows = \local_wwtreaty\outcome::records($root, false);
$showactive = $archived ? 0 : 1;
foreach ($rows as $row) {
    if ((int) $row->active !== $showactive) {
        continue;
    }
    $tiles[] = \local_wwtreaty\page::link(
        $row->code,
        $row->title,
        (new moodle_url('/local/wwtreaty/edit.php', ['category' => $root, 'id' => $row->id]))->out(false),
        !(int) $row->active
    );
}

if ($archived) {
    $tiles[] = \local_wwtreaty\page::link(
        get_string('outcomes', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/index.php', ['category' => $root]))->out(false)
    );
} else {
    $tiles[] = \local_wwtreaty\page::link(
        get_string('add', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/edit.php', ['category' => $root]))->out(false)
    );
    $tiles[] = \local_wwtreaty\page::link(
        get_string('import', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/import.php', ['category' => $root]))->out(false)
    );
    $tiles[] = \local_wwtreaty\page::link(
        get_string('archive', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/index.php', ['category' => $root, 'archived' => 1]))->out(false)
    );
}

$shown = 0;
foreach ($rows as $row) {
    if ((int) $row->active === $showactive) {
        $shown++;
    }
}
if ($shown < 1) {
    array_splice($tiles, 1, 0, [
        \local_wwtreaty\page::link(get_string('none', 'local_wwtreaty'), '', null, true),
    ]);
}

\local_wwtreaty\page::render(get_string('outcomes', 'local_wwtreaty'), $url, $context, ['tiles' => $tiles]);
