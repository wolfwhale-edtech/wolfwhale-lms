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
 * Treaty Education coverage for one class.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$courseid = optional_param('course', 0, PARAM_INT);
$term = optional_param('term', -1, PARAM_INT);
$termid = $term < 0 ? null : $term;
$courses = \local_wwtreaty\school::teaching_courses();

if (!$courseid) {
    if (count($courses) === 1) {
        $courseid = (int) reset($courses)->id;
    } else {
        $tiles = [
            \local_wwtreaty\page::link(
                get_string('home', 'local_wwtreaty'),
                '',
                (new moodle_url('/local/wolfwhale/teacher.php'))->out(false)
            ),
        ];
        foreach ($courses as $course) {
            $summary = \local_wwtreaty\coverage::calculate((int) $course->id, $termid);
            $fact = get_string('none', 'local_wwtreaty');
            $empty = true;
            if ($summary['outcomes']) {
                $empty = false;
                $fact = $summary['gaps'] < 1
                    ? get_string('met', 'local_wwtreaty')
                    : (string) $summary['gaps'];
            }
            $tiles[] = \local_wwtreaty\page::link(
                \local_wwtreaty\page::plain($course->fullname, \context_course::instance($course->id)),
                $fact,
                (new moodle_url('/local/wwtreaty/coverage.php', ['course' => $course->id]))->out(false),
                $empty,
                $summary['outcomes'] && $summary['gaps'] < 1 ? 'met' : ($summary['gaps'] > 0 ? 'gap' : 'open')
            );
        }
        if (count($tiles) === 1) {
            $tiles[] = \local_wwtreaty\page::link(get_string('none', 'local_wwtreaty'), '', null, true);
        }
        \local_wwtreaty\page::render(
            get_string('treaty', 'local_wwtreaty'),
            new moodle_url('/local/wwtreaty/coverage.php'),
            \context_user::instance($USER->id),
            ['tiles' => $tiles]
        );
        exit;
    }
}

$context = \local_wwtreaty\school::require_view($courseid);
$summary = \local_wwtreaty\coverage::summary($courseid, $termid);
$params = ['course' => $courseid];
if ($summary['termid']) {
    $params['term'] = $summary['termid'];
}
$url = new moodle_url('/local/wwtreaty/coverage.php', ['course' => $courseid]);
if ($term >= 0) {
    $url->param('term', $term);
}

$tiles = [
    \local_wwtreaty\page::link(
        get_string('home', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wolfwhale/teacher.php'))->out(false)
    ),
];
if (count($courses) > 1) {
    foreach ($courses as $course) {
        $tiles[] = \local_wwtreaty\page::link(
            \local_wwtreaty\page::plain($course->fullname, \context_course::instance($course->id)),
            '',
            (new moodle_url('/local/wwtreaty/coverage.php', ['course' => $course->id]))->out(false),
            false,
            '',
            (int) $course->id === $courseid
        );
    }
}

$terms = $DB->get_records('local_wwterms', ['categoryid' => $summary['schoolid']], 'timestart ASC, id ASC');
foreach ($terms as $saved) {
    $tiles[] = \local_wwtreaty\page::link(
        \local_wwtreaty\page::plain($saved->name),
        '',
        (new moodle_url('/local/wwtreaty/coverage.php', [
            'course' => $courseid,
            'term' => $saved->id,
        ]))->out(false),
        false,
        '',
        (int) $saved->id === (int) $summary['termid']
    );
}

foreach ($summary['outcomes'] as $row) {
    if ($row['status'] === 'open') {
        $fact = get_string('none', 'local_wwtreaty');
        $target = new moodle_url('/local/wwtreaty/tag.php', ['course' => $courseid]);
    } else if ($row['status'] === 'met') {
        $fact = get_string('met', 'local_wwtreaty');
        $target = new moodle_url('/local/wwtreaty/need.php', [
            'course' => $courseid,
            'outcome' => $row['id'],
            'term' => $summary['termid'],
        ]);
    } else {
        $fact = (string) $row['needs'];
        $target = new moodle_url('/local/wwtreaty/need.php', [
            'course' => $courseid,
            'outcome' => $row['id'],
            'term' => $summary['termid'],
        ]);
    }
    $tiles[] = \local_wwtreaty\page::link(
        $row['code'],
        $fact,
        $target->out(false),
        $row['status'] === 'open',
        $row['status'],
        false,
        $row['tagged'] ? $row['percent'] : null
    );
}

foreach ($summary['needs'] as $need) {
    $tiles[] = \local_wwtreaty\page::link(
        $need['name'],
        implode(' ', $need['codes']),
        (new moodle_url('/local/wwtreaty/need.php', [
            'course' => $courseid,
            'outcome' => $need['outcomeid'],
            'term' => $summary['termid'],
        ]))->out(false),
        false,
        'gap'
    );
}

if (!$summary['outcomes']) {
    $tiles[] = \local_wwtreaty\page::link(get_string('none', 'local_wwtreaty'), '', null, true);
}

$tiles[] = \local_wwtreaty\page::link(
    get_string('tag', 'local_wwtreaty'),
    '',
    (new moodle_url('/local/wwtreaty/tag.php', ['course' => $courseid]))->out(false)
);
if (\local_wwtreaty\school::can_manage($summary['schoolid'])) {
    $tiles[] = \local_wwtreaty\page::link(
        get_string('outcomes', 'local_wwtreaty'),
        '',
        (new moodle_url('/local/wwtreaty/index.php', ['category' => $summary['schoolid']]))->out(false)
    );
}

\local_wwtreaty\page::render(get_string('treaty', 'local_wwtreaty'), $url, $context, ['tiles' => $tiles]);
