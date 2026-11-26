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
 * Import outcomes from CSV or JSON.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$categoryid = required_param('category', PARAM_INT);
$root = \local_wwtreaty\school::root_id($categoryid);
$context = \local_wwtreaty\school::require_manage($root);
$url = new moodle_url('/local/wwtreaty/import.php', ['category' => $root]);
$back = new moodle_url('/local/wwtreaty/index.php', ['category' => $root]);

if (data_submitted()) {
    require_sesskey();
    $content = '';
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $content = (string) file_get_contents($_FILES['file']['tmp_name']);
    }
    \local_wwtreaty\outcome::import($root, $content);
    redirect($back);
}

$tiles = [
    \local_wwtreaty\page::link(get_string('outcomes', 'local_wwtreaty'), '', $back->out(false)),
    \local_wwtreaty\page::link(
        get_string('csv', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/template.php', ['format' => 'csv']))->out(false)
    ),
    \local_wwtreaty\page::link(
        get_string('json', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/template.php', ['format' => 'json']))->out(false)
    ),
];
$data = [
    'tiles' => $tiles,
    'form' => [
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'submit' => get_string('import', 'local_wwtreaty'),
        'file' => true,
        'hidden' => [
            ['name' => 'category', 'value' => $root],
        ],
        'fields' => [
            [
                'name' => 'file',
                'label' => get_string('file', 'local_wwtreaty'),
                'file' => true,
                'accept' => '.csv,.json,text/csv,application/json',
            ],
        ],
    ],
];
\local_wwtreaty\page::render(get_string('import', 'local_wwtreaty'), $url, $context, $data);
