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
 * Link a parent to a child.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();

if (!\local_wolfwhale\parents::can_link()) {
    throw new moodle_exception('cannotview', 'local_wolfwhale');
}

$return = new moodle_url('/local/wolfwhale/linkparent.php');
$error = '';

if (optional_param('link', 0, PARAM_BOOL)) {
    require_sesskey();
    $parent = \local_wolfwhale\parents::user_by_username(optional_param('parentusername', '', PARAM_USERNAME));
    $childid = optional_param('childid', 0, PARAM_INT);
    if (!$parent) {
        $error = get_string('unknown', 'local_wolfwhale');
    } else {
        \local_wolfwhale\parents::link((int) $parent->id, $childid);
        redirect($return);
    }
}

if (optional_param('unlink', 0, PARAM_BOOL)) {
    require_sesskey();
    \local_wolfwhale\parents::unlink(
        optional_param('parentid', 0, PARAM_INT),
        optional_param('childid', 0, PARAM_INT)
    );
    redirect($return);
}

$children = [];
foreach (\local_wolfwhale\parents::linkable_children() as $child) {
    $children[] = [
        'id' => (int) $child->id,
        'name' => fullname($child),
    ];
}
$links = [];
foreach (\local_wolfwhale\parents::managed_links() as $link) {
    $links[] = $link + ['sesskey' => sesskey()];
}

\local_wolfwhale\parents::setup_page('parents', $return);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wolfwhale/link', [
    'sesskey' => sesskey(),
    'error' => $error,
    'children' => $children,
    'haschildren' => $children !== [],
    'links' => $links,
    'haslinks' => $links !== [],
    'parent' => get_string('parent', 'local_wolfwhale'),
    'child' => get_string('child', 'local_wolfwhale'),
    'link' => get_string('link', 'local_wolfwhale'),
    'unlink' => get_string('unlink', 'local_wolfwhale'),
    'none' => get_string('none', 'local_wolfwhale'),
]);
echo $OUTPUT->footer();
