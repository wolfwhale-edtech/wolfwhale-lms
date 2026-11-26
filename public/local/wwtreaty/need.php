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
 * One outcome: the activities tagged with it, and the students who still need it.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$courseid = required_param('course', PARAM_INT);
$outcomeid = required_param('outcome', PARAM_INT);
$term = optional_param('term', -1, PARAM_INT);
$termid = $term < 0 ? null : $term;
$context = \local_wwtreaty\school::require_view($courseid);

if (data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'meet') {
    require_sesskey();
    \local_wwtreaty\coverage::meet(
        $courseid,
        $outcomeid,
        required_param('user', PARAM_INT),
        max(0, $term)
    );
    redirect(new moodle_url('/local/wwtreaty/need.php', [
        'course' => $courseid,
        'outcome' => $outcomeid,
        'term' => $term,
    ]));
}

$summary = \local_wwtreaty\coverage::summary($courseid, $termid);
$found = null;
foreach ($summary['outcomes'] as $row) {
    if ((int) $row['id'] === $outcomeid) {
        $found = $row;
        break;
    }
}
if (!$found) {
    throw new moodle_exception('invalidrecord', 'error');
}

$back = new moodle_url('/local/wwtreaty/coverage.php', ['course' => $courseid]);
if ($summary['termid']) {
    $back->param('term', $summary['termid']);
}
$url = new moodle_url('/local/wwtreaty/need.php', [
    'course' => $courseid,
    'outcome' => $outcomeid,
    'term' => $summary['termid'],
]);

$fact = get_string('none', 'local_wwtreaty');
if ($found['status'] === 'met') {
    $fact = get_string('met', 'local_wwtreaty');
} else if ($found['status'] === 'gap') {
    $fact = (string) $found['needs'];
}
$tiles = [
    \local_wwtreaty\page::link(get_string('treaty', 'local_wwtreaty'), '', $back->out(false)),
    \local_wwtreaty\page::link(
        $found['code'],
        $fact,
        $back->out(false),
        $found['status'] === 'open',
        $found['status'],
        false,
        $found['tagged'] ? $found['percent'] : null
    ),
];

$tagged = $DB->get_records('local_wwtreaty_tag', ['outcomeid' => $outcomeid, 'courseid' => $courseid]);
$taggedids = [];
foreach ($tagged as $tag) {
    $taggedids[(int) $tag->cmid] = true;
}
foreach (\local_wwtreaty\tag::activities($courseid) as $cm) {
    if (empty($taggedids[(int) $cm->id])) {
        continue;
    }
    $tiles[] = \local_wwtreaty\page::link(
        \local_wwtreaty\page::plain($cm->name, \context_module::instance($cm->id)),
        $found['code'],
        (new moodle_url('/local/wwtreaty/tag.php', ['course' => $courseid, 'cm' => $cm->id]))->out(false),
        false,
        'met'
    );
}

$cantag = \local_wwtreaty\school::can_tag($courseid);
foreach ($summary['needs'] as $need) {
    if (!in_array($found['code'], $need['codes'], true)) {
        continue;
    }
    if ($cantag) {
        $tiles[] = \local_wwtreaty\page::post(
            $need['name'],
            get_string('met', 'local_wwtreaty'),
            $url->out(false),
            [
                'course' => $courseid,
                'outcome' => $outcomeid,
                'user' => $need['userid'],
                'term' => $summary['termid'],
                'action' => 'meet',
            ],
            false,
            'gap'
        );
        continue;
    }
    $tiles[] = \local_wwtreaty\page::link($need['name'], $found['code'], null, false, 'gap');
}

\local_wwtreaty\page::render($found['code'], $url, $context, ['tiles' => $tiles]);
