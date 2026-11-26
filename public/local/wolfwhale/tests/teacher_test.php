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

use core_message\tests\helper as message_helper;

/**
 * Tests for the teacher home.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(teacher::class)]
final class teacher_test extends \advanced_testcase {
    /**
     * A teacher is sent home. An admin, a manager, and a site-wide teacher role are not.
     */
    public function test_only_a_course_teacher_is_redirected(): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $home = teacher::url()->out(false);
        $this->assertTrue(teacher::is_teacher((int) $teacher->id));
        $this->assertFalse(home::is_student((int) $teacher->id));
        $this->assertTrue(teacher::should_redirect(new \moodle_url('/my/index.php'), (int) $teacher->id));
        $this->assertTrue(teacher::should_redirect(new \moodle_url('/my/'), (int) $teacher->id));
        $this->assertFalse(teacher::should_redirect(new \moodle_url('/my/courses.php'), (int) $teacher->id));
        $this->assertFalse(teacher::should_redirect(teacher::url(), (int) $teacher->id));
        $this->assertSame($home, teacher::login_wantsurl(null, (int) $teacher->id));
        $this->assertSame($home, teacher::login_wantsurl($CFG->wwwroot . '/', (int) $teacher->id));
        $this->assertSame($home, teacher::login_wantsurl($CFG->wwwroot . '/my/index.php', (int) $teacher->id));
        $courseurl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $this->assertNull(teacher::login_wantsurl($courseurl, (int) $teacher->id));

        $plain = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($plain->id, $course->id, 'teacher');
        $this->assertTrue(teacher::is_teacher((int) $plain->id));

        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->assertFalse(teacher::is_teacher((int) $student->id));
        $this->assertNull(teacher::login_wantsurl(null, (int) $student->id));
        $this->assertTrue(home::should_redirect(new \moodle_url('/my/'), (int) $student->id));

        $both = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($both->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($both->id, $other->id, 'editingteacher');
        $this->assertTrue(teacher::is_teacher((int) $both->id));
        $this->assertFalse(home::is_student((int) $both->id));
        $this->assertTrue(teacher::should_redirect(new \moodle_url('/my/'), (int) $both->id));

        $manager = $this->getDataGenerator()->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerrole, $manager->id, \context_system::instance()->id);
        $this->assertFalse(teacher::is_teacher((int) $manager->id));
        $this->assertFalse(teacher::should_redirect(new \moodle_url('/my/'), (int) $manager->id));

        $systemteacher = $this->getDataGenerator()->create_user();
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        role_assign($teacherrole, $systemteacher->id, \context_system::instance()->id);
        $this->assertFalse(teacher::is_teacher((int) $systemteacher->id));

        $this->setAdminUser();
        $this->getDataGenerator()->enrol_user($USER->id, $course->id, 'editingteacher');
        $this->assertFalse(teacher::is_teacher((int) $USER->id));
        $this->assertFalse(teacher::should_redirect(new \moodle_url('/my/'), (int) $USER->id));
    }

    /**
     * A category role covers that category's courses and no other category.
     */
    public function test_a_category_role_stays_inside_that_category(): void {
        global $DB;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $north = $this->getDataGenerator()->create_category();
        $south = $this->getDataGenerator()->create_category();
        $northcourse = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'fullname' => 'North room',
        ]);
        $southcourse = $this->getDataGenerator()->create_course([
            'category' => $south->id,
            'fullname' => 'South room',
        ]);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        role_assign($roleid, $teacher->id, \context_coursecat::instance($north->id)->id);

        $northstudent = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $southstudent = $this->getDataGenerator()->create_user(['firstname' => 'Dale', 'lastname' => 'West']);
        $this->getDataGenerator()->enrol_user($northstudent->id, $northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($southstudent->id, $southcourse->id, 'student');
        $northassign = $this->assignment($northcourse->id, 'North essay');
        $southassign = $this->assignment($southcourse->id, 'South essay');
        $this->submit((int) $northstudent->id, (int) $northassign->cmid);
        $this->submit((int) $southstudent->id, (int) $southassign->cmid);

        $this->setUser($teacher);
        $names = array_map(static fn($course) => $course->fullname, teacher::courses());
        $this->assertContains('North room', $names);
        $this->assertNotContains('South room', $names);

        $encoded = json_encode(teacher::marking_items());
        $this->assertStringContainsString('River Stone', $encoded);
        $this->assertStringContainsString('North essay', $encoded);
        $this->assertStringNotContainsString('Dale West', $encoded);
        $this->assertStringNotContainsString('South essay', $encoded);
    }

    /**
     * Ungraded submissions are listed. Graded ones are not. Another course is not listed.
     */
    public function test_marking_is_ungraded_work_in_the_teachers_courses(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $teacher = $this->getDataGenerator()->create_user();
        $otherteacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $secret = $this->getDataGenerator()->create_course(['fullname' => 'South room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($otherteacher->id, $secret->id, 'editingteacher');

        $student = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $graded = $this->getDataGenerator()->create_user(['firstname' => 'Fern', 'lastname' => 'Moss']);
        $secretstudent = $this->getDataGenerator()->create_user(['firstname' => 'Dale', 'lastname' => 'West']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($graded->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($secretstudent->id, $secret->id, 'student');

        $open = $this->assignment($course->id, 'River essay');
        $done = $this->assignment($course->id, 'Fern quiz stand-in');
        $hidden = $this->assignment($secret->id, 'Secret essay');
        $this->submit((int) $student->id, (int) $open->cmid);
        $this->submit((int) $graded->id, (int) $done->cmid);
        $this->submit((int) $secretstudent->id, (int) $hidden->cmid);
        $this->grade($done, $course, $teacher, $graded);

        $this->setUser($teacher);
        $items = teacher::marking_items();
        $this->assertCount(1, $items);
        $this->assertSame('River Stone', $items[0]['word']);
        $this->assertSame('River essay', $items[0]['fact']);
        $this->assertSame('North room', $items[0]['course']);
        $this->assertStringContainsString('/mod/assign/view.php', $items[0]['url']);
        $this->assertStringContainsString('action=grader', $items[0]['url']);
        $this->assertStringContainsString('userid=' . $student->id, $items[0]['url']);
        $encoded = json_encode($items);
        $this->assertStringNotContainsString('Fern Moss', $encoded);
        $this->assertStringNotContainsString('Dale West', $encoded);
        $this->assertStringNotContainsString('Secret essay', $encoded);

        $cm = get_coursemodule_from_id('assign', $open->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $assign = new \assign($context, $cm, $course);
        $this->assertSame($assign->count_submissions_need_grading(), count($items));

        $tiles = $this->tiles_by_id(teacher::get_tiles());
        $this->assertSame('1', $tiles['marking']['fact']);
        $this->assertStringContainsString('/local/wolfwhale/marking.php', $tiles['marking']['url']);
    }

    /**
     * A prohibited grade capability hides the queue. A non-editing teacher can still mark.
     */
    public function test_marking_follows_the_grade_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $plain = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($plain->id, $course->id, 'teacher');
        $student = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $assign = $this->assignment($course->id, 'River essay');
        $this->submit((int) $student->id, (int) $assign->cmid);

        $this->setUser($plain);
        $plainitems = teacher::marking_items();
        $this->assertCount(1, $plainitems);
        $this->assertSame([], teacher::post_courses());
        $this->assertSame([], teacher::post_actions((int) $course->id));

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/assign:grade', CAP_PROHIBIT, $roleid, \context_module::instance($assign->cmid)->id, true);
        $this->setUser($teacher);
        $this->assertSame([], teacher::marking_items());
    }

    /**
     * Blind marking does not put the student's name on the row.
     */
    public function test_blind_marking_hides_the_student_name(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $assign = $this->assignment($course->id, 'Blind essay', ['blindmarking' => 1]);
        $this->submit((int) $student->id, (int) $assign->cmid);

        $this->setUser($teacher);
        $items = teacher::marking_items();
        $this->assertCount(1, $items);
        $this->assertStringNotContainsString('River', $items[0]['word']);
        $this->assertStringNotContainsString('Stone', $items[0]['word']);
        $this->assertStringContainsString('blindid=', $items[0]['url']);
        $this->assertStringNotContainsString('userid=' . $student->id, $items[0]['url']);
    }

    /**
     * Post links exist only for courses this teacher can change.
     */
    public function test_post_links_are_limited_to_courses_they_can_change(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $secret = $this->getDataGenerator()->create_course(['fullname' => 'South room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $secret->id, 'student');

        $this->setUser($teacher);
        $actions = teacher::post_actions((int) $course->id);
        $mods = array_column($actions, 'mod');
        $this->assertSame(['page', 'resource', 'assign', 'wwlibrary'], $mods);
        $this->assertSame('Lesson', $actions[0]['word']);
        $this->assertSame('File', $actions[1]['word']);
        $this->assertSame('Assignment', $actions[2]['word']);
        $this->assertStringContainsString('add=page', $actions[0]['url']);
        $this->assertStringContainsString('add=resource', $actions[1]['url']);
        $this->assertStringContainsString('add=assign', $actions[2]['url']);
        $this->assertStringContainsString('course=' . $course->id, $actions[0]['url']);
        $this->assertStringContainsString('section=0', $actions[0]['url']);
        $this->assertSame([], teacher::post_actions((int) $secret->id));

        $page = teacher::post_page();
        $this->assertCount(1, $page);
        $this->assertSame('North room', $page[0]['name']);
        $this->assertStringNotContainsString('South room', json_encode($page));
    }

    /**
     * The marks link is the grader report, and it follows the view capability.
     */
    public function test_marks_link_requires_the_grader_report_capability(): void {
        global $DB;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $secret = $this->getDataGenerator()->create_course(['fullname' => 'South room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $secret->id, 'student');

        $this->setUser($teacher);
        $url = teacher::marks_url((int) $course->id);
        $this->assertNotNull($url);
        $this->assertStringContainsString('/grade/report/grader/index.php', $url->out(false));
        $this->assertStringContainsString('id=' . $course->id, $url->out(false));
        $this->assertNull(teacher::marks_url((int) $secret->id));

        $tiles = $this->tiles_by_id(teacher::get_tiles());
        $this->assertSame('North room', $tiles['marks']['fact']);
        $this->assertStringContainsString('/grade/report/grader/index.php', $tiles['marks']['url']);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability(
            'gradereport/grader:view',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);
        $this->assertSame([], teacher::marks_courses());
        $this->assertNull(teacher::marks_url((int) $course->id));
    }

    /**
     * Overdue work and a long gap since the last visit are listed per course.
     */
    public function test_stuck_students_are_overdue_or_away_in_their_own_course(): void {
        $this->resetAfterTest();
        $now = time();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $quizcourse = $this->getDataGenerator()->create_course(['fullname' => 'Quiz room']);
        $quiet = $this->getDataGenerator()->create_course(['fullname' => 'Quiet room']);
        $secret = $this->getDataGenerator()->create_course(['fullname' => 'South room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $quizcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $quiet->id, 'editingteacher');
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $secret->id, 'editingteacher');

        $overdue = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $away = $this->getDataGenerator()->create_user(['firstname' => 'Hill', 'lastname' => 'Brook']);
        $fine = $this->getDataGenerator()->create_user(['firstname' => 'Fern', 'lastname' => 'Moss']);
        $extended = $this->getDataGenerator()->create_user(['firstname' => 'Lake', 'lastname' => 'Pine']);
        $quizstudent = $this->getDataGenerator()->create_user(['firstname' => 'Quiz', 'lastname' => 'Taker']);
        $recent = $this->getDataGenerator()->create_user(['firstname' => 'Recent', 'lastname' => 'Visitor']);
        $exact = $this->getDataGenerator()->create_user(['firstname' => 'Exact', 'lastname' => 'Week']);
        $never = $this->getDataGenerator()->create_user(['firstname' => 'Never', 'lastname' => 'Seen']);
        $secretstudent = $this->getDataGenerator()->create_user(['firstname' => 'Dale', 'lastname' => 'West']);
        foreach ([$overdue, $away, $fine, $extended] as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($quizstudent->id, $quizcourse->id, 'student');
        foreach ([$recent, $exact, $never] as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $quiet->id, 'student');
        }
        $this->getDataGenerator()->enrol_user($secretstudent->id, $secret->id, 'student');

        $late = $this->assignment($course->id, 'River essay', ['duedate' => $now - DAYSECS]);
        $this->assignment($course->id, 'Future essay', ['duedate' => $now + WEEKSECS]);
        $extendedassign = $this->assignment($course->id, 'Extended essay', ['duedate' => $now - HOURSECS]);
        $secretassign = $this->assignment($secret->id, 'Secret essay', ['duedate' => $now - DAYSECS]);
        $this->getDataGenerator()->create_module('quiz', [
            'course' => $quizcourse->id,
            'name' => 'Closed quiz',
            'timeclose' => $now - HOURSECS,
        ]);

        $this->submit((int) $fine->id, (int) $late->cmid);
        $this->submit((int) $fine->id, (int) $extendedassign->cmid);
        $this->submit((int) $extended->id, (int) $late->cmid);
        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $extendedassign->cmid,
            'userid' => $extended->id,
            'extensionduedate' => $now + WEEKSECS,
        ]);

        $this->visit((int) $overdue->id, (int) $course->id, $now);
        $this->visit((int) $away->id, (int) $course->id, $now - (8 * DAYSECS));
        $this->visit((int) $fine->id, (int) $course->id, $now);
        $this->visit((int) $extended->id, (int) $course->id, $now);
        $this->visit((int) $quizstudent->id, (int) $quizcourse->id, $now);
        $this->visit((int) $recent->id, (int) $quiet->id, $now - (6 * DAYSECS));
        $this->visit((int) $exact->id, (int) $quiet->id, $now - (7 * DAYSECS));
        $this->visit((int) $secretstudent->id, (int) $secret->id, $now - (20 * DAYSECS));

        $this->setUser($teacher);
        $rows = $this->rows_by_word(teacher::stuck_rows());

        $this->assertArrayHasKey('River Stone', $rows);
        $this->assertStringContainsString('River essay', $rows['River Stone']['fact']);
        $this->assertSame('North room', $rows['River Stone']['course']);
        $this->assertStringContainsString('action=grader', $rows['River Stone']['url']);

        $this->assertArrayHasKey('Hill Brook', $rows);
        $this->assertStringContainsString('River essay', $rows['Hill Brook']['fact']);
        $this->assertStringContainsString('8 days', $rows['Hill Brook']['fact']);

        $this->assertArrayHasKey('Quiz Taker', $rows);
        $this->assertStringContainsString('Closed quiz', $rows['Quiz Taker']['fact']);
        $this->assertSame('Quiz room', $rows['Quiz Taker']['course']);
        $this->assertStringContainsString('/mod/quiz/view.php', $rows['Quiz Taker']['url']);

        $this->assertArrayHasKey('Exact Week', $rows);
        $this->assertStringContainsString('7 days', $rows['Exact Week']['fact']);
        $this->assertSame('Quiet room', $rows['Exact Week']['course']);
        $this->assertArrayHasKey('Never Seen', $rows);
        $this->assertStringContainsString('Never', $rows['Never Seen']['fact']);
        $this->assertStringContainsString('/user/view.php', $rows['Never Seen']['url']);

        $this->assertArrayNotHasKey('Fern Moss', $rows);
        $this->assertArrayNotHasKey('Lake Pine', $rows);
        $this->assertArrayNotHasKey('Recent Visitor', $rows);
        $encoded = json_encode($rows);
        $this->assertStringNotContainsString('Dale West', $encoded);
        $this->assertStringNotContainsString('South room', $encoded);
        $this->assertStringNotContainsString('Future essay', $encoded);
        $this->assertStringNotContainsString('Secret essay', $encoded);
        $this->assertStringNotContainsString('Extended essay', $rows['River Stone']['fact']);
    }

    /**
     * Unread messages are the logged-in person's, and the list is only their students.
     */
    public function test_messages_are_the_teachers_students(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('messaging', 1);
        $teacher = $this->getDataGenerator()->create_user();
        $otherteacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $secret = $this->getDataGenerator()->create_course(['fullname' => 'South room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($otherteacher->id, $secret->id, 'editingteacher');
        $student = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $secretstudent = $this->getDataGenerator()->create_user(['firstname' => 'Dale', 'lastname' => 'West']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($secretstudent->id, $secret->id, 'student');
        message_helper::send_fake_message($student, $teacher, 'Hello north');
        message_helper::send_fake_message($secretstudent, $otherteacher, 'Hello south');

        $this->setUser($teacher);
        $this->assertSame(1, teacher::unread_count());
        $rows = teacher::message_rows();
        $this->assertCount(1, $rows);
        $this->assertSame('River Stone', $rows[0]['word']);
        $this->assertSame('North room', $rows[0]['fact']);
        $this->assertStringContainsString('/message/index.php', $rows[0]['url']);
        $this->assertStringContainsString('id=' . $student->id, $rows[0]['url']);
        $tiles = $this->tiles_by_id(teacher::get_tiles());
        $this->assertSame('1', $tiles['messages']['fact']);
        $this->assertStringContainsString('/local/wolfwhale/students.php', $tiles['messages']['url']);

        $this->setUser($otherteacher);
        $this->assertSame(1, teacher::unread_count());
        $this->assertStringNotContainsString('River Stone', json_encode(teacher::message_rows()));

        $userrole = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/site:sendmessage', CAP_PROHIBIT, $userrole, \context_system::instance()->id, true);
        $this->setUser($teacher);
        $tiles = $this->tiles_by_id(teacher::get_tiles());
        $this->assertArrayNotHasKey('messages', $tiles);
    }

    /**
     * The messages tile is absent when messaging is switched off.
     */
    public function test_messages_tile_is_left_out_when_messaging_is_off(): void {
        $this->resetAfterTest();
        set_config('messaging', 0);
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tiles = $this->tiles_by_id(teacher::get_tiles());
        $this->assertArrayNotHasKey('messages', $tiles);
        $this->assertSame([], teacher::message_rows());
    }

    /**
     * A course with nothing waiting shows None, and does not invent a count.
     */
    public function test_empty_teacher_home_is_honest(): void {
        $this->resetAfterTest();
        set_config('messaging', 1);
        $now = time();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Quiet room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->assignment($course->id, 'Next week', ['duedate' => $now + WEEKSECS]);
        $this->visit((int) $student->id, (int) $course->id, $now);

        $this->setUser($teacher);
        $tiles = $this->tiles_by_id(teacher::get_tiles());
        $this->assertSame('Quiet room', $tiles['post']['fact']);
        $this->assertSame('None', $tiles['marking']['fact']);
        $this->assertTrue($tiles['marking']['empty']);
        $this->assertSame('Quiet room', $tiles['attendance']['fact']);
        $this->assertStringContainsString('/local/wwattendance/take.php', $tiles['attendance']['url']);
        $this->assertSame('None', $tiles['messages']['fact']);
        $this->assertSame('None', $tiles['stuck']['fact']);
        $this->assertSame('Quiet room', $tiles['marks']['fact']);
        $this->assertSame('Marking. None', $tiles['marking']['speech']);
        $this->assertSame(
            ['post', 'marking', 'attendance', 'messages', 'stuck', 'marks', 'points', 'goal', 'library'],
            array_keys($tiles)
        );
    }

    /**
     * An ampersand in a real name is still one ampersand after the page is drawn.
     */
    public function test_ampersand_in_a_name_is_not_escaped_twice(): void {
        global $OUTPUT;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Arts & Music']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $tiles = $this->tiles_by_id(teacher::get_tiles());
        $this->assertSame('Arts & Music', $tiles['post']['fact']);
        $html = $OUTPUT->render_from_template('local_wolfwhale/teacher', teacher::export());
        $this->assertStringContainsString('Arts &amp; Music', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * A non-editing teacher in separate groups only sees their own groups.
     */
    public function test_marking_stays_inside_the_teachers_groups(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($outsider->id, $course->id, 'teacher');
        $mine = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $theirs = $this->getDataGenerator()->create_user(['firstname' => 'Dale', 'lastname' => 'West']);
        $this->getDataGenerator()->enrol_user($mine->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($theirs->id, $course->id, 'student');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group A']);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Group B']);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $mine->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $theirs->id]);

        $assign = $this->assignment($course->id, 'Grouped essay', ['groupmode' => SEPARATEGROUPS]);
        $this->submit((int) $mine->id, (int) $assign->cmid);
        $this->submit((int) $theirs->id, (int) $assign->cmid);

        $this->setUser($teacher);
        $items = teacher::marking_items();
        $this->assertCount(1, $items);
        $this->assertSame('River Stone', $items[0]['word']);
        $this->assertStringNotContainsString('Dale West', json_encode($items));

        $this->setUser($outsider);
        $this->assertSame([], teacher::marking_items());
    }

    /**
     * A quiz is late at its due date, even when it still accepts attempts.
     */
    public function test_a_quiz_due_date_counts_as_overdue(): void {
        $this->resetAfterTest();
        $now = time();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Quiz room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $student = $this->getDataGenerator()->create_user(['firstname' => 'Quiz', 'lastname' => 'Taker']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'Due quiz',
            'duedate' => $now - HOURSECS,
            'timeclose' => $now + WEEKSECS,
        ]);
        $this->visit((int) $student->id, (int) $course->id, $now);

        $this->setUser($teacher);
        $rows = $this->rows_by_word(teacher::stuck_rows());
        $this->assertArrayHasKey('Quiz Taker', $rows);
        $this->assertStringContainsString('Due quiz', $rows['Quiz Taker']['fact']);
    }

    /**
     * A group restriction keeps students outside that group off the overdue list.
     */
    public function test_overdue_work_ignores_students_who_cannot_see_the_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $now = time();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $inside = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $outside = $this->getDataGenerator()->create_user(['firstname' => 'Dale', 'lastname' => 'West']);
        $this->getDataGenerator()->enrol_user($inside->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($outside->id, $course->id, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $inside->id]);
        $assign = $this->assignment($course->id, 'Group essay', ['duedate' => $now - DAYSECS]);
        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_group\condition::get_json($group->id),
        ]));
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $assign->cmid]);
        rebuild_course_cache($course->id, true);
        $this->visit((int) $inside->id, (int) $course->id, $now);
        $this->visit((int) $outside->id, (int) $course->id, $now);

        $this->setUser($teacher);
        $rows = $this->rows_by_word(teacher::stuck_rows());
        $this->assertArrayHasKey('River Stone', $rows);
        $this->assertArrayNotHasKey('Dale West', $rows);
    }

    /**
     * A student who calls the teacher data sees none of it.
     */
    public function test_a_student_sees_no_teacher_data(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'North room']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $assign = $this->assignment($course->id, 'River essay', ['duedate' => time() - DAYSECS]);
        $this->submit((int) $student->id, (int) $assign->cmid);

        $this->setUser($student);
        $this->assertSame([], teacher::courses());
        $this->assertSame([], teacher::marking_items());
        $this->assertSame([], teacher::stuck_rows());
        $this->assertSame([], teacher::post_courses());
        $this->assertSame([], teacher::marks_courses());
    }

    /**
     * Create an assignment.
     *
     * @param int $courseid
     * @param string $name
     * @param array $extra
     * @return \stdClass
     */
    private function assignment(int $courseid, string $name, array $extra = []): \stdClass {
        return $this->getDataGenerator()->create_module('assign', $extra + [
            'course' => $courseid,
            'name' => $name,
            'grade' => 100,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
        ]);
    }

    /**
     * Hand in an online text.
     *
     * @param int $userid
     * @param int $cmid
     */
    private function submit(int $userid, int $cmid): void {
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $userid,
            'cmid' => $cmid,
            'status' => 'submitted',
            'onlinetext' => 'Work',
        ]);
    }

    /**
     * Give a grade so the submission no longer needs marking.
     *
     * @param \stdClass $assign
     * @param \stdClass $course
     * @param \stdClass $teacher
     * @param \stdClass $student
     */
    private function grade(\stdClass $assign, \stdClass $course, \stdClass $teacher, \stdClass $student): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $this->setUser($teacher);
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $object = new \assign($context, $cm, $course);
        $grade = $object->get_user_grade($student->id, true);
        $grade->grade = '80.00000';
        $grade->grader = $teacher->id;
        $object->update_grade($grade);
        // A grade stored in the same second as the submission still counts as
        // needing a grade. Move the grade a little later.
        $submission = $object->get_user_submission($student->id, false);
        $stored = $object->get_user_grade($student->id, false);
        $stored->timemodified = ((int) $submission->timemodified) + 10;
        $DB->update_record('assign_grades', $stored);
    }

    /**
     * Record a course visit.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $time
     */
    private function visit(int $userid, int $courseid, int $time): void {
        global $DB;

        $existing = $DB->get_record('user_lastaccess', ['userid' => $userid, 'courseid' => $courseid]);
        if ($existing) {
            $existing->timeaccess = $time;
            $DB->update_record('user_lastaccess', $existing);
            return;
        }
        $DB->insert_record('user_lastaccess', (object) [
            'userid' => $userid,
            'courseid' => $courseid,
            'timeaccess' => $time,
        ]);
    }

    /**
     * Index tiles by id.
     *
     * @param array[] $tiles
     * @return array<string, array>
     */
    private function tiles_by_id(array $tiles): array {
        $indexed = [];
        foreach ($tiles as $tile) {
            $indexed[$tile['id']] = $tile;
        }
        return $indexed;
    }

    /**
     * Index rows by the main word.
     *
     * @param array[] $rows
     * @return array<string, array>
     */
    private function rows_by_word(array $rows): array {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['word']] = $row;
        }
        return $indexed;
    }
}
