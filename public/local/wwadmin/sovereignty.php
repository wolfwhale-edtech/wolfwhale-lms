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
 * Data sovereignty for one Nation.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$url = new moodle_url('/local/wwadmin/sovereignty.php');

if (data_submitted() && optional_param('export', 0, PARAM_BOOL)) {
    require_sesskey();
    $categoryid = required_param('category', PARAM_INT);
    \local_wwadmin\exporter::build($categoryid);
    $url->param('category', $categoryid);
    redirect($url);
}

$categoryid = \local_wwadmin\ui::category_param();
if (!$categoryid) {
    $data = [
        'rows' => \local_wwadmin\ui::school_rows($url),
    ];
    if (is_siteadmin()) {
        $data['actions'] = [[
            'word' => get_string('nations', 'local_wwadmin'),
            'url' => (new moodle_url('/local/wwadmin/nations.php'))->out(false),
        ]];
    }
    \local_wwadmin\ui::render('sovereignty', $url, $data);
    exit;
}

$url->param('category', $categoryid);
$data = \local_wwadmin\sovereignty::export($categoryid);
$data['action'] = $url->out(false);
$roots = \local_wwadmin\scope::managed_root_ids((int) $USER->id);
if (count($roots) > 1) {
    $data['back'] = (new moodle_url('/local/wwadmin/sovereignty.php'))->out(false);
    $data['backword'] = get_string('sovereignty', 'local_wwadmin');
}
if (is_siteadmin()) {
    $data['nationsurl'] = (new moodle_url('/local/wwadmin/nations.php'))->out(false);
    $data['nationsword'] = get_string('nations', 'local_wwadmin');
}

$context = \context_coursecat::instance($categoryid);
\local_wwadmin\home::setup('sovereignty', $url, $context);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwadmin/sovereignty', $data);
echo $OUTPUT->footer();
