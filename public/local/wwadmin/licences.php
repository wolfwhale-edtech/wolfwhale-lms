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
 * Seat count and active people for each school.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$url = new moodle_url('/local/wwadmin/licences.php');

if (data_submitted() && is_siteadmin()) {
    require_sesskey();
    \local_wwadmin\seats::set(
        required_param('category', PARAM_INT),
        required_param('seats', PARAM_INT)
    );
    redirect($url);
}

$rows = [];
$forms = [];
foreach (\local_wwadmin\scope::managed_root_ids((int) $USER->id) as $categoryid) {
    $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name', MUST_EXIST);
    $rows[] = [
        'word' => $category->name,
        'fact' => \local_wwadmin\seats::fact(
            \local_wwadmin\seats::active_count($categoryid),
            \local_wwadmin\seats::count($categoryid)
        ),
    ];
    if (is_siteadmin()) {
        $forms[] = [
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            'hidden' => [
                ['name' => 'category', 'value' => $categoryid],
            ],
            'fields' => [
                [
                    'name' => 'seats',
                    'label' => $category->name,
                    'text' => true,
                    'value' => (string) (\local_wwadmin\seats::count($categoryid) ?? ''),
                ],
            ],
            'submit' => get_string('save', 'local_wwadmin'),
        ];
    }
}

\local_wwadmin\ui::render('licences', $url, [
    'rows' => $rows,
    'forms' => $forms,
    'empty' => !$rows,
    'none' => get_string('none', 'local_wwadmin'),
]);
