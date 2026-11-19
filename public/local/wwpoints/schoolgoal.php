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
 * Set the goal for one school.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
\local_wwadmin\scope::require_admin();

$categoryid = optional_param('category', 0, PARAM_INT);
$roots = \local_wwadmin\scope::managed_root_ids((int) $USER->id);
if (!$categoryid && count($roots) === 1) {
    $categoryid = (int) reset($roots);
}

$url = new moodle_url('/local/wwpoints/schoolgoal.php');
$data = [];
if ($categoryid) {
    $url->param('category', $categoryid);
    $context = \context_coursecat::instance($categoryid);
    require_capability('local/wwpoints:setcategorygoal', $context);
    if (data_submitted()) {
        require_sesskey();
        \local_wwpoints\goal::save_category(
            $categoryid,
            required_param('name', PARAM_TEXT),
            required_param('target', PARAM_INT),
            \local_wwpoints\goal::day_end(required_param('end', PARAM_RAW_TRIMMED))
        );
        redirect($url);
    }
    $category = $DB->get_record('course_categories', ['id' => $categoryid], 'id, name', MUST_EXIST);
    $goal = \local_wwpoints\goal::for_category($categoryid);
    $title = \local_wwpoints\points::plain($category->name);
    $data['form'] = true;
    $data['name'] = $goal ? \local_wwpoints\points::plain($goal->name) : '';
    $data['target'] = $goal ? (int) $goal->target : '';
    $data['end'] = $goal ? \local_wwpoints\goal::day_value((int) $goal->timeend) : '';
    $data['goals'] = $goal ? [\local_wwpoints\goal::progress($goal)] : [];
} else {
    $rows = [];
    foreach ($roots as $id) {
        $catcontext = \context_coursecat::instance($id);
        if (!has_capability('local/wwpoints:setcategorygoal', $catcontext)) {
            continue;
        }
        $category = $DB->get_record('course_categories', ['id' => $id], 'id, name', MUST_EXIST);
        $goal = \local_wwpoints\goal::for_category($id);
        $rows[] = [
            'word' => \local_wwpoints\points::plain($category->name),
            'fact' => $goal ? \local_wwpoints\points::plain($goal->name) : get_string('none', 'local_wwpoints'),
            'url' => (new moodle_url('/local/wwpoints/schoolgoal.php', ['category' => $id]))->out(false),
        ];
    }
    $context = context_user::instance($USER->id);
    $title = get_string('goal', 'local_wwpoints');
    $data['rows'] = $rows;
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('base');
$PAGE->set_secondary_navigation(false);
$PAGE->set_title($title);
$PAGE->set_heading($title);

$data['action'] = $url->out(false);
$data['sesskey'] = sesskey();
$data['title'] = $title;
$data['nameword'] = get_string('name', 'local_wwpoints');
$data['targetword'] = get_string('target', 'local_wwpoints');
$data['endword'] = get_string('end', 'local_wwpoints');
$data['save'] = get_string('save', 'local_wwpoints');
$data['read'] = get_string('read', 'local_wwpoints');
$speech = [$title];
foreach ($data['goals'] ?? [] as $bar) {
    $speech[] = $bar['speech'];
}
$data['speech'] = implode(' ', $speech);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wwpoints/goal', $data);
echo $OUTPUT->footer();
