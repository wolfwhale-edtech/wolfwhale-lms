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
 * Terms for one school.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$categoryid = required_param('category', PARAM_INT);
\local_wwterms\term::require_manage($categoryid);
$url = new moodle_url('/local/wwadmin/terms.php', ['category' => $categoryid]);

$delete = optional_param('delete', 0, PARAM_INT);
if ($delete) {
    require_sesskey();
    \local_wwterms\term::delete($delete);
    redirect($url);
}
if (data_submitted()) {
    require_sesskey();
    $start = \local_wwadmin\ui::day_stamp(required_param('start', PARAM_RAW_TRIMMED), false);
    $end = \local_wwadmin\ui::day_stamp(required_param('end', PARAM_RAW_TRIMMED), true);
    \local_wwterms\term::create($categoryid, required_param('name', PARAM_TEXT), $start, $end);
    redirect($url);
}

$rows = [];
foreach (\local_wwterms\term::list_for($categoryid) as $term) {
    $rows[] = [
        'word' => $term->name,
        'fact' => \local_wwadmin\report::day((int) $term->timestart) . ' ' . \local_wwadmin\report::day((int) $term->timeend),
        'deleteurl' => (new moodle_url($url, ['delete' => $term->id, 'sesskey' => sesskey()]))->out(false),
        'delete' => get_string('delete', 'local_wwadmin'),
    ];
}

\local_wwadmin\ui::render('terms', $url, [
    'rows' => $rows,
    'empty' => !$rows,
    'none' => get_string('none', 'local_wwadmin'),
    'form' => [
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'submit' => get_string('save', 'local_wwadmin'),
        'fields' => [
            ['name' => 'name', 'label' => get_string('name', 'local_wwadmin'), 'text' => true],
            ['name' => 'start', 'label' => get_string('start', 'local_wwadmin'), 'date' => true],
            ['name' => 'end', 'label' => get_string('end', 'local_wwadmin'), 'date' => true],
        ],
    ],
    'back' => (new moodle_url('/local/wwadmin/classes.php'))->out(false),
    'backword' => get_string('classes', 'local_wwadmin'),
]);
