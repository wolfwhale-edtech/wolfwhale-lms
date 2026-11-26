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
 * Site administrator: create and open Nations.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$url = new moodle_url('/local/wwadmin/nations.php');
if (!is_siteadmin()) {
    redirect(new moodle_url('/local/wwadmin/sovereignty.php'));
}

if (data_submitted()) {
    require_sesskey();
    $name = required_param('name', PARAM_TEXT);
    $nationid = \local_wwnation\nation::create($name);
    $categoryid = $DB->get_field('local_wwnation', 'categoryid', ['id' => $nationid], MUST_EXIST);
    redirect(new moodle_url('/local/wwadmin/sovereignty.php', ['category' => $categoryid]));
}

$rows = [];
foreach (\local_wwnation\nation::all() as $nation) {
    $rows[] = [
        'word' => \local_wwnation\nation::plain($nation->name),
        'url' => (new moodle_url('/local/wwadmin/sovereignty.php', ['category' => $nation->categoryid]))->out(false),
    ];
}

\local_wwadmin\ui::render('nations', $url, [
    'back' => (new moodle_url('/local/wwadmin/index.php'))->out(false),
    'backword' => get_string('home', 'local_wwadmin'),
    'rows' => $rows,
    'empty' => !$rows,
    'none' => get_string('none', 'local_wwadmin'),
    'form' => [
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'submit' => get_string('save', 'local_wwadmin'),
        'fields' => [[
            'label' => get_string('name', 'local_wwadmin'),
            'name' => 'name',
            'text' => true,
            'value' => '',
        ]],
    ],
]);
