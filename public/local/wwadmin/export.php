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
 * Ask for a school archive and download one that is ready.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$url = new moodle_url('/local/wwadmin/export.php');

if (data_submitted()) {
    require_sesskey();
    $categoryid = required_param('category', PARAM_INT);
    \local_wwadmin\exporter::request($categoryid);
    redirect($url);
}

$actions = [];
foreach (\local_wwadmin\ui::school_rows($url) as $row) {
    $categoryid = (int) $row['id'];
    $context = \context_coursecat::instance($categoryid);
    if (!has_capability('local/wwadmin:export', $context)) {
        continue;
    }
    $actions[] = [
        'word' => get_string('export', 'local_wwadmin'),
        'fact' => $row['word'],
        'post' => true,
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'category' => $categoryid,
    ];
}

$rows = [];
foreach (\local_wwadmin\exporter::list_for_current() as $export) {
    $category = $DB->get_record('course_categories', ['id' => $export->categoryid], 'id, name', IGNORE_MISSING);
    $name = $category ? $category->name : (string) $export->categoryid;
    $status = get_string($export->status, 'local_wwadmin');
    $row = [
        'word' => $name,
        'fact' => $status,
    ];
    if ($export->status === \local_wwadmin\exporter::READY) {
        $row['url'] = (new moodle_url('/local/wwadmin/download.php', [
            'id' => $export->id,
            'sesskey' => sesskey(),
        ]))->out(false);
        $row['fact'] = get_string('download', 'local_wwadmin');
    }
    $rows[] = $row;
}

\local_wwadmin\ui::render('export', $url, [
    'rows' => $rows,
    'actions' => $actions,
    'empty' => !$rows,
    'none' => get_string('none', 'local_wwadmin'),
]);
