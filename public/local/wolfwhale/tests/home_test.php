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
 * Tests for the student home.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(home::class)]
final class home_test extends \advanced_testcase {
    /**
     * A student sees their own next activity, due work, and course, and not another student's.
     */
    public function test_student_sees_only_their_own_activities_and_course(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'River class']);
        $secret = $this->getDataGenerator()->create_course(['fullname' => 'Secret class']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $secret->id, 'student');

        $soon = time() + DAYSECS;
        $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'River essay',
            'duedate' => $soon,
        ]);
        $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'name' => 'River quiz',
            'timeclose' => $soon + HOURSECS,
        ]);
        $this->getDataGenerator()->create_module('assign', [
            'course' => $secret->id,
            'name' => 'Secret essay',
            'duedate' => $soon,
        ]);

        $this->setUser($student);
        $tiles = $this->tiles_by_id(home::get_tiles());

        $this->assertSame('River essay', $tiles['today']['fact']);
        $this->assertStringContainsString('/mod/assign/view.php', $tiles['today']['url']);
        $this->assertFalse($tiles['today']['empty']);
        $this->assertSame('2', $tiles['due']['fact']);
        $this->assertStringContainsString('view=upcoming', $tiles['due']['url']);
        $this->assertSame('River class', $tiles['courses']['fact']);
        $this->assertStringContainsString('/course/view.php', $tiles['courses']['url']);
        $encoded = json_encode($tiles);
        $this->assertStringNotContainsString('Secret essay', $encoded);
        $this->assertStringNotContainsString('Secret class', $encoded);

        $this->setUser($other);
        $othertiles = $this->tiles_by_id(home::get_tiles());
        $this->assertSame('Secret essay', $othertiles['today']['fact']);
        $this->assertSame('1', $othertiles['due']['fact']);
        $this->assertStringContainsString('/mod/assign/view.php', $othertiles['due']['url']);
        $this->assertSame('Secret class', $othertiles['courses']['fact']);
        $otherencoded = json_encode($othertiles);
        $this->assertStringNotContainsString('River essay', $otherencoded);
        $this->assertStringNotContainsString('River class', $otherencoded);
    }

    /**
     * A student with nothing scheduled gets short empty lines and no invented numbers.
     */
    public function test_empty_home_is_honest(): void {
        $this->resetAfterTest();
        set_config('enablebadges', 1);
        set_config('messaging', 1);
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $tiles = $this->tiles_by_id(home::get_tiles());

        $this->assertSame('Nothing today', $tiles['today']['fact']);
        $this->assertTrue($tiles['today']['empty']);
        $this->assertSame('Nothing due', $tiles['due']['fact']);
        $this->assertTrue($tiles['due']['empty']);
        $this->assertSame('None', $tiles['badges']['fact']);
        $this->assertSame('None', $tiles['messages']['fact']);
        $this->assertSame('None', $tiles['courses']['fact']);
        $this->assertSame('Today. Nothing today', $tiles['today']['speech']);
    }

    /**
     * A student sees only a badge that was issued to them.
     */
    public function test_student_sees_only_their_own_badge(): void {
        $this->resetAfterTest();
        set_config('enablebadges', 1);
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        /** @var \core_badges_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_badges');
        $river = $generator->create_badge(['name' => 'River badge']);
        $hill = $generator->create_badge(['name' => 'Hill badge']);
        $river->issue($student->id, true);
        $hill->issue($other->id, true);

        $this->setUser($student);
        $tiles = $this->tiles_by_id(home::get_tiles());
        $this->assertSame('River badge', $tiles['badges']['fact']);
        $this->assertStringContainsString('/badges/mybadges.php', $tiles['badges']['url']);
        $this->assertStringNotContainsString('Hill badge', json_encode($tiles));

        $this->setUser($other);
        $othertiles = $this->tiles_by_id(home::get_tiles());
        $this->assertSame('Hill badge', $othertiles['badges']['fact']);
        $this->assertStringNotContainsString('River badge', json_encode($othertiles));
    }

    /**
     * The badges tile is absent when badges are switched off.
     */
    public function test_badges_tile_is_left_out_when_badges_are_off(): void {
        $this->resetAfterTest();
        set_config('enablebadges', 0);
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $tiles = $this->tiles_by_id(home::get_tiles());
        $this->assertArrayNotHasKey('badges', $tiles);
    }

    /**
     * Unread messages are counted for the logged-in person only.
     */
    public function test_student_sees_only_their_own_unread_messages(): void {
        $this->resetAfterTest();
        set_config('messaging', 1);
        $sender = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        message_helper::send_fake_message($sender, $student, 'Hello river');
        message_helper::send_fake_message($sender, $other, 'Hello hill');
        message_helper::send_fake_message($sender, $other, 'Hello again', 0, time() + 1);

        $this->setUser($student);
        $tiles = $this->tiles_by_id(home::get_tiles());
        $this->assertSame('1', $tiles['messages']['fact']);
        $this->assertStringContainsString('/message/index.php', $tiles['messages']['url']);

        $this->setUser($other);
        $othertiles = $this->tiles_by_id(home::get_tiles());
        $this->assertSame('1', $othertiles['messages']['fact']);
    }

    /**
     * The messages tile is absent when messaging is switched off.
     */
    public function test_messages_tile_is_left_out_when_messaging_is_off(): void {
        $this->resetAfterTest();
        set_config('messaging', 0);
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $tiles = $this->tiles_by_id(home::get_tiles());
        $this->assertArrayNotHasKey('messages', $tiles);
    }

    /**
     * Two courses show a count and open the course list.
     */
    public function test_several_courses_show_a_count(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $first = $this->getDataGenerator()->create_course(['fullname' => 'Art']);
        $second = $this->getDataGenerator()->create_course(['fullname' => 'Music']);
        $this->getDataGenerator()->enrol_user($student->id, $first->id, 'student');
        $this->getDataGenerator()->enrol_user($student->id, $second->id, 'student');
        $this->setUser($student);

        $tiles = $this->tiles_by_id(home::get_tiles());
        $this->assertSame('2', $tiles['courses']['fact']);
        $this->assertStringContainsString('/my/courses.php', $tiles['courses']['url']);
    }

    /**
     * A teacher, a manager, a course creator, and an admin stay on the dashboard.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('staff_role_provider')]
    public function test_staff_are_not_redirected(string $roleshortname, bool $system): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        if ($system) {
            $roleid = $DB->get_field('role', 'id', ['shortname' => $roleshortname], MUST_EXIST);
            role_assign($roleid, $user->id, \context_system::instance()->id);
        } else {
            $course = $this->getDataGenerator()->create_course();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleshortname);
        }
        $this->setUser($user);

        $this->assertFalse(home::is_student((int) $user->id));
        $this->assertFalse(home::should_redirect(new \moodle_url('/my/index.php'), (int) $user->id));
        $this->assertNull(home::login_wantsurl(null, (int) $user->id));
    }

    /**
     * Staff roles that must keep the normal dashboard.
     *
     * @return array[]
     */
    public static function staff_role_provider(): array {
        return [
            'editing teacher' => ['editingteacher', false],
            'teacher' => ['teacher', false],
            'manager' => ['manager', true],
            'course creator' => ['coursecreator', true],
        ];
    }

    /**
     * An administrator is not sent to the student home.
     */
    public function test_admin_is_not_redirected(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        $this->assertFalse(home::is_student((int) $USER->id));
        $this->assertFalse(home::should_redirect(new \moodle_url('/my/'), (int) $USER->id));
    }

    /**
     * A student who also teaches somewhere keeps the dashboard.
     */
    public function test_student_who_also_teaches_is_not_redirected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $class = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $class->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $other->id, 'editingteacher');

        $this->assertFalse(home::is_student((int) $user->id));
        $this->assertFalse(home::should_redirect(new \moodle_url('/my/index.php'), (int) $user->id));
    }

    /**
     * A student is sent home from the dashboard, and a deep link is kept.
     */
    public function test_student_is_redirected_home_but_keeps_a_deep_link(): void {
        global $CFG;
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $home = home::url()->out(false);
        $this->assertTrue(home::is_student((int) $student->id));
        $this->assertTrue(home::should_redirect(new \moodle_url('/my/index.php'), (int) $student->id));
        $this->assertTrue(home::should_redirect(new \moodle_url('/my/'), (int) $student->id));
        $this->assertFalse(home::should_redirect(new \moodle_url('/my/courses.php'), (int) $student->id));
        $this->assertFalse(home::should_redirect(home::url(), (int) $student->id));

        $this->assertSame($home, home::login_wantsurl(null, (int) $student->id));
        $this->assertSame($home, home::login_wantsurl($CFG->wwwroot . '/', (int) $student->id));
        $this->assertSame($home, home::login_wantsurl($CFG->wwwroot . '/my/', (int) $student->id));
        $this->assertSame($home, home::login_wantsurl($CFG->wwwroot . '/my/index.php', (int) $student->id));

        $courseurl = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $this->assertNull(home::login_wantsurl($courseurl, (int) $student->id));
    }

    /**
     * The hooks do not redirect while the test suite is running.
     */
    public function test_hooks_stay_quiet_during_phpunit(): void {
        $this->resetAfterTest();
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);
        hook_callbacks::before_http_headers();
        hook_callbacks::after_login_completed();
        $this->assertTrue(home::is_student((int) $student->id));
    }

    /**
     * Index the tiles by id.
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
}
