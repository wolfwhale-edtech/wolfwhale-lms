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

namespace local_wwtreaty;

/**
 * Permissions, import checks, lesson tagging, and coverage.
 *
 * Sample rows in this file are fixtures. They are not an official outcome list.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(outcome::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tag::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(coverage::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(school::class)]
final class treaty_test extends \advanced_testcase {
    /**
     * A fresh site has the tables and no outcome wording.
     */
    public function test_a_new_site_has_no_outcomes(): void {
        global $DB;

        $this->resetAfterTest();
        $this->assertSame(0, $DB->count_records('local_wwtreaty'));
        $this->assertSame(0, $DB->count_records('local_wwtreaty_tag'));
        $this->assertSame(0, $DB->count_records('local_wwtreaty_evidence'));
        $this->assertSame("code,grade,theme,title,description,active\n", outcome::CSV_HEADER);
        $this->assertStringContainsString('"code": ""', outcome::JSON_TEMPLATE);
        $this->assertStringNotContainsString('Grade 4 Sample Outcome', outcome::JSON_TEMPLATE);
        $this->assertStringNotContainsString('Grade 4 Sample Outcome', outcome::CSV_HEADER);
    }

    /**
     * A student cannot change outcomes, tags, or evidence. A teacher cannot tag another course.
     */
    public function test_students_cannot_edit_and_teachers_stay_in_their_course(): void {
        global $DB;

        $this->resetAfterTest();
        $north = $this->getDataGenerator()->create_category(['name' => 'North School']);
        $south = $this->getDataGenerator()->create_category(['name' => 'South School']);
        $northcourse = $this->getDataGenerator()->create_course(['category' => $north->id, 'fullname' => 'North room']);
        $southcourse = $this->getDataGenerator()->create_course(['category' => $south->id, 'fullname' => 'South room']);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $northcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $northcourse->id, 'student');
        $page = $this->getDataGenerator()->create_module('page', ['course' => $northcourse->id, 'name' => 'North page']);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $southpage = $this->getDataGenerator()->create_module('page', ['course' => $southcourse->id, 'name' => 'South page']);
        $southcm = get_coursemodule_from_instance('page', $southpage->id);

        $this->setUser($teacher);
        $id = outcome::save($north->id, $this->sample());
        $this->assertGreaterThan(0, $id);

        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        outcome::save($north->id, $this->sample('TE-4.2'));
        // The next lines are reached only if the exception is missing. PHPUnit stops here.
    }

    /**
     * The student and the other course are refused. Split from the exception above.
     */
    public function test_a_teacher_cannot_tag_another_course_and_a_student_cannot_tag(): void {
        $this->resetAfterTest();
        $built = $this->two_schools();
        $this->setUser($built->teacher);
        $id = outcome::save($built->north->id, $this->sample());
        tag::set($built->northcm, $id, true);
        $this->assertTrue($this->is_tagged($id, $built->northcm));

        $this->setUser($built->student);
        try {
            tag::set($built->northcm, $id, false);
            $this->fail('A student must not change a tag');
        } catch (\required_capability_exception $error) {
            $this->assertSame('nopermissions', $error->errorcode);
        }
        $this->assertTrue($this->is_tagged($id, $built->northcm));

        $this->setUser($built->teacher);
        try {
            tag::set($built->southcm, $id, true);
            $this->fail('A teacher must not tag another course');
        } catch (\required_capability_exception $error) {
            $this->assertSame('nopermissions', $error->errorcode);
        }
        $this->assertSame(0, $this->tag_count($built->southcm));

        coverage::meet($built->northcourse->id, $id, $built->student->id);
        $this->setUser($built->student);
        try {
            coverage::meet($built->northcourse->id, $id, $built->student->id);
            $this->fail('A student must not record evidence');
        } catch (\required_capability_exception $error) {
            $this->assertSame('nopermissions', $error->errorcode);
        }
    }

    /**
     * A school administrator cannot load or read another school's outcomes.
     * An editing teacher can. A non-editing teacher can tag and cannot import.
     */
    public function test_admins_manage_their_own_school(): void {
        global $DB;

        $this->resetAfterTest();
        $built = $this->two_schools();
        $plain = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($plain->id, $built->northcourse->id, 'teacher');
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($built->northadmin);
        $imported = outcome::import($built->north->id, $this->csv($this->sample()));
        $this->assertSame(1, $imported);
        $stored = outcome::records($built->north->id, true);
        $row = reset($stored);
        $this->assertSame('TE-4.1', $row->code);
        $this->assertSame('Grade 4 Sample Outcome', $row->title);
        $this->assertSame((int) $built->north->id, (int) $row->categoryid);

        $this->setUser($built->southadmin);
        try {
            outcome::import($built->north->id, $this->csv($this->sample('TE-9.1')));
            $this->fail('Another school must not import');
        } catch (\required_capability_exception $error) {
            $this->assertSame('nopermissions', $error->errorcode);
        }
        try {
            outcome::get_for((int) $row->id, $built->north->id);
            $this->fail('Another school must not open this outcome');
        } catch (\required_capability_exception $error) {
            $this->assertSame('nopermissions', $error->errorcode);
        }
        $this->assertSame(1, $DB->count_records('local_wwtreaty'));

        $this->setUser($plain);
        try {
            outcome::import($built->north->id, $this->csv($this->sample('TE-9.1')));
            $this->fail('A non-editing teacher must not import');
        } catch (\required_capability_exception $error) {
            $this->assertSame('nopermissions', $error->errorcode);
        }
        $this->setUser($built->teacher);
        $second = outcome::save($built->north->id, $this->sample('TE-4.2'));
        $this->assertGreaterThan(0, $second);
        $this->setUser($plain);
        tag::set($built->northcm, $second, true);
        $this->assertTrue($this->is_tagged($second, $built->northcm));
    }

    /**
     * CSV and JSON update the same code. A bad file writes nothing.
     */
    public function test_import_validates_the_whole_file(): void {
        global $DB;

        $this->resetAfterTest();
        $built = $this->two_schools();
        $this->setUser($built->teacher);

        $count = outcome::import($built->north->id, $this->csv($this->sample()));
        $this->assertSame(1, $count);
        $first = $DB->get_record('local_wwtreaty', ['code' => 'TE-4.1'], '*', MUST_EXIST);

        $json = json_encode([
            [
                'code' => 'TE-4.1',
                'grade' => '4',
                'theme' => 'Sample pillar',
                'title' => 'Grade 4 Sample Outcome Revised',
                'description' => 'Revised sample',
                'active' => 0,
            ],
            $this->sample('TE-4.2'),
        ]);
        $this->assertSame(2, outcome::import($built->north->id, $json));
        $updated = $DB->get_record('local_wwtreaty', ['code' => 'TE-4.1'], '*', MUST_EXIST);
        $this->assertSame((int) $first->id, (int) $updated->id);
        $this->assertSame('Grade 4 Sample Outcome Revised', $updated->title);
        $this->assertSame('0', (string) $updated->active);
        $this->assertTrue($DB->record_exists('local_wwtreaty', ['code' => 'TE-4.2', 'active' => 1]));

        $before = $DB->count_records('local_wwtreaty');
        $bad = "code,grade,theme,title,description,active\n"
            . "TE-8.1,8,Sample pillar,Grade 8 Sample Outcome,Sample description,1\n"
            . ",8,Sample pillar,Missing code,Sample description,1\n";
        try {
            outcome::import($built->north->id, $bad);
            $this->fail('A missing code must be refused');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidimport', $error->errorcode);
            $this->assertSame('Code', $error->a);
        }
        $this->assertSame($before, $DB->count_records('local_wwtreaty'));
        $this->assertFalse($DB->record_exists('local_wwtreaty', ['code' => 'TE-8.1']));

        $duplicate = $this->csv($this->sample('TE-7.1')) . "TE-7.1,7,Sample pillar,Grade 7 Sample Outcome,Again,1\n";
        try {
            outcome::import($built->north->id, $duplicate);
            $this->fail('A repeated code must be refused');
        } catch (\moodle_exception $error) {
            $this->assertSame('TE-7.1', $error->a);
        }
        $this->assertFalse($DB->record_exists('local_wwtreaty', ['code' => 'TE-7.1']));

        try {
            outcome::import($built->north->id, '{');
            $this->fail('Broken JSON must be refused');
        } catch (\moodle_exception $error) {
            $this->assertSame('JSON', $error->a);
        }
        try {
            outcome::import($built->north->id, outcome::JSON_TEMPLATE);
            $this->fail('The blank template must not create an outcome');
        } catch (\moodle_exception $error) {
            $this->assertSame('Code', $error->a);
        }
        try {
            outcome::import($built->north->id, outcome::CSV_HEADER);
            $this->fail('A header with no rows must be refused');
        } catch (\moodle_exception $error) {
            $this->assertSame('File', $error->a);
        }
        $this->assertSame($before, $DB->count_records('local_wwtreaty'));
    }

    /**
     * One activity can carry several outcomes, and one outcome can be on several activities.
     */
    public function test_lessons_can_carry_more_than_one_outcome(): void {
        global $DB;

        $this->resetAfterTest();
        $built = $this->two_schools();
        $secondpage = $this->getDataGenerator()->create_module('page', [
            'course' => $built->northcourse->id,
            'name' => 'North next',
        ]);
        $second = get_coursemodule_from_instance('page', $secondpage->id);
        $this->setUser($built->teacher);
        $first = outcome::save($built->north->id, $this->sample());
        $other = outcome::save($built->north->id, $this->sample('TE-4.2'));
        tag::set($built->northcm, $first, true);
        tag::set($built->northcm, $other, true);
        tag::set((int) $second->id, $first, true);
        tag::set($built->northcm, $first, true);
        $this->assertSame(2, tag::count_for_activity($built->northcm));
        $this->assertSame(2, $DB->count_records('local_wwtreaty_tag', ['outcomeid' => $first]));
        tag::set($built->northcm, $other, false);
        $this->assertSame(1, tag::count_for_activity($built->northcm));
        $this->assertFalse($this->is_tagged($other, $built->northcm));
    }

    /**
     * Coverage counts tagged outcomes, met students, and who is still missing one.
     * Evidence outside the selected term does not count. An archived outcome drops out.
     */
    public function test_coverage_counts_tagged_met_and_the_term(): void {
        global $DB;

        $this->resetAfterTest();
        $built = $this->two_schools();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $built->northcourse->id, 'student');
        $this->setAdminUser();
        $start = strtotime('2026-09-01 00:00:00 UTC');
        $end = strtotime('2026-12-18 23:59:59 UTC');
        $termid = \local_wwterms\term::create($built->north->id, 'Harvest', $start, $end);
        $southstart = strtotime('2026-01-01 00:00:00 UTC');
        $southterm = \local_wwterms\term::create($built->south->id, 'South term', $southstart, $southstart + 86400);

        $this->setUser($built->teacher);
        $first = outcome::save($built->north->id, $this->sample());
        $second = outcome::save($built->north->id, $this->sample('TE-4.2'));
        tag::set($built->northcm, $first, true);

        $open = coverage::summary($built->northcourse->id, 0);
        $bycode = $this->by_code($open['outcomes']);
        $this->assertSame('gap', $bycode['TE-4.1']['status']);
        $this->assertSame(2, $bycode['TE-4.1']['needs']);
        $this->assertSame(0, $bycode['TE-4.1']['met']);
        $this->assertTrue($bycode['TE-4.1']['tagged']);
        $this->assertSame('open', $bycode['TE-4.2']['status']);
        $this->assertFalse($bycode['TE-4.2']['tagged']);
        $this->assertSame(2, $open['gaps']);
        $this->assertCount(2, $open['needs']);

        coverage::meet($built->northcourse->id, $first, $built->student->id);
        $DB->set_field('local_wwtreaty_evidence', 'timecreated', $start + 10, [
            'outcomeid' => $first,
            'userid' => $built->student->id,
        ]);
        coverage::meet($built->northcourse->id, $first, $other->id);
        $DB->set_field('local_wwtreaty_evidence', 'timecreated', $end + 86400, [
            'outcomeid' => $first,
            'userid' => $other->id,
        ]);

        $during = coverage::summary($built->northcourse->id, $termid);
        $duringcodes = $this->by_code($during['outcomes']);
        $this->assertSame(1, $duringcodes['TE-4.1']['met']);
        $this->assertSame(1, $duringcodes['TE-4.1']['needs']);
        $this->assertSame('gap', $duringcodes['TE-4.1']['status']);
        $this->assertSame($termid, $during['termid']);
        $names = array_column($during['needs'], 'codes', 'userid');
        $this->assertNotContains('TE-4.1', $names[$built->student->id] ?? []);
        $this->assertContains('TE-4.1', $names[$other->id]);

        $all = coverage::summary($built->northcourse->id, 0);
        $allcodes = $this->by_code($all['outcomes']);
        $this->assertSame(2, $allcodes['TE-4.1']['met']);
        $this->assertSame('met', $allcodes['TE-4.1']['status']);

        try {
            coverage::summary($built->northcourse->id, $southterm);
            $this->fail('A term from another school must be refused');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidrecord', $error->errorcode);
        }
    }

    /**
     * Completing a tagged activity writes one evidence row. Clearing it removes that row only.
     */
    public function test_completion_writes_evidence_once(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');
        $north = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'enablecompletion' => 1,
            'fullname' => 'North room',
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'River page',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id);

        $this->setUser($teacher);
        $first = outcome::save($north->id, $this->sample());
        $second = outcome::save($north->id, $this->sample('TE-4.2'));

        $this->setUser($student);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->assertSame(0, $DB->count_records('local_wwtreaty_evidence'));

        $this->setUser($teacher);
        tag::set((int) $cm->id, $first, true);
        tag::set((int) $cm->id, $second, true);
        $this->assertSame(2, $DB->count_records('local_wwtreaty_evidence', ['userid' => $student->id]));

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
        $this->assertSame(2, $DB->count_records('local_wwtreaty_evidence', ['userid' => $student->id]));

        $this->setUser($student);
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $student->id);
        $this->assertSame(0, $DB->count_records('local_wwtreaty_evidence', ['userid' => $student->id]));

        $this->setUser($teacher);
        coverage::meet($course->id, $first, $student->id);
        $this->setUser($student);
        $completion->update_state($cm, COMPLETION_INCOMPLETE, $student->id);
        $manual = $DB->get_record('local_wwtreaty_evidence', [
            'outcomeid' => $first,
            'userid' => $student->id,
        ], '*', MUST_EXIST);
        $this->assertSame('0', (string) $manual->cmid);
        $this->assertSame((string) $teacher->id, (string) $manual->usermodified);

        $this->setUser($teacher);
        outcome::set_active($second, false);
        $summary = coverage::summary($course->id, 0);
        $this->assertSame(['TE-4.1'], array_column($summary['outcomes'], 'code'));
        $this->assertSame('met', $summary['outcomes'][0]['status']);
    }

    /**
     * A teacher in separate groups does not see students from another group.
     */
    public function test_separate_groups_hide_the_other_group(): void {
        global $DB;

        $this->resetAfterTest();
        $north = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'groupmode' => SEPARATEGROUPS,
            'fullname' => 'Grouped room',
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $ada = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Moss']);
        $ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben', 'lastname' => 'Moss']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($ada->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($ben->id, $course->id, 'student');
        $context = \context_course::instance($course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $roleid, $context->id);
        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'B']);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $ada->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $ben->id]);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setAdminUser();
        $id = outcome::save($north->id, $this->sample());
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Group page']);
        $cm = get_coursemodule_from_instance('page', $page->id);

        $this->setUser($teacher);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $context));
        tag::set((int) $cm->id, $id, true);
        $summary = coverage::summary($course->id, 0);
        $this->assertSame(1, $summary['outcomes'][0]['students']);
        $this->assertSame([(int) $ada->id], array_column($summary['needs'], 'userid'));
    }

    /**
     * The teacher home and the admin home both open this plugin.
     */
    public function test_homes_link_to_treaty(): void {
        $this->resetAfterTest();
        $built = $this->two_schools();
        $this->setUser($built->teacher);
        $tiles = [];
        foreach (\local_wolfwhale\teacher::get_tiles() as $tile) {
            $tiles[$tile['id']] = $tile;
        }
        $this->assertArrayHasKey('treaty', $tiles);
        $this->assertSame('None', $tiles['treaty']['fact']);
        $this->assertStringContainsString('/local/wwtreaty/coverage.php', $tiles['treaty']['url']);

        outcome::save($built->north->id, $this->sample());
        $tiles = [];
        foreach (\local_wolfwhale\teacher::get_tiles() as $tile) {
            $tiles[$tile['id']] = $tile;
        }
        $this->assertSame('1', $tiles['treaty']['fact']);

        $this->setUser($built->northadmin);
        $admin = [];
        foreach (\local_wwadmin\home::tiles() as $tile) {
            $admin[$tile['id']] = $tile;
        }
        $this->assertArrayHasKey('treaty', $admin);
        $this->assertSame('1', $admin['treaty']['fact']);
        $this->assertStringContainsString('/local/wwtreaty/index.php', $admin['treaty']['url']);

        $this->setUser($built->southadmin);
        $south = [];
        foreach (\local_wwadmin\home::tiles() as $tile) {
            $south[$tile['id']] = $tile;
        }
        $this->assertSame('None', $south['treaty']['fact']);
    }

    /**
     * Resetting completion deletes the rows without an event for each activity.
     * Automatic evidence must not keep counting. A teacher's mark stays.
     */
    public function test_a_completion_reset_drops_automatic_evidence(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/completionlib.php');
        $north = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'enablecompletion' => 1,
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id);

        $this->setUser($teacher);
        $id = outcome::save($north->id, $this->sample());
        tag::set((int) $cm->id, $id, true);
        $this->setUser($student);
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $this->setUser($teacher);
        $this->assertSame('met', coverage::summary($course->id, 0)['outcomes'][0]['status']);

        $completion->delete_all_completion_data();
        $after = coverage::summary($course->id, 0);
        $this->assertSame('gap', $after['outcomes'][0]['status']);
        $this->assertSame([(int) $student->id], array_column($after['needs'], 'userid'));
        $this->assertSame(0, $DB->count_records('local_wwtreaty_evidence', ['usermodified' => 0]));

        coverage::meet($course->id, $id, $student->id);
        $completion->delete_all_completion_data();
        $this->assertSame('met', coverage::summary($course->id, 0)['outcomes'][0]['status']);
    }

    /**
     * A future start or a past end is not a current enrolment.
     */
    public function test_enrolments_outside_their_dates_are_left_out(): void {
        global $DB;

        $this->resetAfterTest();
        $built = $this->two_schools();
        $later = $this->getDataGenerator()->create_user(['firstname' => 'Bea', 'lastname' => 'Moss']);
        $ended = $this->getDataGenerator()->create_user(['firstname' => 'Cam', 'lastname' => 'Moss']);
        $this->getDataGenerator()->enrol_user($later->id, $built->northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($ended->id, $built->northcourse->id, 'student');
        $now = \core\di::get(\core\clock::class)->time();
        $this->set_enrolment_dates($built->northcourse->id, $later->id, $now + DAYSECS, 0);
        $this->set_enrolment_dates($built->northcourse->id, $ended->id, 0, $now - 10);

        $this->setUser($built->teacher);
        $id = outcome::save($built->north->id, $this->sample());
        tag::set($built->northcm, $id, true);
        $summary = coverage::summary($built->northcourse->id, 0);
        $this->assertSame([(int) $built->student->id], array_column($summary['needs'], 'userid'));

        try {
            coverage::meet($built->northcourse->id, $id, $ended->id);
            $this->fail('An ended enrolment must not be marked');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidrecord', $error->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_wwtreaty_evidence', ['userid' => $ended->id]));
    }

    /**
     * A mark in an earlier term stays. The next term can be marked again.
     */
    public function test_a_later_term_can_be_marked_again(): void {
        global $DB;

        $this->resetAfterTest();
        $built = $this->two_schools();
        $this->setAdminUser();
        $firststart = strtotime('2026-01-01 00:00:00 UTC');
        $firstend = strtotime('2026-03-31 23:59:59 UTC');
        $secondstart = strtotime('2026-09-01 00:00:00 UTC');
        $secondend = strtotime('2026-12-18 23:59:59 UTC');
        $firstterm = \local_wwterms\term::create($built->north->id, 'First', $firststart, $firstend);
        $secondterm = \local_wwterms\term::create($built->north->id, 'Second', $secondstart, $secondend);

        $this->setUser($built->teacher);
        $id = outcome::save($built->north->id, $this->sample());
        tag::set($built->northcm, $id, true);
        coverage::meet($built->northcourse->id, $id, $built->student->id, $firstterm);
        $DB->set_field('local_wwtreaty_evidence', 'timecreated', $firststart + 10, [
            'outcomeid' => $id,
            'userid' => $built->student->id,
            'termid' => $firstterm,
        ]);

        $before = coverage::summary($built->northcourse->id, $secondterm);
        $this->assertSame('gap', $this->by_code($before['outcomes'])['TE-4.1']['status']);
        $this->assertSame([(int) $built->student->id], array_column($before['needs'], 'userid'));

        coverage::meet($built->northcourse->id, $id, $built->student->id, $secondterm);
        $after = coverage::summary($built->northcourse->id, $secondterm);
        $this->assertSame('met', $this->by_code($after['outcomes'])['TE-4.1']['status']);
        $earlier = coverage::summary($built->northcourse->id, $firstterm);
        $this->assertSame('met', $this->by_code($earlier['outcomes'])['TE-4.1']['status']);
        $this->assertSame(2, $DB->count_records('local_wwtreaty_evidence', [
            'outcomeid' => $id,
            'userid' => $built->student->id,
            'cmid' => 0,
        ]));
    }

    /**
     * Two schools, a teacher and a student in the north course, and one page in each course.
     *
     * @return \stdClass
     */
    private function two_schools(): \stdClass {
        global $DB;

        $north = $this->getDataGenerator()->create_category(['name' => 'North School']);
        $south = $this->getDataGenerator()->create_category(['name' => 'South School']);
        $northcourse = $this->getDataGenerator()->create_course(['category' => $north->id, 'fullname' => 'North room']);
        $southcourse = $this->getDataGenerator()->create_course(['category' => $south->id, 'fullname' => 'South room']);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Moss']);
        $northadmin = $this->getDataGenerator()->create_user();
        $southadmin = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $northcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $northcourse->id, 'student');
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $northadmin->id, \context_coursecat::instance($north->id)->id);
        role_assign($manager, $southadmin->id, \context_coursecat::instance($south->id)->id);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $northcourse->id, 'name' => 'North page']);
        $cm = get_coursemodule_from_instance('page', $page->id);
        $southpage = $this->getDataGenerator()->create_module('page', ['course' => $southcourse->id, 'name' => 'South page']);
        $southcm = get_coursemodule_from_instance('page', $southpage->id);
        accesslib_clear_all_caches_for_unit_testing();
        return (object) [
            'north' => $north,
            'south' => $south,
            'northcourse' => $northcourse,
            'southcourse' => $southcourse,
            'teacher' => $teacher,
            'student' => $student,
            'northadmin' => $northadmin,
            'southadmin' => $southadmin,
            'northcm' => (int) $cm->id,
            'southcm' => (int) $southcm->id,
        ];
    }

    /**
     * A neutral fixture. The code can change. The wording stays a sample.
     *
     * @param string $code
     * @return array
     */
    private function sample(string $code = 'TE-4.1'): array {
        return [
            'code' => $code,
            'grade' => '4',
            'theme' => 'Sample pillar',
            'title' => $code === 'TE-4.1' ? 'Grade 4 Sample Outcome' : $code . ' Sample Outcome',
            'description' => 'Sample description',
            'active' => 1,
        ];
    }

    /**
     * @param array $row
     * @return string
     */
    private function csv(array $row): string {
        return "code,grade,theme,title,description,active\n"
            . "{$row['code']},{$row['grade']},{$row['theme']},{$row['title']},{$row['description']},{$row['active']}\n";
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @param int $timestart
     * @param int $timeend
     */
    private function set_enrolment_dates(int $courseid, int $userid, int $timestart, int $timeend): void {
        global $DB;

        $id = $DB->get_field_sql(
            "SELECT ue.id
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $courseid, 'userid' => $userid],
            MUST_EXIST
        );
        $DB->update_record('user_enrolments', (object) [
            'id' => $id,
            'timestart' => $timestart,
            'timeend' => $timeend,
        ]);
    }

    /**
     * @param int $outcomeid
     * @param int $cmid
     * @return bool
     */
    private function is_tagged(int $outcomeid, int $cmid): bool {
        global $DB;

        return $DB->record_exists('local_wwtreaty_tag', ['outcomeid' => $outcomeid, 'cmid' => $cmid]);
    }

    /**
     * @param int $cmid
     * @return int
     */
    private function tag_count(int $cmid): int {
        global $DB;

        return $DB->count_records('local_wwtreaty_tag', ['cmid' => $cmid]);
    }

    /**
     * @param array $outcomes
     * @return array
     */
    private function by_code(array $outcomes): array {
        $indexed = [];
        foreach ($outcomes as $outcome) {
            $indexed[$outcome['code']] = $outcome;
        }
        return $indexed;
    }
}
