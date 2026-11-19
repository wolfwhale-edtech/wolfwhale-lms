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
 * One school snapshot.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$url = new moodle_url('/local/wwadmin/school.php');
$categoryid = \local_wwadmin\ui::category_param();
if (!$categoryid) {
    \local_wwadmin\ui::render('school', $url, [
        'rows' => \local_wwadmin\ui::school_rows($url),
    ]);
    exit;
}

$url->param('category', $categoryid);
$counts = \local_wwadmin\snapshot::for_category($categoryid);
$category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name', MUST_EXIST);
$rows = [
    ['word' => format_string($category->name), 'fact' => ''],
    ['word' => get_string('students', 'local_wwadmin'), 'fact' => (string) $counts->students],
    ['word' => get_string('teachers', 'local_wwadmin'), 'fact' => (string) $counts->teachers],
    ['word' => get_string('courses', 'local_wwadmin'), 'fact' => (string) $counts->courses],
    ['word' => get_string('attendance', 'local_wwadmin'), 'fact' => \local_wwadmin\snapshot::format_rate($counts->attendance)],
    ['word' => get_string('overdue', 'local_wwadmin'), 'fact' => (string) $counts->overdue],
];

\local_wwadmin\ui::render('school', $url, ['rows' => $rows]);
