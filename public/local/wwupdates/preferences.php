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
 * Parent switches for updates.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wolfwhale\parents::require_parent();

$userid = (int) $USER->id;
$url = new moodle_url('/local/wwupdates/preferences.php');
if (data_submitted() && confirm_sesskey()) {
    $on = [];
    $keys = array_merge(\local_wwupdates\preferences::CHANNELS, \local_wwupdates\preferences::TYPES);
    foreach ($keys as $key) {
        $on[$key] = optional_param($key, 0, PARAM_BOOL);
    }
    \local_wwupdates\preferences::save($userid, $on);
    \local_wwupdates\preferences::set_phone($userid, optional_param('phone', '', PARAM_TEXT));
    redirect($url);
}

$PAGE->set_context(\context_user::instance($userid));
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$title = get_string('updates', 'local_wwupdates');
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwupdates/preferences', \local_wwupdates\preferences::form($userid));
echo $OUTPUT->footer();
