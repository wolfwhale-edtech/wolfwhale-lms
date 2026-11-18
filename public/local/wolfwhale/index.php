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
 * Student home.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

if (isguestuser() || !\local_wolfwhale\home::is_student((int) $USER->id)) {
    redirect(new moodle_url('/my/'));
}

// The calendar read below resets the page, so the page is set up after it.
$data = \local_wolfwhale\home::export();

$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_url(\local_wolfwhale\home::url());
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title(get_string('home', 'local_wolfwhale'));
$PAGE->set_heading(get_string('home', 'local_wolfwhale'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wolfwhale/home', $data);
echo $OUTPUT->footer();
