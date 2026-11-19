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
 * People in one school.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$url = new moodle_url('/local/wwadmin/people.php');
$categoryid = \local_wwadmin\ui::category_param();
if (!$categoryid) {
    \local_wwadmin\ui::render('people', $url, [
        'rows' => \local_wwadmin\ui::school_rows($url),
    ]);
    exit;
}

$context = \context_coursecat::instance($categoryid);
$url->param('category', $categoryid);
$rows = [];
foreach (\local_wwadmin\people::list_users($categoryid) as $user) {
    $row = [
        'word' => fullname($user),
        'url' => (new moodle_url('/local/wwadmin/person.php', [
            'category' => $categoryid,
            'id' => $user->id,
        ]))->out(false),
    ];
    if ((int) $user->suspended) {
        $row['fact'] = get_string('suspended', 'local_wwadmin');
    }
    $rows[] = $row;
}
$actions = [];
if (has_capability('local/wwadmin:managepeople', $context)) {
    $actions[] = [
        'word' => get_string('add', 'local_wwadmin'),
        'url' => (new moodle_url('/local/wwadmin/person.php', ['category' => $categoryid]))->out(false),
    ];
    $actions[] = [
        'word' => get_string('upload', 'local_wwadmin'),
        'url' => (new moodle_url('/local/wwadmin/upload.php', ['category' => $categoryid]))->out(false),
    ];
}
if (has_capability('moodle/site:uploadusers', context_system::instance())) {
    $actions[] = [
        'word' => get_string('uploadusers', 'local_wwadmin'),
        'url' => (new moodle_url('/admin/tool/uploaduser/index.php'))->out(false),
    ];
}

\local_wwadmin\ui::render('people', $url, [
    'rows' => $rows,
    'actions' => $actions,
    'empty' => !$rows,
    'none' => get_string('none', 'local_wwadmin'),
]);
