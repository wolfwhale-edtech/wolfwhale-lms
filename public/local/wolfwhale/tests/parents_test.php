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

namespace local_wolfwhale;

/**
 * Tests for parent accounts.
 *
 * Two families are built. A parent may see only the children they are linked to.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(parents::class)]
final class parents_test extends \advanced_testcase {
    /**
     * A parent sees one child's marks, due work, attendance, badges, and teachers.
     */
    public function test_parent_sees_only_the_linked_child(): void {
        $world = $this->world();
        $pat = $world['pat'];
        $sam = $world['sam'];
        $ada = $world['ada'];
        $ben = $world['ben'];
        $cleo = $world['cleo'];

        $this->setUser($pat);
        $this->assertTrue(parents::is_parent((int) $pat->id));
        $this->assertFalse(home::is_student((int) $pat->id));
        $this->assertFalse(teacher::is_teacher((int) $pat->id));
        $this->assertTrue(parents::should_redirect(new \moodle_url('/my/'), (int) $pat->id));
        $this->assertFalse(home::should_redirect(new \moodle_url('/my/'), (int) $pat->id));
        $this->assertSame(parents::url()->out(false), parents::login_wantsurl(null, (int) $pat->id));

        $export = parents::export((int) $ada->id);
        $this->assertTrue($export['several']);
        $this->assertCount(2, $export['children']);
        $tiles = $this->tiles_by_id($export['tiles']);
        $encoded = json_encode($export);
        $this->assertStringContainsString('83', $tiles['marks']['fact']);
        $this->assertStringNotContainsString('46', $tiles['marks']['fact']);
        $this->assertStringNotContainsString('Hill class', $encoded);
        $this->assertStringNotContainsString('Hill star', $encoded);
        $this->assertStringNotContainsString('Hill essay', $encoded);
        $this->assertStringNotContainsString('Cleo', $encoded);
        $this->assertStringNotContainsString('Nia', $encoded);
        $this->assertStringNotContainsString('Ben star', $encoded);
        $this->assertStringNotContainsString('Secret ribbon', $encoded);
        $this->assertStringNotContainsString('Hidden essay', $encoded);
        $this->assertSame('River star', $tiles['badges']['fact']);
        $this->assertSame('1', $tiles['messages']['fact']);
        $this->assertSame('2', $tiles['attendance']['fact']);

        $marks = json_encode(parents::marks_rows((int) $ada->id));
        $this->assertStringContainsString('River class', $marks);
        $this->assertStringContainsString('83', $marks);
        $this->assertStringNotContainsString('46', $marks);
        $this->assertStringNotContainsString('Hill class', $marks);

        $due = json_encode(parents::due_rows((int) $ada->id));
        $this->assertStringContainsString('River essay', $due);
        $this->assertStringNotContainsString('River project', $due);
        $this->assertStringNotContainsString('Overdue', $due);
        $this->assertStringNotContainsString('Hill essay', $due);
        $this->assertStringNotContainsString('Hidden essay', $due);

        $attendance = json_encode(parents::attendance_rows((int) $ada->id));
        $this->assertStringContainsString('Present', $attendance);
        $this->assertStringContainsString('Late', $attendance);
        $this->assertStringNotContainsString('Absent', $attendance);
        $this->assertStringNotContainsString('Hill class', $attendance);

        $messages = parents::message_rows((int) $ada->id);
        $messagetext = json_encode($messages);
        $this->assertStringContainsString('Lee Moss', $messagetext);
        $this->assertStringContainsString('/message/index.php', $messages[0]['url']);
        $this->assertStringNotContainsString('Nia Brook', $messagetext);
        $this->assertStringNotContainsString('Hill class', $messagetext);
        $this->assertTrue(\core_message\api::can_send_message((int) $world['lee']->id, (int) $pat->id));
        $this->assertFalse(\core_message\api::can_send_message((int) $world['nia']->id, (int) $pat->id));

        $report = parents::report((int) $ada->id, $world['term']);
        $reporttext = json_encode($report);
        $this->assertStringContainsString('River class', $reporttext);
        $this->assertStringContainsString('Present 1', $reporttext);
        $this->assertStringContainsString('Absent 0', $reporttext);
        $this->assertStringContainsString('Late 0', $reporttext);
        $this->assertStringNotContainsString('Hill class', $reporttext);
        $this->assertStringContainsString('83', $reporttext);

        $other = parents::report((int) $ada->id, $world['otherterm']);
        $othertext = json_encode($other);
        $this->assertStringNotContainsString('Late 1', $othertext);

        $bentiles = json_encode(parents::get_tiles((int) $ben->id));
        $this->assertStringContainsString('46', $bentiles);
        $this->assertStringContainsString('Ben star', $bentiles);
        $this->assertStringContainsString('Absent', $bentiles);
        $this->assertStringNotContainsString('River star', $bentiles);
        $this->assertStringNotContainsString('Hill', $bentiles);
        $bendue = json_encode(parents::due_rows((int) $ben->id));
        $this->assertStringContainsString('River project', $bendue);
        $this->assertStringContainsString('Overdue', $bendue);
        $this->assertStringNotContainsString('Hill essay', $bendue);

        try {
            parents::resolve((int) $cleo->id);
            $this->fail('A parent opened another family.');
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString('Cleo', $e->getMessage());
        }

        $this->setUser($sam);
        $samtiles = json_encode(parents::get_tiles((int) $cleo->id));
        $this->assertStringContainsString('Hill star', $samtiles);
        $this->assertStringContainsString('29', $samtiles);
        $this->assertStringNotContainsString('River star', $samtiles);
        $this->assertStringNotContainsString('Ada', $samtiles);
        $sammarks = json_encode(parents::marks_rows((int) $cleo->id));
        $this->assertStringContainsString('Hill class', $sammarks);
        $this->assertStringNotContainsString('River', $sammarks);
        $sammessages = json_encode(parents::message_rows((int) $cleo->id));
        $this->assertStringContainsString('Nia Brook', $sammessages);
        $this->assertStringNotContainsString('Lee Moss', $sammessages);
        $this->assertFalse(\core_message\api::can_send_message((int) $world['lee']->id, (int) $sam->id));

        try {
            parents::marks_rows((int) $ada->id);
            $this->fail('A parent read another family.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->setUser($ada);
        $this->assertTrue(home::is_student((int) $ada->id));
        $this->assertFalse(parents::is_parent((int) $ada->id));
        try {
            parents::marks_rows((int) $ben->id);
            $this->fail('A student read a sibling.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * A teacher links students in their own courses. Another course is refused.
     */
    public function test_teacher_links_only_their_own_students(): void {
        global $DB;

        $world = $this->world();
        $lee = $world['lee'];
        $nia = $world['nia'];
        $ada = $world['ada'];
        $cleo = $world['cleo'];
        $pat = $world['pat'];

        $roleid = parents::role_id();
        $levels = array_map('intval', $DB->get_fieldset_select('role_context_levels', 'contextlevel', 'roleid = ?', [$roleid]));
        $this->assertSame([CONTEXT_USER], $levels);

        $this->setUser($lee);
        $this->assertTrue(parents::can_link());
        $ids = array_map(fn($user) => (int) $user->id, parents::linkable_children());
        $this->assertContains((int) $ada->id, $ids);
        $this->assertContains((int) $world['ben']->id, $ids);
        $this->assertNotContains((int) $cleo->id, $ids);
        $this->assertTrue(parents::can_link_child((int) $ada->id));
        $this->assertFalse(parents::can_link_child((int) $cleo->id));

        $extra = $this->getDataGenerator()->create_user(['username' => 'extra.parent']);
        parents::link((int) $extra->id, (int) $ada->id);
        $this->setUser($extra);
        $this->assertTrue(parents::is_parent((int) $extra->id));
        $this->setUser($lee);
        parents::unlink((int) $extra->id, (int) $ada->id);
        $this->setUser($extra);
        $this->assertFalse(parents::is_parent((int) $extra->id));

        $this->setUser($lee);
        try {
            parents::link((int) $pat->id, (int) $cleo->id);
            $this->fail('A teacher linked a student in another course.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->setUser($pat);
        $this->assertCount(2, parents::children());

        $this->setUser($nia);
        $niaids = array_map(fn($user) => (int) $user->id, parents::linkable_children());
        $this->assertContains((int) $cleo->id, $niaids);
        $this->assertNotContains((int) $ada->id, $niaids);
        $names = array_column(parents::managed_links(), 'childname');
        $joined = implode(' ', $names);
        $this->assertStringContainsString('Cleo', $joined);
        $this->assertStringNotContainsString('Ada', $joined);
        $this->assertStringNotContainsString('Ben', $joined);
    }

    /**
     * A blocked teacher is not offered as someone the parent can message.
     */
    public function test_a_blocked_teacher_is_not_messaged(): void {
        $world = $this->world();
        \core_message\api::block_user((int) $world['lee']->id, (int) $world['pat']->id);

        $this->setUser($world['pat']);
        $rows = parents::message_rows((int) $world['ada']->id);
        $this->assertSame([], $rows);
        $this->assertFalse(\core_message\api::can_send_message((int) $world['lee']->id, (int) $world['pat']->id));
        $this->assertFalse(\core_message\api::is_contact((int) $world['pat']->id, (int) $world['lee']->id));
    }

    /**
     * Attendance for another child is refused, including for the child's teacher.
     */
    public function test_attendance_read_stays_with_the_linked_parent(): void {
        $world = $this->world();

        $this->setUser($world['pat']);
        try {
            \local_wwattendance\attendance::records_for_user((int) $world['cleo']->id);
            $this->fail('A parent read another child\'s attendance.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->setUser($world['lee']);
        $this->assertFalse(\local_wwattendance\attendance::can_view_user((int) $world['ada']->id));
        try {
            \local_wwattendance\attendance::records_for_user((int) $world['ada']->id);
            $this->fail('A teacher used the parent attendance read.');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    /**
     * A due date override and a submitted team assignment are not left overdue.
     */
    public function test_due_follows_the_childs_override_and_team_submission(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        parents::install_role();
        $parent = $this->getDataGenerator()->create_user();
        $child = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($child->id, $course->id, 'student');
        $past = time() - DAYSECS;
        $future = time() + WEEKSECS;
        $moved = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Moved essay',
            'duedate' => $past,
        ]);
        $onlyoverride = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Override only',
            'duedate' => 0,
        ]);
        $team = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Team essay',
            'duedate' => $past,
            'teamsubmission' => 1,
        ]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $child->id]);
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $moved->id,
            'userid' => $child->id,
            'sortorder' => 1,
            'duedate' => $future,
            'reasonformat' => FORMAT_PLAIN,
        ]);
        $DB->insert_record('assign_overrides', (object) [
            'assignid' => $onlyoverride->id,
            'userid' => $child->id,
            'sortorder' => 1,
            'duedate' => $future,
            'reasonformat' => FORMAT_PLAIN,
        ]);
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $team->id,
            'userid' => 0,
            'groupid' => $group->id,
            'timecreated' => $past,
            'timemodified' => $past,
            'status' => 'submitted',
            'attemptnumber' => 0,
            'latest' => 1,
        ]);
        parents::link((int) $parent->id, (int) $child->id);

        $this->setUser($parent);
        $due = json_encode(parents::due_rows((int) $child->id));
        $this->assertStringContainsString('Moved essay', $due);
        $this->assertStringContainsString('Override only', $due);
        $this->assertStringNotContainsString('Overdue', $due);
        $this->assertStringNotContainsString('Team essay', $due);
    }

    /**
     * A course total that includes a hidden mark is not shown as the raw sum.
     */
    public function test_hidden_marks_are_left_out_of_the_course_total(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        parents::install_role();
        set_config('grade_report_overview_showtotalsifcontainhidden', GRADE_REPORT_HIDE_TOTAL_IF_CONTAINS_HIDDEN);
        $parent = $this->getDataGenerator()->create_user();
        $child = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['showgrades' => 1]);
        $this->getDataGenerator()->enrol_user($child->id, $course->id, 'student');
        $shown = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Shown essay',
        ]);
        $secret = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Secret essay',
        ]);
        $this->give_grade((int) $course->id, (int) $shown->id, (int) $child->id, 40);
        $this->give_grade((int) $course->id, (int) $secret->id, (int) $child->id, 50);
        $hidden = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $secret->id,
            'courseid' => $course->id,
        ]);
        $hidden->set_hidden(1);
        grade_regrade_final_grades($course->id);
        parents::link((int) $parent->id, (int) $child->id);

        $this->setUser($parent);
        $marks = json_encode(parents::marks_rows((int) $child->id));
        $this->assertStringContainsString('None', $marks);
        $this->assertStringNotContainsString('90', $marks);
        $this->assertStringNotContainsString('50', $marks);
    }

    /**
     * A saved school term replaces the January-June and July-December split.
     */
    public function test_report_uses_school_terms_when_the_school_has_them(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        parents::install_role();

        $north = $this->getDataGenerator()->create_category(['name' => 'North School']);
        $south = $this->getDataGenerator()->create_category(['name' => 'South School']);
        $now = time();
        $from = $now - (10 * DAYSECS);
        $until = $now + (10 * DAYSECS);
        \local_wwterms\term::create((int) $north->id, 'Harvest', $from, $until);
        \local_wwterms\term::create((int) $north->id, 'Freeze', $now + (40 * DAYSECS), $now + (80 * DAYSECS));
        \local_wwterms\term::create((int) $south->id, 'South only', $from, $until);

        $course = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'fullname' => 'North room',
            'startdate' => $from,
            'showgrades' => 1,
        ]);
        $plain = $this->getDataGenerator()->create_course([
            'fullname' => 'Plain room',
            'startdate' => $from,
            'showgrades' => 1,
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $parent = $this->getDataGenerator()->create_user(['firstname' => 'Pat', 'lastname' => 'North']);
        $child = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'North']);
        $otherchild = $this->getDataGenerator()->create_user(['firstname' => 'Bea', 'lastname' => 'Plain']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($child->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($otherchild->id, $plain->id, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'North essay',
        ]);
        $this->give_grade((int) $course->id, (int) $assign->id, (int) $child->id, 91);

        $this->setUser($teacher);
        \local_wwattendance\attendance::save((int) $course->id, [
            $child->id => \local_wwattendance\attendance::PRESENT,
        ], $now);
        \local_wwattendance\attendance::save((int) $course->id, [
            $child->id => \local_wwattendance\attendance::LATE,
        ], $from - DAYSECS);

        $this->setAdminUser();
        parents::link((int) $parent->id, (int) $child->id);
        parents::link((int) $parent->id, (int) $otherchild->id);

        $this->setUser($parent);
        $term = parents::current_term(null, (int) $child->id);
        $this->assertSame('Harvest', $term['short']);
        $this->assertSame('Harvest', $term['label']);
        $calendar = parents::current_term();
        $this->assertNull(parents::term_by_id($calendar['id'], (int) $child->id));
        $this->assertSame($term, parents::term_by_id($term['id'], (int) $child->id));

        $report = parents::report((int) $child->id, $term);
        $this->assertSame('Harvest', $report['termname']);
        $names = array_column($report['terms'], 'name');
        $this->assertSame(['Harvest', 'Freeze'], $names);
        $reporttext = json_encode($report);
        $this->assertStringContainsString('North room', $reporttext);
        $this->assertStringContainsString('91', $reporttext);
        $this->assertStringContainsString('Present 1', $reporttext);
        $this->assertStringContainsString('Late 0', $reporttext);
        $this->assertStringNotContainsString('South only', $reporttext);
        $this->assertStringNotContainsString('Term 1', $reporttext);
        $this->assertStringNotContainsString('Term 2', $reporttext);

        $tiles = json_encode(parents::get_tiles((int) $child->id));
        $this->assertStringContainsString('Harvest', $tiles);

        $fallback = parents::current_term(null, (int) $otherchild->id);
        $this->assertMatchesRegularExpression('/^\d{4}-[12]$/', $fallback['id']);
        $this->assertContains($fallback['short'], ['Term 1', 'Term 2']);
    }

    /**
     * Two families, a shared sibling pair, and one child in another course.
     *
     * @return array
     */
    private function world(): array {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        parents::install_role();
        set_config('enablebadges', 1);
        set_config('messaging', 1);

        $term = parents::current_term();
        $year = (int) substr($term['id'], 0, 4);
        $terms = parents::terms_for_year($year);
        $otherterm = $terms[0]['id'] === $term['id'] ? $terms[1] : $terms[0];
        $start = $term['from'] + (2 * DAYSECS);
        $inside = $term['from'] + (3 * DAYSECS);
        $outside = $term['from'] - DAYSECS;

        $river = $this->getDataGenerator()->create_course([
            'fullname' => 'River class',
            'startdate' => $start,
            'showgrades' => 1,
        ]);
        $hill = $this->getDataGenerator()->create_course([
            'fullname' => 'Hill class',
            'startdate' => $start,
            'showgrades' => 1,
        ]);
        $lee = $this->getDataGenerator()->create_user([
            'username' => 'lee.moss',
            'firstname' => 'Lee',
            'lastname' => 'Moss',
        ]);
        $nia = $this->getDataGenerator()->create_user([
            'username' => 'nia.brook',
            'firstname' => 'Nia',
            'lastname' => 'Brook',
        ]);
        $pat = $this->getDataGenerator()->create_user([
            'username' => 'pat.river',
            'firstname' => 'Pat',
            'lastname' => 'River',
        ]);
        $sam = $this->getDataGenerator()->create_user([
            'username' => 'sam.hill',
            'firstname' => 'Sam',
            'lastname' => 'Hill',
        ]);
        $ada = $this->getDataGenerator()->create_user([
            'username' => 'ada.river',
            'firstname' => 'Ada',
            'lastname' => 'River',
        ]);
        $ben = $this->getDataGenerator()->create_user([
            'username' => 'ben.river',
            'firstname' => 'Ben',
            'lastname' => 'River',
        ]);
        $cleo = $this->getDataGenerator()->create_user([
            'username' => 'cleo.hill',
            'firstname' => 'Cleo',
            'lastname' => 'Hill',
        ]);

        $this->getDataGenerator()->enrol_user($lee->id, $river->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($nia->id, $hill->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($ada->id, $river->id, 'student');
        $this->getDataGenerator()->enrol_user($ben->id, $river->id, 'student');
        $this->getDataGenerator()->enrol_user($cleo->id, $hill->id, 'student');

        $soon = time() + DAYSECS;
        $past = time() - DAYSECS;
        $essay = $this->getDataGenerator()->create_module('assign', [
            'course' => $river->id,
            'name' => 'River essay',
            'duedate' => $soon,
        ]);
        $project = $this->getDataGenerator()->create_module('assign', [
            'course' => $river->id,
            'name' => 'River project',
            'duedate' => $past,
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $river->id,
            'name' => 'Hidden essay',
            'duedate' => $soon,
            'visible' => 0,
        ]);
        $hillessay = $this->getDataGenerator()->create_module('assign', [
            'course' => $hill->id,
            'name' => 'Hill essay',
            'duedate' => $soon,
        ]);
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $project->id,
            'userid' => $ada->id,
            'timecreated' => $past,
            'timemodified' => $past,
            'status' => 'submitted',
            'groupid' => 0,
            'attemptnumber' => 0,
            'latest' => 1,
        ]);

        $this->give_grade($river->id, (int) $essay->id, (int) $ada->id, 83);
        $this->give_grade($river->id, (int) $essay->id, (int) $ben->id, 46);
        $this->give_grade($hill->id, (int) $hillessay->id, (int) $cleo->id, 29);

        $this->setUser($lee);
        \local_wwattendance\attendance::save((int) $river->id, [
            $ada->id => \local_wwattendance\attendance::PRESENT,
        ], $inside);
        \local_wwattendance\attendance::save((int) $river->id, [
            $ada->id => \local_wwattendance\attendance::LATE,
        ], $outside);
        \local_wwattendance\attendance::save((int) $river->id, [
            $ben->id => \local_wwattendance\attendance::ABSENT,
        ], $inside);
        $this->setUser($nia);
        \local_wwattendance\attendance::save((int) $hill->id, [
            $cleo->id => \local_wwattendance\attendance::ABSENT,
        ], $inside);

        $this->setAdminUser();
        /** @var \core_badges_generator $badges */
        $badges = $this->getDataGenerator()->get_plugin_generator('core_badges');
        $riverstar = $badges->create_badge(['name' => 'River star']);
        $benstar = $badges->create_badge(['name' => 'Ben star']);
        $hillstar = $badges->create_badge(['name' => 'Hill star']);
        $secret = $badges->create_badge(['name' => 'Secret ribbon']);
        $riverstar->issue($ada->id, true);
        $benstar->issue($ben->id, true);
        $hillstar->issue($cleo->id, true);
        $secret->issue($ada->id, true);
        $DB->set_field('badge_issued', 'visible', 0, ['userid' => $ada->id, 'badgeid' => $secret->id]);

        parents::link((int) $pat->id, (int) $ada->id);
        parents::link((int) $pat->id, (int) $ben->id);
        parents::link((int) $sam->id, (int) $cleo->id);

        return [
            'term' => $term,
            'otherterm' => $otherterm,
            'lee' => $lee,
            'nia' => $nia,
            'pat' => $pat,
            'sam' => $sam,
            'ada' => $ada,
            'ben' => $ben,
            'cleo' => $cleo,
        ];
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
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
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
     * Tiles keyed by id.
     *
     * @param array[] $tiles
     * @return array
     */
    private function tiles_by_id(array $tiles): array {
        $byid = [];
        foreach ($tiles as $tile) {
            $byid[$tile['id']] = $tile;
        }
        return $byid;
    }
}
