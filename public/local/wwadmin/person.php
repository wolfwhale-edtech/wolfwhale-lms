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
 * Add, suspend, or remove one person in a school.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$categoryid = required_param('category', PARAM_INT);
$userid = optional_param('id', 0, PARAM_INT);
\local_wwadmin\scope::require($categoryid, 'local/wwadmin:managepeople');
$url = new moodle_url('/local/wwadmin/person.php', ['category' => $categoryid]);
if ($userid) {
    $url->param('id', $userid);
}
$back = new moodle_url('/local/wwadmin/people.php', ['category' => $categoryid]);

if ($userid && data_submitted()) {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    if ($action === 'suspend') {
        \local_wwadmin\people::suspend($categoryid, $userid);
    } else if ($action === 'remove') {
        \local_wwadmin\people::remove($categoryid, $userid);
    }
    redirect($back);
}

if (!$userid && data_submitted()) {
    require_sesskey();
    \local_wwadmin\people::add(
        $categoryid,
        required_param('username', PARAM_USERNAME),
        required_param('firstname', PARAM_NOTAGS),
        required_param('lastname', PARAM_NOTAGS),
        required_param('email', PARAM_EMAIL),
        required_param('password', PARAM_RAW),
        required_param('course', PARAM_INT),
        required_param('role', PARAM_ALPHANUMEXT)
    );
    redirect($back);
}

$data = ['back' => $back->out(false), 'backword' => get_string('people', 'local_wwadmin')];
if ($userid) {
    $users = \local_wwadmin\people::list_users($categoryid);
    if (!isset($users[$userid]) || (int) $userid === (int) $USER->id || is_siteadmin($userid)) {
        throw new moodle_exception('notinschool', 'local_wwadmin');
    }
    $data['word'] = fullname($users[$userid]);
    $data['forms'] = [
        [
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            'hidden' => [
                ['name' => 'category', 'value' => $categoryid],
                ['name' => 'id', 'value' => $userid],
                ['name' => 'action', 'value' => 'suspend'],
            ],
            'submit' => get_string('suspend', 'local_wwadmin'),
        ],
        [
            'action' => $url->out(false),
            'sesskey' => sesskey(),
            'hidden' => [
                ['name' => 'category', 'value' => $categoryid],
                ['name' => 'id', 'value' => $userid],
                ['name' => 'action', 'value' => 'remove'],
            ],
            'submit' => get_string('remove', 'local_wwadmin'),
        ],
    ];
} else {
    $courses = [];
    foreach (\local_wwadmin\people::course_choices($categoryid) as $course) {
        $courses[] = ['value' => $course->id, 'label' => $course->fullname];
    }
    $roles = [];
    foreach (\local_wwadmin\people::ROLES as $role) {
        $roles[] = ['value' => $role, 'label' => get_string($role, 'local_wwadmin')];
    }
    $data['form'] = [
        'action' => $url->out(false),
        'sesskey' => sesskey(),
        'submit' => get_string('add', 'local_wwadmin'),
        'fields' => [
            ['name' => 'firstname', 'label' => get_string('firstname', 'local_wwadmin'), 'text' => true],
            ['name' => 'lastname', 'label' => get_string('lastname', 'local_wwadmin'), 'text' => true],
            ['name' => 'email', 'label' => get_string('email', 'local_wwadmin'), 'text' => true],
            ['name' => 'username', 'label' => get_string('username', 'local_wwadmin'), 'text' => true],
            ['name' => 'password', 'label' => get_string('password', 'local_wwadmin'), 'password' => true],
            ['name' => 'course', 'label' => get_string('course', 'local_wwadmin'), 'select' => true, 'options' => $courses],
            ['name' => 'role', 'label' => get_string('role', 'local_wwadmin'), 'select' => true, 'options' => $roles],
        ],
    ];
}

\local_wwadmin\ui::render('people', $url, $data);
