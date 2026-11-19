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

namespace local_wwpoints;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Points, levels, avatars, and goals.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(points::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(levels::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(avatar::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(goal::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_wwpoints\privacy\provider::class)]
final class points_test extends \advanced_testcase {
    /**
     * Real events write one ledger row, and the same event does not write again.
     */
    public function test_real_events_award_points_once(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1, 'fullname' => 'North room']);
        $student = $this->getDataGenerator()->create_user();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $submission = $this->submit_assignment($course, $student);
        $this->assertSame(10, points::total($student->id));
        $assign = $submission->assign;
        \mod_assign\event\assessable_submitted::create_from_submission(
            $assign,
            $assign->get_user_submission($student->id, false),
            false
        )->trigger();
        $this->assertSame(1, $DB->count_records('local_wwpoints_ledger', [
            'userid' => $student->id,
            'eventname' => '\mod_assign\event\assessable_submitted',
        ]));

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $this->setUser($student);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $student->id);
        $this->assertSame(10, points::total($student->id));
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->assertSame(15, points::total($student->id));
        $record = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $cm->id,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
        \core\event\course_module_completion_updated::create([
            'objectid' => $record->id,
            'context' => \context_module::instance($cm->id),
            'relateduserid' => $student->id,
            'other' => [
                'relateduserid' => $student->id,
                'overrideby' => null,
                'completionstate' => COMPLETION_COMPLETE,
            ],
        ])->trigger();
        $this->assertSame(15, points::total($student->id));

        $this->setUser($teacher);
        \local_wwattendance\attendance::save($course->id, [$student->id => 'absent']);
        $this->assertSame(15, points::total($student->id));
        \local_wwattendance\attendance::save($course->id, [$student->id => 'present']);
        $this->assertSame(20, points::total($student->id));
        \local_wwattendance\attendance::save($course->id, [$student->id => 'present']);
        $this->assertSame(20, points::total($student->id));

        $this->setAdminUser();
        $badge = $this->getDataGenerator()->get_plugin_generator('core_badges')->create_badge([
            'name' => 'North star',
            'type' => BADGE_TYPE_COURSE,
            'courseid' => $course->id,
        ]);
        $badge->issue($student->id, true);
        $issued = $DB->get_record('badge_issued', ['badgeid' => $badge->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame(35, points::total($student->id));
        $row = $DB->get_record('local_wwpoints_ledger', ['sourcekey' => 'badge:' . $issued->id], '*', MUST_EXIST);
        $this->assertSame((string) $course->id, (string) $row->courseid);
        \core\event\badge_awarded::create([
            'context' => \context_course::instance($course->id),
            'objectid' => $badge->id,
            'relateduserid' => $student->id,
            'other' => ['dateexpire' => null, 'badgeissuedid' => $issued->id],
        ])->trigger();
        $this->assertSame(35, points::total($student->id));
        $this->assertSame(4, $DB->count_records('local_wwpoints_ledger', ['userid' => $student->id]));
    }

    /**
     * The level follows the stored threshold table.
     */
    public function test_level_comes_from_config(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        points::record($user->id, 0, 5, 'Five', 'manual', 'level-five');

        set_config('levels', "1=0\n2=4\n9=100", 'local_wwpoints');
        $this->assertSame(2, levels::for_points(points::total($user->id)));

        set_config('levels', "1=0\n2=50", 'local_wwpoints');
        $this->assertSame(1, levels::for_points(points::total($user->id)));
    }

    /**
     * A teacher cannot award points outside their own courses.
     */
    public function test_a_teacher_cannot_award_points_outside_their_courses(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);

        try {
            points::adjust($schools->southcourse->id, $schools->southstudent->id, 5, 'No', false);
            $this->fail('A teacher awarded points in another course');
        } catch (\required_capability_exception $error) {
            $this->assertNotEmpty($error->errorcode);
        }
        $this->assertSame(0, points::total($schools->southstudent->id));

        try {
            points::adjust($schools->northcourse->id, $schools->southstudent->id, 5, 'No', false);
            $this->fail('A teacher awarded points to a student in another course');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidaward', $error->errorcode);
        }

        try {
            points::adjust($schools->northcourse->id, $schools->northstudent->id, 5, '   ', false);
            $this->fail('Points were stored without a reason');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidaward', $error->errorcode);
        }

        points::adjust($schools->northcourse->id, $schools->northstudent->id, 4, 'Help', false);
        points::adjust($schools->northcourse->id, $schools->northstudent->id, 1, 'Oops', true);
        $this->assertSame(3, points::course_total($schools->northstudent->id, $schools->northcourse->id));
        $this->assertSame(0, points::course_total($schools->northstudent->id, $schools->southcourse->id));
        $rows = $DB->get_records('local_wwpoints_ledger', ['userid' => $schools->northstudent->id], 'id ASC');
        $this->assertCount(2, $rows);
        $reasons = array_column($rows, 'reason');
        $this->assertSame(['Help', 'Oops'], array_values($reasons));
        $this->assertSame('-1', (string) end($rows)->amount);
    }

    /**
     * A student cannot change another person's avatar, or a locked part.
     */
    public function test_a_student_cannot_change_another_avatar(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northstudent);
        avatar::save($schools->northstudent->id, ['face' => 'oval', 'skin' => 'honey']);
        $this->assertSame('oval', avatar::parts_for($schools->northstudent->id)['face']);
        $this->assertStringContainsString('#c68642', avatar::svg_for($schools->northstudent->id));

        try {
            avatar::save($schools->southstudent->id, ['face' => 'square']);
            $this->fail('A student changed another avatar');
        } catch (\moodle_exception $error) {
            $this->assertSame('cannotview', $error->errorcode);
        }
        $this->assertFalse($DB->record_exists('local_wwpoints_avatar', ['userid' => $schools->southstudent->id]));
        $this->assertSame('round', avatar::parts_for($schools->southstudent->id)['face']);

        try {
            avatar::save($schools->northstudent->id, ['accessory' => 'star']);
            $this->fail('A locked part was saved');
        } catch (\moodle_exception $error) {
            $this->assertSame('locked', $error->errorcode);
        }
        $this->assertSame('none', avatar::parts_for($schools->northstudent->id)['accessory']);
        $this->assertNotContains('star', avatar::choices(1)['accessory']);
        $this->assertContains('star', avatar::choices(4)['accessory']);
    }

    /**
     * Goals and points from one school are not visible in another.
     */
    public function test_goals_and_points_do_not_cross_categories(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $end = goal::day_end(date('Y-m-d', time() + 10 * DAYSECS));

        $this->setUser($schools->northteacher);
        goal::save_course($schools->northcourse->id, 'North river', 100, $end);
        try {
            goal::save_course($schools->southcourse->id, 'South river', 100, $end);
            $this->fail('A teacher set a goal in another course');
        } catch (\required_capability_exception $error) {
            $this->assertNotEmpty($error->errorcode);
        }
        points::adjust($schools->northcourse->id, $schools->northstudent->id, 40, 'Class help', false);

        $this->setUser($schools->southteacher);
        goal::save_course($schools->southcourse->id, 'South river', 100, $end);
        points::adjust($schools->southcourse->id, $schools->southstudent->id, 80, 'Other help', false);

        $this->setUser($schools->northadmin);
        goal::save_category($schools->north->id, 'North school', 200, $end);
        try {
            goal::save_category($schools->south->id, 'South school', 200, $end);
            $this->fail('A school administrator set the other school goal');
        } catch (\required_capability_exception $error) {
            $this->assertNotEmpty($error->errorcode);
        }
        $this->setUser($schools->southadmin);
        goal::save_category($schools->south->id, 'South school', 200, $end);

        $this->setUser($schools->northstudent);
        avatar::save($schools->northstudent->id, ['hair' => 'long']);
        $this->setUser($schools->southstudent);
        avatar::save($schools->southstudent->id, ['hair' => 'curly']);
        points::record($schools->southstudent->id, 0, 7, 'Site south', 'manual', 'site-south');

        $bars = goal::bars_for_user($schools->northstudent->id);
        $byname = [];
        foreach ($bars as $bar) {
            $byname[$bar['name']] = $bar['percent'];
        }
        $this->assertSame(40, $byname['North river']);
        $this->assertSame(20, $byname['North school']);
        $this->assertArrayNotHasKey('South river', $byname);
        $this->assertArrayNotHasKey('South school', $byname);

        $teacherbars = array_column(goal::bars_for_teacher($schools->northteacher->id), 'name');
        $this->assertContains('North river', $teacherbars);
        $this->assertContains('North school', $teacherbars);
        $this->assertNotContains('South river', $teacherbars);

        $export = \local_wwadmin\exporter::gather($schools->north->id);
        $this->assertContains('Class help', array_column($export['points'], 'reason'));
        $this->assertNotContains('Other help', array_column($export['points'], 'reason'));
        $this->assertNotContains('site-south', array_column($export['points'], 'sourcekey'));
        $this->assertNotContains((int) $schools->southcourse->id, array_column($export['points'], 'courseid'));
        $this->assertContains((int) $schools->northstudent->id, array_column($export['avatars'], 'userid'));
        $this->assertNotContains((int) $schools->southstudent->id, array_column($export['avatars'], 'userid'));
        $this->assertContains('North river', array_column($export['goals'], 'name'));
        $this->assertContains('North school', array_column($export['goals'], 'name'));
        $this->assertNotContains('South river', array_column($export['goals'], 'name'));
        $this->assertNotContains('South school', array_column($export['goals'], 'name'));

        $DB->set_field('local_wwpoints_goal', 'timeend', time() - 10, ['courseid' => $schools->northcourse->id]);
        $frozen = goal::progress(goal::for_course($schools->northcourse->id));
        $this->assertSame(0, $frozen['percent']);
    }

    /**
     * Export and delete cover the ledger, the avatar, and the goal author.
     */
    public function test_privacy_export_and_delete(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $end = goal::day_end(date('Y-m-d', time() + 5 * DAYSECS));
        $this->setUser($schools->northteacher);
        points::adjust($schools->northcourse->id, $schools->northstudent->id, 2, 'Noted', false);
        goal::save_course($schools->northcourse->id, 'Keep', 10, $end);
        $this->setUser($schools->northstudent);
        avatar::save($schools->northstudent->id, ['eyes' => 'wide']);
        points::record($schools->northstudent->id, 0, 1, 'Site', 'manual', 'site-north');

        $student = $DB->get_record('user', ['id' => $schools->northstudent->id]);
        $contextlist = \local_wwpoints\privacy\provider::get_contexts_for_userid($student->id);
        $ids = array_map('intval', $contextlist->get_contextids());
        $this->assertContains(\context_course::instance($schools->northcourse->id)->id, $ids);
        $this->assertContains(\context_user::instance($student->id)->id, $ids);

        writer::reset();
        \local_wwpoints\privacy\provider::export_user_data(new approved_contextlist(
            $student,
            'local_wwpoints',
            $ids
        ));
        $coursedata = writer::with_context(\context_course::instance($schools->northcourse->id))->get_data([
            get_string('pluginname', 'local_wwpoints'),
        ]);
        $this->assertCount(1, $coursedata->points);
        $this->assertSame('Noted', $coursedata->points[0]->reason);
        $userdata = writer::with_context(\context_user::instance($student->id))->get_data([
            get_string('pluginname', 'local_wwpoints'),
        ]);
        $this->assertSame('wide', $userdata->avatar->eyes);

        \local_wwpoints\privacy\provider::delete_data_for_user(new approved_contextlist(
            $student,
            'local_wwpoints',
            $ids
        ));
        $this->assertFalse($DB->record_exists('local_wwpoints_ledger', ['userid' => $student->id]));
        $this->assertFalse($DB->record_exists('local_wwpoints_avatar', ['userid' => $student->id]));
        $goal = goal::for_course($schools->northcourse->id);
        $this->assertSame('Keep', $goal->name);

        $teacher = $DB->get_record('user', ['id' => $schools->northteacher->id]);
        $teachercontexts = \local_wwpoints\privacy\provider::get_contexts_for_userid($teacher->id)->get_contextids();
        \local_wwpoints\privacy\provider::delete_data_for_user(new approved_contextlist(
            $teacher,
            'local_wwpoints',
            $teachercontexts
        ));
        $goal = goal::for_course($schools->northcourse->id);
        $this->assertSame('0', (string) $goal->createdby);
        $this->assertSame('Keep', $goal->name);
    }

    /**
     * The student home, teacher home, and parent home read the stored points.
     */
    public function test_homes_show_the_stored_points(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->enablebadges = 1;
        $schools = $this->schools();
        $end = goal::day_end(date('Y-m-d', time() + 3 * DAYSECS));
        $this->setUser($schools->northteacher);
        points::adjust($schools->northcourse->id, $schools->northstudent->id, 30, 'Shown', false);
        goal::save_course($schools->northcourse->id, 'Shown goal', 60, $end);

        $this->setUser($schools->northstudent);
        $tiles = [];
        foreach (\local_wolfwhale\home::get_tiles() as $tile) {
            $tiles[$tile['id']] = $tile;
        }
        $this->assertSame('30', $tiles['points']['fact']);
        $this->assertSame('2', $tiles['points']['levelfact']);
        $bars = \local_wwpoints\goal::bars_for_user($schools->northstudent->id);
        $this->assertSame('Shown goal', $bars[0]['name']);
        $this->assertSame(50, $bars[0]['percent']);

        $this->setUser($schools->northteacher);
        $teachertiles = [];
        foreach (\local_wolfwhale\teacher::get_tiles() as $tile) {
            $teachertiles[$tile['id']] = $tile;
        }
        $this->assertSame('Shown goal', $teachertiles['goal']['fact']);
        $this->assertStringContainsString('/local/wwpoints/award.php', $teachertiles['points']['url']);

        $parent = $this->getDataGenerator()->create_user();
        \local_wolfwhale\parents::install_role();
        role_assign(
            \local_wolfwhale\parents::role_id(),
            $parent->id,
            \context_user::instance($schools->northstudent->id)->id
        );
        $this->setUser($parent);
        $parenttiles = [];
        foreach (\local_wolfwhale\parents::get_tiles($schools->northstudent->id) as $tile) {
            $parenttiles[$tile['id']] = $tile;
        }
        $this->assertSame('30', $parenttiles['badges']['points']);
        $this->assertSame('2', $parenttiles['badges']['level']);
    }

    /**
     * Two schools, each with an administrator, a teacher, and a student.
     *
     * @return \stdClass
     */
    private function schools(): \stdClass {
        global $DB;

        $north = $this->getDataGenerator()->create_category(['name' => 'North School']);
        $south = $this->getDataGenerator()->create_category(['name' => 'South School']);
        $northcourse = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'fullname' => 'North room',
        ]);
        $southcourse = $this->getDataGenerator()->create_course([
            'category' => $south->id,
            'fullname' => 'South room',
        ]);
        $northteacher = $this->getDataGenerator()->create_user(['username' => 'northteacher']);
        $southteacher = $this->getDataGenerator()->create_user(['username' => 'southteacher']);
        $northstudent = $this->getDataGenerator()->create_user(['username' => 'northstudent']);
        $southstudent = $this->getDataGenerator()->create_user(['username' => 'southstudent']);
        $northadmin = $this->getDataGenerator()->create_user(['username' => 'northadmin']);
        $southadmin = $this->getDataGenerator()->create_user(['username' => 'southadmin']);
        $this->getDataGenerator()->enrol_user($northteacher->id, $northcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($southteacher->id, $southcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($northstudent->id, $northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($southstudent->id, $southcourse->id, 'student');
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $northadmin->id, \context_coursecat::instance($north->id)->id);
        role_assign($manager, $southadmin->id, \context_coursecat::instance($south->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        return (object) [
            'north' => $north,
            'south' => $south,
            'northcourse' => $northcourse,
            'southcourse' => $southcourse,
            'northteacher' => $northteacher,
            'southteacher' => $southteacher,
            'northstudent' => $northstudent,
            'southstudent' => $southstudent,
            'northadmin' => $northadmin,
            'southadmin' => $southadmin,
        ];
    }

    /**
     * Submit one assignment as the student. The returned object carries the assign.
     *
     * @param \stdClass $course
     * @param \stdClass $student
     * @return \stdClass
     */
    private function submit_assignment(\stdClass $course, \stdClass $student): \stdClass {
        set_config('submissionreceipts', 0, 'assign');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $instance = $generator->create_instance([
            'course' => $course->id,
            'name' => 'North essay',
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 1,
            'sendnotifications' => 0,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
        $this->setUser($student);
        $submission = $assign->get_user_submission($student->id, true);
        $data = (object) [
            'onlinetext_editor' => [
                'itemid' => file_get_unused_draft_itemid(),
                'text' => 'Hello',
                'format' => FORMAT_HTML,
            ],
        ];
        $assign->get_submission_plugin_by_type('onlinetext')->save($submission, $data);
        $data->submissionstatement = 1;
        $assign->submit_for_grading($data, []);
        $data->assign = $assign;
        return $data;
    }
}
