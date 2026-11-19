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
 * Courses and categories in the schools this person administers.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$url = new moodle_url('/local/wwadmin/classes.php');
$tree = \local_wwadmin\scope::visible_ids();
$rows = [];
foreach (\local_wwadmin\scope::courses($tree) as $course) {
    $rows[] = [
        'word' => $course->fullname,
        'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    ];
}
$actions = [];
foreach (\local_wwadmin\scope::managed_root_ids((int) $USER->id) as $categoryid) {
    $context = \context_coursecat::instance($categoryid);
    $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name', MUST_EXIST);
    if (has_capability('moodle/course:create', $context)) {
        $actions[] = [
            'word' => get_string('add', 'local_wwadmin'),
            'fact' => $category->name,
            'url' => (new moodle_url('/course/edit.php', ['category' => $categoryid]))->out(false),
        ];
    }
    if (has_capability('moodle/category:manage', $context)) {
        $actions[] = [
            'word' => get_string('categories', 'local_wwadmin'),
            'fact' => $category->name,
            'url' => (new moodle_url('/course/management.php', ['categoryid' => $categoryid]))->out(false),
        ];
    }
    if (\local_wwterms\term::can_manage($categoryid)) {
        $actions[] = [
            'word' => get_string('terms', 'local_wwadmin'),
            'fact' => $category->name,
            'url' => (new moodle_url('/local/wwadmin/terms.php', ['category' => $categoryid]))->out(false),
        ];
    }
}

\local_wwadmin\ui::render('classes', $url, [
    'rows' => $rows,
    'actions' => $actions,
    'empty' => !$rows,
    'none' => get_string('none', 'local_wwadmin'),
]);
