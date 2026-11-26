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
 * Add or edit one outcome.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$categoryid = required_param('category', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$root = \local_wwtreaty\school::root_id($categoryid);
$context = \local_wwtreaty\school::require_manage($root);
$url = new moodle_url('/local/wwtreaty/edit.php', ['category' => $root]);
if ($id) {
    $url->param('id', $id);
}
$back = new moodle_url('/local/wwtreaty/index.php', ['category' => $root]);
$row = $id ? \local_wwtreaty\outcome::get_for($id, $root) : null;

$action = optional_param('action', '', PARAM_ALPHA);
if (data_submitted() && $row && $action === 'archive') {
    require_sesskey();
    \local_wwtreaty\outcome::set_active((int) $row->id, false);
    redirect($back);
}
if (data_submitted() && $row && $action === 'restore') {
    require_sesskey();
    \local_wwtreaty\outcome::set_active((int) $row->id, true);
    redirect(new moodle_url('/local/wwtreaty/index.php', ['category' => $root, 'archived' => 1]));
}
if (data_submitted() && $action === '') {
    require_sesskey();
    \local_wwtreaty\outcome::save($root, [
        'code' => required_param('code', PARAM_RAW_TRIMMED),
        'grade' => optional_param('grade', '', PARAM_RAW_TRIMMED),
        'theme' => optional_param('theme', '', PARAM_RAW_TRIMMED),
        'title' => required_param('title', PARAM_RAW_TRIMMED),
        'description' => optional_param('description', '', PARAM_RAW),
    ], $id);
    redirect($back);
}

$tiles = [
    \local_wwtreaty\page::link(get_string('outcomes', 'local_wwtreaty'), '', $back->out(false)),
];
if ($row) {
    $tiles[] = \local_wwtreaty\page::post(
        (int) $row->active ? get_string('archive', 'local_wwtreaty') : get_string('restore', 'local_wwtreaty'),
        '',
        $url->out(false),
        [
            'category' => $root,
            'id' => $row->id,
            'action' => (int) $row->active ? 'archive' : 'restore',
        ]
    );
}

$value = static function (string $field) use ($row): string {
    return $row ? (string) $row->{$field} : '';
};
$data = [
    'tiles' => $tiles,
    'form' => [
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'submit' => get_string('save', 'local_wwtreaty'),
        'hidden' => [
            ['name' => 'category', 'value' => $root],
            ['name' => 'id', 'value' => $id],
        ],
        'fields' => [
            ['name' => 'code', 'label' => get_string('code', 'local_wwtreaty'), 'text' => true,
                'value' => $value('code'), 'max' => 64, 'required' => true],
            ['name' => 'grade', 'label' => get_string('grade', 'local_wwtreaty'), 'text' => true,
                'value' => $value('grade'), 'max' => 64, 'required' => false],
            ['name' => 'theme', 'label' => get_string('theme', 'local_wwtreaty'), 'text' => true,
                'value' => $value('theme'), 'max' => 255, 'required' => false],
            ['name' => 'title', 'label' => get_string('title', 'local_wwtreaty'), 'text' => true,
                'value' => $value('title'), 'max' => 255, 'required' => true],
            ['name' => 'description', 'label' => get_string('description', 'local_wwtreaty'), 'area' => true,
                'value' => $value('description')],
        ],
    ],
];
$title = $row ? $row->code : get_string('add', 'local_wwtreaty');
\local_wwtreaty\page::render($title, $url, $context, $data);
