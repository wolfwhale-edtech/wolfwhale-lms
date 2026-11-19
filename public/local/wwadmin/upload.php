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
 * Upload people into one school from a CSV file.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$categoryid = required_param('category', PARAM_INT);
\local_wwadmin\scope::require($categoryid, 'local/wwadmin:managepeople');
$url = new moodle_url('/local/wwadmin/upload.php', ['category' => $categoryid]);
$rows = [];

if (data_submitted()) {
    require_sesskey();
    $content = '';
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $content = file_get_contents($_FILES['csv']['tmp_name']);
    }
    $result = \local_wwadmin\people::import_csv($categoryid, (string) $content);
    $rows[] = [
        'word' => get_string('added', 'local_wwadmin'),
        'fact' => (string) $result->created,
    ];
    foreach ($result->errors as $error) {
        $rows[] = $error;
    }
}

$data = [
    'rows' => $rows,
    'form' => [
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'submit' => get_string('upload', 'local_wwadmin'),
        'file' => true,
        'fields' => [
            ['name' => 'csv', 'label' => get_string('upload', 'local_wwadmin'), 'file' => true],
        ],
    ],
    'back' => (new moodle_url('/local/wwadmin/people.php', ['category' => $categoryid]))->out(false),
    'backword' => get_string('people', 'local_wwadmin'),
];
\local_wwadmin\ui::render('upload', $url, $data);
