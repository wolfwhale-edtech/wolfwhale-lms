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

namespace local_wwattendance;

use local_wwattendance\privacy\provider;

/**
 * Tests for attendance.
 *
 * @package    local_wwattendance
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attendance::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class attendance_test extends \advanced_testcase {
    /**
     * A teacher can save and read back. A second save updates the same day.
     */
    public function test_teacher_can_save_and_update_the_same_day(): void {
        global $DB;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $river = $this->getDataGenerator()->create_user(['firstname' => 'River', 'lastname' => 'Stone']);
        $hill = $this->getDataGenerator()->create_user(['firstname' => 'Hill', 'lastname' => 'Brook']);
        $this->getDataGenerator()->enrol_user($river->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($hill->id, $course->id, 'student');
        $day = 1700000000;

        $this->setUser($teacher);
        $this->assertTrue(attendance::can_take((int) $course->id));
        $students = attendance::students((int) $course->id);
        $this->assertArrayHasKey($river->id, $students);
        $this->assertArrayHasKey($hill->id, $students);
        $this->assertSame(0, attendance::save((int) $course->id, [], $day));
        $this->assertSame([], attendance::marks((int) $course->id, $day));

        $sessionid = attendance::save((int) $course->id, [
            $river->id => attendance::PRESENT,
        ], $day);
        $this->assertGreaterThan(0, $sessionid);
        $this->assertSame([
            (int) $river->id => attendance::PRESENT,
        ], attendance::marks((int) $course->id, $day));
        $this->assertSame(1, $DB->count_records('local_wwattendance_log', ['sessionid' => $sessionid]));

        attendance::save((int) $course->id, [
            $river->id => attendance::LATE,
            $hill->id => attendance::ABSENT,
        ], $day);
        $this->assertSame([
            (int) $river->id => attendance::LATE,
            (int) $hill->id => attendance::ABSENT,
        ], attendance::marks((int) $course->id, $day));
        $this->assertSame(1, $DB->count_records('local_wwattendance_session', ['courseid' => $course->id]));
        $this->assertSame(2, $DB->count_records('local_wwattendance_log', ['sessionid' => $sessionid]));

        $nextday = $day + DAYSECS;
        attendance::save((int) $course->id, [
            $river->id => attendance::PRESENT,
        ], $nextday);
        $this->assertSame(attendance::PRESENT, attendance::marks((int) $course->id, $nextday)[(int) $river->id]);
        $this->assertSame(attendance::LATE, attendance::marks((int) $course->id, $day)[(int) $river->id]);
        $this->assertSame(2, $DB->count_records('local_wwattendance_session', ['courseid' => $course->id]));
    }

    /**
     * Two teachers with different clocks share one sheet for the site's day.
     */
    public function test_the_attendance_day_ignores_profile_timezones(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $east = $this->getDataGenerator()->create_user();
        $west = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($east->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($west->id, $course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $DB->set_field('user', 'timezone', 'Pacific/Auckland', ['id' => $east->id]);
        $DB->set_field('user', 'timezone', 'Pacific/Honolulu', ['id' => $west->id]);

        // 2023-11-14 22:13 UTC is the next day in Auckland and the same day in Honolulu.
        $stamp = 1700000000;
        $this->setUser($east);
        $eastday = attendance::day_start($stamp);
        $this->setUser($west);
        $westday = attendance::day_start($stamp);
        $this->assertSame($eastday, $westday);

        attendance::save((int) $course->id, [
            $student->id => attendance::PRESENT,
        ], $westday);
        $this->setUser($east);
        $this->assertSame(
            attendance::PRESENT,
            attendance::marks((int) $course->id, $eastday)[(int) $student->id]
        );
    }

    /**
     * A non-editing teacher may take attendance. A student and a manager may not.
     */
    public function test_only_teachers_can_take_attendance(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $plain = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($plain->id, $course->id, 'teacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');

        $this->setUser($teacher);
        $this->assertTrue(attendance::can_take((int) $course->id));
        $this->setUser($plain);
        $this->assertTrue(attendance::can_take((int) $course->id));
        $this->setUser($student);
        $this->assertFalse(attendance::can_take((int) $course->id));
        $this->setUser($manager);
        $this->assertFalse(attendance::can_take((int) $course->id));

        $this->expectException(\core\exception\required_capability_exception::class);
        attendance::students((int) $course->id);
    }

    /**
     * A teacher cannot take attendance in someone else's course.
     */
    public function test_teacher_cannot_take_attendance_in_another_course(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $mine = $this->getDataGenerator()->create_course();
        $theirs = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $mine->id, 'editingteacher');
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $theirs->id, 'student');
        $this->setUser($teacher);

        $this->assertFalse(attendance::can_take((int) $theirs->id));
        $this->expectException(\core\exception\required_capability_exception::class);
        attendance::save((int) $theirs->id, [$student->id => attendance::PRESENT], 1700000000);
    }

    /**
     * A student from another course is not stored, and a bad status is refused.
     */
    public function test_save_ignores_other_courses_and_rejects_a_bad_status(): void {
        global $DB;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'teacher');
        $student = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($outsider->id, $other->id, 'student');
        $this->setUser($teacher);

        $day = 1700000000;
        attendance::save((int) $course->id, [
            $student->id => attendance::PRESENT,
            $outsider->id => attendance::ABSENT,
        ], $day);
        $marks = attendance::marks((int) $course->id, $day);
        $this->assertSame([
            (int) $student->id => attendance::PRESENT,
        ], $marks);
        $this->assertSame(1, $DB->count_records('local_wwattendance_log'));

        $this->expectException(\core\exception\moodle_exception::class);
        attendance::save((int) $course->id, [$student->id => 'excused'], $day);
    }

    /**
     * Prohibiting the capability removes access even for an editing teacher.
     */
    public function test_prohibited_capability_blocks_taking_attendance(): void {
        global $DB;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'local/wwattendance:take',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);
        $this->assertFalse(attendance::can_take((int) $course->id));
        $this->expectException(\core\exception\required_capability_exception::class);
        attendance::marks((int) $course->id, 1700000000);
    }

    /**
     * Export and delete keep one person's data apart from another's.
     */
    public function test_privacy_export_and_delete_keep_courses_apart(): void {
        global $DB;

        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $othercourse->id, 'editingteacher');
        $river = $this->getDataGenerator()->create_user();
        $hill = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($river->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($hill->id, $othercourse->id, 'student');

        $this->setUser($teacher);
        attendance::save((int) $course->id, [$river->id => attendance::PRESENT], 1700000000);
        attendance::save((int) $othercourse->id, [$hill->id => attendance::ABSENT], 1700000000);

        $rivercontext = \context_course::instance($course->id);
        $hillcontext = \context_course::instance($othercourse->id);
        $riverids = array_map('intval', provider::get_contexts_for_userid((int) $river->id)->get_contextids());
        $this->assertContains((int) $rivercontext->id, $riverids);
        $this->assertNotContains((int) $hillcontext->id, $riverids);

        $teachercontexts = array_map('intval', provider::get_contexts_for_userid((int) $teacher->id)->get_contextids());
        $this->assertContains((int) $rivercontext->id, $teachercontexts);
        $this->assertContains((int) $hillcontext->id, $teachercontexts);

        \core_privacy\local\request\writer::reset();
        provider::export_user_data(new \core_privacy\local\request\approved_contextlist(
            $river,
            'local_wwattendance',
            [$rivercontext->id]
        ));
        $written = \core_privacy\local\request\writer::with_context($rivercontext)->get_data([
            get_string('pluginname', 'local_wwattendance'),
        ]);
        $this->assertNotEmpty($written->marks);
        $this->assertSame(attendance::PRESENT, reset($written->marks)->status);

        provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $river,
            'local_wwattendance',
            [$rivercontext->id]
        ));
        $this->setUser($teacher);
        $this->assertSame([], attendance::marks((int) $course->id, 1700000000));
        $this->assertSame(
            attendance::ABSENT,
            attendance::marks((int) $othercourse->id, 1700000000)[(int) $hill->id]
        );

        provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $teacher,
            'local_wwattendance',
            [$hillcontext->id]
        ));
        $session = $DB->get_record('local_wwattendance_session', [
            'courseid' => $othercourse->id,
            'sessdate' => 1700000000,
        ], '*', MUST_EXIST);
        $this->assertSame('0', (string) $session->takenby);
        $this->assertSame(1, $DB->count_records('local_wwattendance_log', ['sessionid' => $session->id]));
    }

    /**
     * A teacher can take attendance and still cannot use the single-person read.
     */
    public function test_parent_style_read_is_not_the_take_list(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($teacher);
        attendance::save((int) $course->id, [$student->id => attendance::PRESENT], 1700000000);
        $this->assertFalse(attendance::can_view_user((int) $student->id));
        $this->expectException(\moodle_exception::class);
        attendance::records_for_user((int) $student->id);
    }
}
