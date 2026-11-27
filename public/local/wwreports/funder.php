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
 * A funder report for one school, division, or education authority.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$categoryid = optional_param('category', 0, PARAM_INT);
$termid = optional_param('term', '', PARAM_ALPHANUMEXT);
$download = optional_param('download', '', PARAM_ALPHA);
$scopes = \local_wwreports\funder::scopes();

if ($categoryid <= 0 && count($scopes) === 1) {
    $categoryid = (int) $scopes[0]['id'];
}

if ($categoryid <= 0) {
    $rows = [];
    foreach ($scopes as $scope) {
        $rows[] = [
            'name' => $scope['name'],
            'url' => (new moodle_url('/local/wwreports/funder.php', ['category' => $scope['id']]))->out(false),
            'current' => false,
        ];
    }
    $PAGE->set_context(context_user::instance($USER->id));
    $PAGE->set_url(new moodle_url('/local/wwreports/funder.php'));
    $PAGE->set_pagelayout('base');
    $PAGE->set_secondary_navigation(false);
    $title = get_string('funder', 'local_wwreports');
    $PAGE->set_title($title);
    $PAGE->set_heading($title);
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_wwreports/funder', [
        'picker' => true,
        'scopes' => $rows,
        'terms' => [],
        'classes' => [],
        'name' => '',
        'kind' => '',
        'termname' => '',
        'empty' => true,
        'none' => get_string('none', 'local_wwreports'),
        'print' => get_string('print', 'local_wwreports'),
        'csv' => get_string('csv', 'local_wwreports'),
        'csvurl' => '',
        'read' => get_string('read', 'local_wwreports'),
        'screenspeech' => $rows ? implode('. ', array_column($rows, 'name')) . '.' : get_string('none', 'local_wwreports'),
    ]);
    echo $OUTPUT->footer();
    exit;
}

\local_wwreports\funder::require_category($categoryid);
$term = \local_wwreports\terms::pick(\local_wwreports\terms::for_category($categoryid), $termid);
$data = \local_wwreports\funder::report($categoryid, $term);

if ($download === 'csv') {
    \local_wwreports\funder::send_csv($data);
}

$url = new moodle_url('/local/wwreports/funder.php', [
    'category' => $categoryid,
    'term' => $term['id'],
]);
$PAGE->set_context(context_coursecat::instance($categoryid));
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title($data['name']);
$PAGE->set_heading($data['name']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwreports/funder', $data);
echo $OUTPUT->footer();
