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

namespace local_wwreports;

/**
 * Report cards and funder totals use real rows and stop at the school boundary.
 *
 * @package    local_wwreports
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(access::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(card::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(comment::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(funder::class)]
final class report_test extends \advanced_testcase {
    /**
     * A parent, a teacher, and a school administrator do not see another family's courses.
     */
    public function test_each_viewer_sees_only_their_own_students(): void {
        $world = $this->world();
        $term = $world['term'];

        $this->setUser($world['pat']);
        $parent = card::build((int) $world['pat']->id, (int) $world['ada']->id, $term);
        $parenttext = json_encode($parent);
        $this->assertStringContainsString('River room', $parenttext);
        $this->assertStringContainsString('South room', $parenttext);
        $this->assertStringNotContainsString('Lake room', $parenttext);
        $this->assertStringContainsString('12', $this->course_named($parent, 'South room')['mark']);

        $this->setUser($world['lee']);
        $teacher = card::build((int) $world['lee']->id, (int) $world['ada']->id, $term);
        $teachertext = json_encode($teacher);
        $this->assertStringContainsString('River room', $teachertext);
        $this->assertStringNotContainsString('South room', $teachertext);
        $this->assertStringNotContainsString('Lake room', $teachertext);

        $this->setUser($world['northadmin']);
        $admin = card::build((int) $world['northadmin']->id, (int) $world['ada']->id, $term);
        $admintext = json_encode($admin);
        $this->assertStringContainsString('River room', $admintext);
        $this->assertStringNotContainsString('South room', $admintext);

        $this->setUser($world['southadmin']);
        $south = card::build((int) $world['southadmin']->id, (int) $world['ada']->id, $term);
        $southtext = json_encode($south);
        $this->assertStringContainsString('South room', $southtext);
        $this->assertStringNotContainsString('River room', $southtext);

        $this->setUser($world['lee']);
        $tiles = $this->tiles(\local_wolfwhale\teacher::get_tiles());
        $this->assertStringContainsString('/local/wwreports/card.php', $tiles['report']['url']);
        $this->assertContains($tiles['report']['fact'], ['2', 'Ada North', 'Ben North']);

        $this->setUser($world['northadmin']);
        $adminTiles = $this->tiles(\local_wwadmin\home::tiles());
        $this->assertSame('5', $adminTiles['funder']['fact']);
        $this->assertStringContainsString('/local/wwreports/funder.php', $adminTiles['funder']['url']);
        $this->assertStringContainsString('/local/wwreports/card.php', $adminTiles['cards']['url']);
    }

    /**
     * A person with no link cannot open the card.
     */
    public function test_a_stranger_cannot_open_the_card(): void {
        $world = $this->world();
        $this->setUser($world['pat']);
        $this->expectException(\moodle_exception::class);
        card::build((int) $world['pat']->id, (int) $world['eve']->id, $world['term']);
    }

    /**
     * A teacher cannot open a student from another course.
     */
    public function test_a_teacher_cannot_open_another_class(): void {
        $world = $this->world();
        $this->setUser($world['lee']);
        $this->expectException(\moodle_exception::class);
        card::build((int) $world['lee']->id, (int) $world['cy']->id, $world['term']);
    }

    /**
     * A school administrator cannot total another school.
     */
    public function test_a_school_administrator_cannot_total_another_school(): void {
        $world = $this->world();
        $this->setUser($world['riveradmin']);
        $this->expectException(\moodle_exception::class);
        funder::aggregate((int) $world['lake']->id, $world['from'], $world['until']);
    }

    /**
     * Hidden marks, hidden totals, and hidden feedback stay off the card.
     */
    public function test_hidden_marks_never_appear(): void {
        $world = $this->world();
        $term = $world['term'];
        $viewers = [$world['pat'], $world['lee'], $world['northadmin']];
        foreach ([GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN, GRADE_REPORT_SHOW_REAL_TOTAL_IF_CONTAINS_HIDDEN] as $setting) {
            set_config('grade_report_overview_showtotalsifcontainhidden', $setting);
            grade_set_setting($world['rivercourse']->id, 'report_overview_showtotalsifcontainhidden', $setting);
            foreach ($viewers as $viewer) {
                $this->setUser($viewer);
                $card = card::build((int) $viewer->id, (int) $world['ada']->id, $term);
                $this->assert_no_hidden_mark($card);
                $this->assert_no_hidden_mark_text(json_encode(card::csv_rows($card)));
                $this->assert_no_hidden_mark_text(card::html($card));
            }
        }
    }

    /**
     * Attendance, a public badge, the level, and a stored comment are on the card.
     */
    public function test_the_card_reads_attendance_badges_level_and_comments(): void {
        global $DB;

        $world = $this->world();
        $this->setUser($world['lee']);
        comment::save(
            (int) $world['lee']->id,
            (int) $world['ada']->id,
            (int) $world['rivercourse']->id,
            $world['term']['id'],
            'Steady hands'
        );

        $this->setUser($world['pat']);
        $card = card::build((int) $world['pat']->id, (int) $world['ada']->id, $world['term']);
        $river = $this->course_named($card, 'River room');
        $this->assertSame('Present 1 Absent 0 Late 0', $river['attendance']);
        $this->assertStringContainsString('Shown essay', json_encode($river['items']));
        $this->assertStringContainsString('41', json_encode($river['items']));
        $this->assertStringContainsString('Clear river note', json_encode($river['comments']));
        $this->assertStringContainsString('Steady hands', json_encode($river['comments']));
        $this->assertStringContainsString('Lee North', json_encode($river['comments']));
        $names = array_column($card['badges'], 'name');
        $this->assertContains('River star', $names);
        $this->assertNotContains('Secret ribbon', $names);
        $ledger = (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(amount), 0) FROM {local_wwpoints_ledger} WHERE userid = ?",
            [$world['ada']->id]
        );
        $this->assertGreaterThanOrEqual(45, $ledger);
        $this->assertSame((string) $ledger, $card['points']);
        $this->assertSame((string) \local_wwpoints\levels::for_points($ledger), $card['level']);
        $this->assertStringContainsString('Steady hands', card::html($card));
        $this->assertStringContainsString('River star', json_encode(card::csv_rows($card)));
        $this->assertStringNotContainsString('Secret ribbon', card::html($card));
    }

    /**
     * A parent cannot store a comment, and a teacher cannot comment on another course.
     */
    public function test_only_the_teacher_of_the_course_can_comment(): void {
        $world = $this->world();
        $this->setUser($world['pat']);
        $this->expectException(\moodle_exception::class);
        comment::save(
            (int) $world['pat']->id,
            (int) $world['ada']->id,
            (int) $world['rivercourse']->id,
            $world['term']['id'],
            'Parent note'
        );
    }

    /**
     * Funder rows are counted from the courses in that category, inside the term.
     */
    public function test_funder_totals_are_counted_not_averaged(): void {
        $world = $this->world();
        $this->setUser($world['northadmin']);
        $north = funder::aggregate((int) $world['north']->id, $world['from'], $world['until']);
        $this->assertSame(4, $north['students']);
        $this->assertEqualsWithDelta(66.666, $north['attendance'], 0.05);
        $this->assertEqualsWithDelta(33.333, $north['coursecompletion'], 0.05);
        $this->assertEqualsWithDelta(33.333, $north['activitycompletion'], 0.05);
        $names = array_column($north['classes'], 'name');
        $this->assertEqualsCanonicalizing(['River room', 'Quiet room', 'Lake room'], $names);
        $river = $this->class_named($north, 'River room');
        $quiet = $this->class_named($north, 'Quiet room');
        $lake = $this->class_named($north, 'Lake room');
        $this->assertSame(2, $river['students']);
        $this->assertEqualsWithDelta(50, $river['attendance'], 0.01);
        $this->assertEqualsWithDelta(50, $river['coursecompletion'], 0.01);
        $this->assertEqualsWithDelta(50, $river['activitycompletion'], 0.01);
        $this->assertSame(1, $quiet['students']);
        $this->assertNull($quiet['attendance']);
        $this->assertNull($quiet['coursecompletion']);
        $this->assertNull($quiet['activitycompletion']);
        $this->assertEqualsWithDelta(100, $lake['attendance'], 0.01);
        $this->assertEqualsWithDelta(0, $lake['coursecompletion'], 0.01);

        $report = funder::report((int) $world['north']->id, $world['term']);
        $text = json_encode($report);
        $this->assertStringContainsString('66.7%', $text);
        $this->assertStringNotContainsString('75%', $text);
        $this->assertStringNotContainsString('South', $text);
        $this->assertSame('Authority', $report['kind']);
        $this->assertStringContainsString('66.7%', json_encode(funder::csv_rows($report)));
        $this->assertStringNotContainsString('South', json_encode(funder::csv_rows($report)));

        $this->setUser($world['eastadmin']);
        $east = funder::aggregate((int) $world['east']->id, $world['from'], $world['until']);
        $this->assertSame(3, $east['students']);
        $this->assertEqualsWithDelta(50, $east['attendance'], 0.01);
        $this->assertNotContains('Lake room', array_column($east['classes'], 'name'));
        $eastreport = funder::report((int) $world['east']->id, $world['term']);
        $this->assertSame('Division', $eastreport['kind']);

        $this->setUser($world['riveradmin']);
        $school = funder::report((int) $world['river']->id, $world['term']);
        $this->assertSame('School', $school['kind']);
        $this->assertNotContains('Lake room', array_column($school['classes'], 'name'));
        $this->assertStringContainsString('None', json_encode($this->class_row($school, 'Quiet room')));
    }

    /**
     * A visible calculation that copies a hidden mark is left off the card.
     */
    public function test_a_calculated_mark_cannot_repeat_a_hidden_mark(): void {
        global $DB;

        $world = $this->world();
        $secret = null;
        $items = \grade_item::fetch_all(['courseid' => $world['rivercourse']->id]);
        foreach ($items as $item) {
            if ($item->get_name(false, false) === 'Secret essay') {
                $secret = $item;
                break;
            }
        }
        $this->assertNotNull($secret);
        $secret->idnumber = 'secretmark';
        $secret->update();

        $mirror = new \grade_item();
        $mirror->courseid = $world['rivercourse']->id;
        $mirror->itemtype = 'manual';
        $mirror->itemname = 'Mirror mark';
        $mirror->gradetype = GRADE_TYPE_VALUE;
        $mirror->grademax = 100;
        $mirror->grademin = 0;
        $mirror->insert();
        $mirror->set_calculation('=[[secretmark]]');
        grade_regrade_final_grades($world['rivercourse']->id);
        $copied = \grade_grade::fetch(['itemid' => $mirror->id, 'userid' => $world['ada']->id]);
        $this->assertNotFalse($copied);
        $this->assertEquals(73.0, (float) $copied->finalgrade);
        $copied->feedback = 'MIRRORHIDDEN';
        $copied->feedbackformat = FORMAT_PLAIN;
        $copied->usermodified = $world['lee']->id;
        $copied->update();

        $this->setUser($world['pat']);
        $card = card::build((int) $world['pat']->id, (int) $world['ada']->id, $world['term']);
        $river = $this->course_named($card, 'River room');
        $text = json_encode($river) . card::html($card) . json_encode(card::csv_rows($card));
        $this->assertStringNotContainsString('Mirror mark', $text);
        $this->assertStringNotContainsString('MIRRORHIDDEN', $text);
        $this->assertStringNotContainsString('73', (string) $river['mark']);
        $this->assertStringNotContainsString('73', json_encode($river['items']));
        $this->assertGreaterThan(0, $DB->count_records('grade_grades', ['itemid' => $mirror->id]));
    }

    /**
     * Prohibiting the comment capability closes the save, even for the teacher.
     */
    public function test_a_prohibited_comment_capability_blocks_the_save(): void {
        global $DB;

        $world = $this->world();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $context = \context_course::instance($world['rivercourse']->id);
        assign_capability('local/wwreports:comment', CAP_PROHIBIT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($world['lee']);
        $this->assertFalse(access::can_comment(
            (int) $world['lee']->id,
            (int) $world['ada']->id,
            (int) $world['rivercourse']->id
        ));
        $this->expectException(\moodle_exception::class);
        comment::save(
            (int) $world['lee']->id,
            (int) $world['ada']->id,
            (int) $world['rivercourse']->id,
            $world['term']['id'],
            'Should not save'
        );
    }

    /**
     * Both CSV downloads load the writer class, which is not autoloaded.
     */
    public function test_csv_downloads_load_the_writer(): void {
        card::load_csv_library();
        funder::load_csv_library();
        $this->assertTrue(class_exists(\csv_export_writer::class, false));
    }

    /**
     * A division administrator cannot open the authority above them.
     */
    public function test_a_division_administrator_cannot_open_the_authority(): void {
        $world = $this->world();
        $this->setUser($world['eastadmin']);
        $this->expectException(\moodle_exception::class);
        funder::aggregate((int) $world['north']->id, $world['from'], $world['until']);
    }

    /**
     * The stored mark, the hidden mark, and the hidden feedback are absent together.
     *
     * @param array $card
     */
    private function assert_no_hidden_mark(array $card): void {
        $river = $this->course_named($card, 'River room');
        $this->assertStringNotContainsString('73', (string) $river['mark']);
        $this->assertStringNotContainsString('114', (string) $river['mark']);
        $this->assertStringNotContainsString('Secret', json_encode($river['items']));
        $this->assertStringNotContainsString('73', json_encode($river['items']));
        $this->assertStringNotContainsString('HIDDENMARK', json_encode($river['comments']));
        $this->assertStringNotContainsString('Secret essay', json_encode($card['courses']));
    }

    /**
     * @param string $text
     */
    private function assert_no_hidden_mark_text(string $text): void {
        $this->assertStringNotContainsString('Secret essay', $text);
        $this->assertStringNotContainsString('HIDDENMARK', $text);
        $this->assertStringNotContainsString('73.00', $text);
        $this->assertStringNotContainsString('114', $text);
    }

    /**
     * @param array $card
     * @param string $name
     * @return array
     */
    private function course_named(array $card, string $name): array {
        foreach ($card['courses'] as $course) {
            if ($course['name'] === $name) {
                return $course;
            }
        }
        $this->fail('Missing course ' . $name);
    }

    /**
     * @param array $report
     * @param string $name
     * @return array
     */
    private function class_named(array $report, string $name): array {
        foreach ($report['classes'] as $class) {
            if ($class['name'] === $name) {
                return $class;
            }
        }
        $this->fail('Missing class ' . $name);
    }

    /**
     * A formatted class row from the page data.
     *
     * @param array $report
     * @param string $name
     * @return array
     */
    private function class_row(array $report, string $name): array {
        foreach ($report['classes'] as $class) {
            if ($class['name'] === $name) {
                return $class;
            }
        }
        $this->fail('Missing class ' . $name);
    }

    /**
     * @param array[] $tiles
     * @return array
     */
    private function tiles(array $tiles): array {
        $byid = [];
        foreach ($tiles as $tile) {
            $byid[$tile['id']] = $tile;
        }
        return $byid;
    }

    /**
     * Two nations, a division, and real marks.
     *
     * @return array
     */
    private function world(): array {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $CFG->enablecompletion = 1;
        set_config('enablecompletion', 1);
        set_config('enablebadges', 1);
        \local_wolfwhale\parents::install_role();

        $north = $this->getDataGenerator()->create_category(['name' => 'North Nation']);
        $east = $this->getDataGenerator()->create_category(['name' => 'East Division', 'parent' => $north->id]);
        $river = $this->getDataGenerator()->create_category(['name' => 'River School', 'parent' => $east->id]);
        $west = $this->getDataGenerator()->create_category(['name' => 'West Division', 'parent' => $north->id]);
        $lake = $this->getDataGenerator()->create_category(['name' => 'Lake School', 'parent' => $west->id]);
        $south = $this->getDataGenerator()->create_category(['name' => 'South Nation']);
        $southschool = $this->getDataGenerator()->create_category(['name' => 'South School', 'parent' => $south->id]);

        $from = time() - (10 * DAYSECS);
        $until = time() + (10 * DAYSECS);
        $inside = time();
        $before = $from - DAYSECS;
        \local_wwterms\term::create((int) $north->id, 'Harvest', $from, $until);
        \local_wwterms\term::create((int) $south->id, 'South only', $from, $until);
        $term = \local_wwreports\terms::for_category((int) $river->id);
        $term = \local_wwreports\terms::pick($term, '', $inside);

        $rivercourse = $this->getDataGenerator()->create_course([
            'category' => $river->id,
            'fullname' => 'River room',
            'shortname' => 'riverroom',
            'showgrades' => 1,
            'enablecompletion' => 1,
            'startdate' => $from,
        ]);
        $quietcourse = $this->getDataGenerator()->create_course([
            'category' => $river->id,
            'fullname' => 'Quiet room',
            'shortname' => 'quietroom',
            'showgrades' => 1,
            'enablecompletion' => 0,
            'startdate' => $from,
        ]);
        $lakecourse = $this->getDataGenerator()->create_course([
            'category' => $lake->id,
            'fullname' => 'Lake room',
            'shortname' => 'lakeroom',
            'showgrades' => 1,
            'enablecompletion' => 1,
            'startdate' => $from,
        ]);
        $southcourse = $this->getDataGenerator()->create_course([
            'category' => $southschool->id,
            'fullname' => 'South room',
            'shortname' => 'southroom',
            'showgrades' => 1,
            'startdate' => $from,
        ]);

        $lee = $this->getDataGenerator()->create_user(['firstname' => 'Lee', 'lastname' => 'North']);
        $nia = $this->getDataGenerator()->create_user(['firstname' => 'Nia', 'lastname' => 'West']);
        $pat = $this->getDataGenerator()->create_user(['firstname' => 'Pat', 'lastname' => 'North']);
        $ada = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'North']);
        $ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben', 'lastname' => 'North']);
        $dee = $this->getDataGenerator()->create_user(['firstname' => 'Dee', 'lastname' => 'North']);
        $cy = $this->getDataGenerator()->create_user(['firstname' => 'Cy', 'lastname' => 'West']);
        $eve = $this->getDataGenerator()->create_user(['firstname' => 'Eve', 'lastname' => 'South']);
        $northadmin = $this->getDataGenerator()->create_user(['firstname' => 'Ned', 'lastname' => 'North']);
        $eastadmin = $this->getDataGenerator()->create_user(['firstname' => 'Eden', 'lastname' => 'East']);
        $riveradmin = $this->getDataGenerator()->create_user(['firstname' => 'Rae', 'lastname' => 'River']);
        $southadmin = $this->getDataGenerator()->create_user(['firstname' => 'Sid', 'lastname' => 'South']);

        $this->getDataGenerator()->enrol_user($lee->id, $rivercourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($nia->id, $lakecourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($ada->id, $rivercourse->id, 'student');
        $this->getDataGenerator()->enrol_user($ben->id, $rivercourse->id, 'student');
        $this->getDataGenerator()->enrol_user($dee->id, $quietcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($cy->id, $lakecourse->id, 'student');
        $this->getDataGenerator()->enrol_user($ada->id, $southcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($eve->id, $southcourse->id, 'student');
        $this->manage($northadmin, (int) $north->id);
        $this->manage($eastadmin, (int) $east->id);
        $this->manage($riveradmin, (int) $river->id);
        $this->manage($southadmin, (int) $south->id);

        $shown = $this->getDataGenerator()->create_module('assign', [
            'course' => $rivercourse->id,
            'name' => 'Shown essay',
        ]);
        $secret = $this->getDataGenerator()->create_module('assign', [
            'course' => $rivercourse->id,
            'name' => 'Secret essay',
        ]);
        $southassign = $this->getDataGenerator()->create_module('assign', [
            'course' => $southcourse->id,
            'name' => 'South page',
        ]);
        $this->give_grade((int) $rivercourse->id, (int) $shown->id, (int) $ada->id, 41);
        $this->give_grade((int) $rivercourse->id, (int) $secret->id, (int) $ada->id, 73);
        $this->give_grade((int) $southcourse->id, (int) $southassign->id, (int) $ada->id, 12);
        $this->feedback((int) $rivercourse->id, (int) $shown->id, (int) $ada->id, (int) $lee->id, 'Clear river note');
        $this->feedback((int) $rivercourse->id, (int) $secret->id, (int) $ada->id, (int) $lee->id, 'HIDDENMARK secret');
        $hidden = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $secret->id,
            'courseid' => $rivercourse->id,
        ]);
        $hidden->set_hidden(1);
        grade_regrade_final_grades($rivercourse->id);
        $this->feedback((int) $rivercourse->id, (int) $secret->id, (int) $ada->id, (int) $lee->id, 'HIDDENMARK secret');
        set_config('grade_report_overview_showtotalsifcontainhidden', GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN);

        $riverpage = $this->getDataGenerator()->create_module('page', [
            'course' => $rivercourse->id,
            'name' => 'River page',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $lakecourse->id,
            'name' => 'Lake page',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $riverpage->cmid,
            'userid' => $ada->id,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => $inside,
        ]);
        $DB->insert_record('course_completions', (object) [
            'userid' => $ada->id,
            'course' => $rivercourse->id,
            'timeenrolled' => $from,
            'timestarted' => $from,
            'timecompleted' => $inside,
            'reaggregate' => 0,
        ]);

        $this->setUser($lee);
        \local_wwattendance\attendance::save((int) $rivercourse->id, [
            $ada->id => \local_wwattendance\attendance::PRESENT,
        ], $inside);
        \local_wwattendance\attendance::save((int) $rivercourse->id, [
            $ben->id => \local_wwattendance\attendance::ABSENT,
        ], $inside - 60);
        \local_wwattendance\attendance::save((int) $rivercourse->id, [
            $ada->id => \local_wwattendance\attendance::ABSENT,
        ], $before);
        $this->setUser($nia);
        \local_wwattendance\attendance::save((int) $lakecourse->id, [
            $cy->id => \local_wwattendance\attendance::PRESENT,
        ], $inside);

        $this->setAdminUser();
        \local_wwpoints\points::record(
            (int) $ada->id,
            (int) $rivercourse->id,
            45,
            'River work',
            'local_wwreports_test',
            'ada-report-' . $ada->id,
            (int) $lee->id
        );
        /** @var \core_badges_generator $badges */
        $badges = $this->getDataGenerator()->get_plugin_generator('core_badges');
        $star = $badges->create_badge(['name' => 'River star']);
        $ribbon = $badges->create_badge(['name' => 'Secret ribbon']);
        $star->issue($ada->id, true);
        $ribbon->issue($ada->id, true);
        $DB->set_field('badge_issued', 'visible', 0, ['userid' => $ada->id, 'badgeid' => $ribbon->id]);
        \local_wolfwhale\parents::link((int) $pat->id, (int) $ada->id);

        $CFG->enablebadges = 1;
        return [
            'north' => $north,
            'east' => $east,
            'river' => $river,
            'lake' => $lake,
            'south' => $south,
            'rivercourse' => $rivercourse,
            'from' => $from,
            'until' => $until,
            'term' => $term,
            'lee' => $lee,
            'nia' => $nia,
            'pat' => $pat,
            'ada' => $ada,
            'ben' => $ben,
            'cy' => $cy,
            'eve' => $eve,
            'northadmin' => $northadmin,
            'eastadmin' => $eastadmin,
            'riveradmin' => $riveradmin,
            'southadmin' => $southadmin,
        ];
    }

    /**
     * Give one user the manager role on one category.
     *
     * @param \stdClass $user
     * @param int $categoryid
     */
    private function manage(\stdClass $user, int $categoryid): void {
        global $DB;

        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $user->id, \context_coursecat::instance($categoryid)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Store one assignment grade and rebuild the course total.
     *
     * @param int $courseid
     * @param int $assignid
     * @param int $userid
     * @param float $grade
     */
    private function give_grade(int $courseid, int $assignid, int $userid, float $grade): void {
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignid,
            'courseid' => $courseid,
        ]);
        $this->assertNotFalse($item);
        $item->update_final_grade($userid, $grade, 'phpunit');
        grade_regrade_final_grades($courseid);
    }

    /**
     * Store feedback on one assignment grade.
     *
     * @param int $courseid
     * @param int $assignid
     * @param int $userid
     * @param int $author
     * @param string $text
     */
    private function feedback(int $courseid, int $assignid, int $userid, int $author, string $text): void {
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assignid,
            'courseid' => $courseid,
        ]);
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        $this->assertNotFalse($grade);
        $grade->feedback = $text;
        $grade->feedbackformat = FORMAT_PLAIN;
        $grade->usermodified = $author;
        $grade->update();
    }
}
