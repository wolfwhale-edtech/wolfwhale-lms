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
 * The day and hour a school sends the weekly summary.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

$roots = \local_wwupdates\schedule::roots();
if (!$roots) {
    redirect(new moodle_url('/my/'));
}
$url = new moodle_url('/local/wwupdates/schedule.php');
if (data_submitted() && confirm_sesskey()) {
    $days = optional_param_array('weekday', [], PARAM_INT);
    $hours = optional_param_array('hour', [], PARAM_INT);
    foreach ($roots as $id) {
        if (!array_key_exists($id, $days) || !array_key_exists($id, $hours)) {
            continue;
        }
        \local_wwupdates\schedule::save((int) $id, (int) $days[$id], (int) $hours[$id]);
    }
    redirect($url);
}

$schools = [];
foreach ($roots as $id) {
    $saved = \local_wwupdates\schedule::get((int) $id);
    $schools[] = [
        'id' => (int) $id,
        'name' => \local_wwupdates\audience::category_name((int) $id),
        'days' => \local_wwupdates\schedule::day_options((int) $saved->weekday),
        'hours' => \local_wwupdates\schedule::hour_options((int) $saved->hour),
    ];
}

$PAGE->set_context(\context_user::instance($USER->id));
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$title = get_string('weekly', 'local_wwupdates');
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwupdates/schedule', [
    'schools' => $schools,
    'day' => get_string('day', 'local_wwupdates'),
    'time' => get_string('time', 'local_wwupdates'),
    'save' => get_string('save', 'local_wwupdates'),
    'sesskey' => sesskey(),
]);
echo $OUTPUT->footer();
