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
 * Tag activities in one course with outcomes.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$courseid = required_param('course', PARAM_INT);
$cmid = optional_param('cm', 0, PARAM_INT);
$context = \local_wwtreaty\school::require_tag($courseid);
$schoolid = \local_wwtreaty\school::school_for_course($courseid);
$coverage = new moodle_url('/local/wwtreaty/coverage.php', ['course' => $courseid]);
$listurl = new moodle_url('/local/wwtreaty/tag.php', ['course' => $courseid]);

if (data_submitted()) {
    require_sesskey();
    $cmid = required_param('cm', PARAM_INT);
    $outcomeid = required_param('outcome', PARAM_INT);
    $on = required_param('on', PARAM_BOOL);
    \local_wwtreaty\tag::set($cmid, $outcomeid, (bool) $on);
    redirect(new moodle_url('/local/wwtreaty/tag.php', ['course' => $courseid, 'cm' => $cmid]));
}

$tiles = [
    \local_wwtreaty\page::link(get_string('treaty', 'local_wwtreaty'), '', $coverage->out(false)),
];

if (!$cmid) {
    foreach (\local_wwtreaty\tag::activities($courseid) as $cm) {
        $count = \local_wwtreaty\tag::count_for_activity((int) $cm->id);
        $tiles[] = \local_wwtreaty\page::link(
            \local_wwtreaty\page::plain($cm->name, \context_module::instance($cm->id)),
            $count > 0 ? (string) $count : get_string('none', 'local_wwtreaty'),
            (new moodle_url($listurl, ['cm' => $cm->id]))->out(false),
            $count < 1
        );
    }
    if (count($tiles) === 1) {
        $tiles[] = \local_wwtreaty\page::link(get_string('none', 'local_wwtreaty'), '', null, true);
    }
    \local_wwtreaty\page::render(get_string('tag', 'local_wwtreaty'), $listurl, $context, ['tiles' => $tiles]);
    exit;
}

$cm = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
$lesson = \local_wwtreaty\page::plain($cm->name, \context_module::instance($cm->id));
$tiles = [
    \local_wwtreaty\page::link($lesson !== '' ? $lesson : get_string('tag', 'local_wwtreaty'), '', $listurl->out(false)),
];
$tagged = array_fill_keys(\local_wwtreaty\tag::outcome_ids($cmid), true);
$action = (new moodle_url('/local/wwtreaty/tag.php', ['course' => $courseid]))->out(false);
foreach (\local_wwtreaty\outcome::records($schoolid, true) as $outcome) {
    $on = isset($tagged[(int) $outcome->id]);
    $tiles[] = \local_wwtreaty\page::post(
        $outcome->code,
        $outcome->title,
        $action,
        [
            'course' => $courseid,
            'cm' => $cmid,
            'outcome' => $outcome->id,
            'on' => $on ? 0 : 1,
        ],
        false,
        $on ? 'met' : 'open',
        $on
    );
}
if (count($tiles) === 1) {
    $tiles[] = \local_wwtreaty\page::link(get_string('none', 'local_wwtreaty'), '', null, true);
}
$url = new moodle_url('/local/wwtreaty/tag.php', ['course' => $courseid, 'cm' => $cmid]);
\local_wwtreaty\page::render($lesson !== '' ? $lesson : get_string('tag', 'local_wwtreaty'), $url, $context, ['tiles' => $tiles]);
