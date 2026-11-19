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

namespace local_wwupdates;

/**
 * Parent updates stay inside one family and one school.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(deliver::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(summary::class)]
final class updates_test extends \advanced_testcase {
    /**
     * A parent hears about their own child. A parent in another school does not.
     */
    public function test_parent_gets_updates_for_own_child_only(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectEmails();
        $north = $this->family('North School', 'River class', 'Ada', 'Pat', 'Lee', 'pat.north@example.com');
        $south = $this->family('South School', 'Hill class', 'Cleo', 'Sam', 'Nia', 'sam.south@example.com');

        $this->grade($north, 'River essay', 83);
        $this->setUser($north['teacher']);
        \local_wwattendance\attendance::save($north['course']->id, [$north['child']->id => 'absent']);

        $pat = $this->emails_to($sink, 'pat.north@example.com');
        $sam = $this->emails_to($sink, 'sam.south@example.com');
        $pattext = $this->joined($pat);
        $this->assertStringContainsString('North School', $pattext);
        $this->assertStringContainsString('Ada', $pattext);
        $this->assertStringContainsString('83', $pattext);
        $this->assertStringContainsString('River essay', $pattext);
        $this->assertStringContainsString('Absent: River class.', $pattext);
        $this->assertStringNotContainsString('Moodle', $pattext);
        $this->assertStringNotContainsString('Cleo', $pattext);
        $this->assertStringNotContainsString('Hill', $pattext);
        $this->assertSame([], $sam);
        $this->assertNotEmpty($this->popup_messages((int) $north['parent']->id));
        $this->assertSame([], $this->popup_messages((int) $south['parent']->id));

        $before = count($pat);
        $this->grade($south, 'Hill essay', 11, $north['child']);
        $after = $this->joined($this->emails_to($sink, 'pat.north@example.com'));
        $this->assertStringNotContainsString('Hill essay', $after);
        $this->assertStringNotContainsString('11', $after);
        $this->assertCount($before, $this->emails_to($sink, 'pat.north@example.com'));
        $sink->close();
    }

    /**
     * A channel that is off sends nothing.
     */
    public function test_channel_off_sends_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectEmails();
        $north = $this->family('North School', 'River class', 'Ada', 'Pat', 'Lee', 'pat.off@example.com');
        $this->setUser($north['parent']);
        preferences::save((int) $north['parent']->id, [
            'app' => 0,
            'email' => 0,
            'text' => 0,
            'mark' => 1,
            'absent' => 1,
            'due' => 1,
            'badge' => 1,
            'message' => 1,
            'weekly' => 1,
        ]);
        $this->grade($north, 'River essay', 83);
        $this->assertSame([], $this->emails_to($sink, 'pat.off@example.com'));
        $this->assertSame([], $this->popup_messages((int) $north['parent']->id));
        $this->assertSame(0, $DB->count_records('sms_messages', ['recipientuserid' => $north['parent']->id]));

        preferences::save((int) $north['parent']->id, [
            'app' => 1,
            'email' => 0,
            'text' => 0,
            'mark' => 1,
        ]);
        $this->grade($north, 'River essay', 90);
        $this->assertSame([], $this->emails_to($sink, 'pat.off@example.com'));
        $popups = $this->popup_messages((int) $north['parent']->id);
        $this->assertCount(1, $popups);
        $popup = reset($popups);
        $this->assertStringContainsString('Ada', $popup->fullmessage);
        $this->assertStringContainsString('90', $popup->fullmessage);
        $this->assertStringNotContainsString('Moodle', $popup->fullmessage);
        $sink->close();
    }

    /**
     * Text sends nothing until a gateway exists, and only to that parent's number.
     */
    public function test_text_sends_nothing_without_a_gateway(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $north = $this->family('North School', 'River class', 'Ada', 'Pat', 'Lee', 'pat.text@example.com');
        $south = $this->family('South School', 'Hill class', 'Cleo', 'Sam', 'Nia', 'sam.text@example.com');
        $this->setUser($north['parent']);
        preferences::save((int) $north['parent']->id, $this->text_only());
        preferences::set_phone((int) $north['parent']->id, '+1 (555) 555-0100');
        $this->setUser($south['parent']);
        preferences::set_phone((int) $south['parent']->id, '+15555550199');
        try {
            preferences::set_phone((int) $north['parent']->id, '+15555550100');
            $this->fail('A parent saved another parent\'s phone.');
        } catch (\moodle_exception $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertSame('+15555550100', preferences::phone((int) $north['parent']->id));
        $this->assertSame('+15555550199', preferences::phone((int) $south['parent']->id));

        $this->grade($north, 'River essay', 70);
        $this->assertSame(0, $DB->count_records('sms_messages'));

        require_once($CFG->dirroot . '/sms/tests/fixtures/dummy_gateway.php');
        $manager = \core\di::get(\core_sms\manager::class);
        $manager->create_gateway_instance(
            classname: \smsgateway_dummy\gateway::class,
            name: 'fake',
            enabled: true,
            config: (object) ['priority' => 50],
        );
        $this->grade($north, 'River essay', 71);
        $this->run_sms_tasks();
        $messages = $DB->get_records('sms_messages');
        $this->assertCount(1, $messages);
        $sms = reset($messages);
        $this->assertSame('+15555550100', $sms->recipientnumber);
        $this->assertSame((string) $north['parent']->id, (string) $sms->recipientuserid);
        $this->assertStringContainsString('Ada', $sms->content);
        $this->assertStringContainsString('71', $sms->content);
        $this->assertStringNotContainsString('Cleo', $sms->content);
        $this->assertStringNotContainsString('Moodle', $sms->content);
        $this->assertSame('gateway_sent', $sms->status);

        $this->setUser($north['parent']);
        preferences::save((int) $north['parent']->id, [
            'app' => 0,
            'email' => 0,
            'text' => 0,
            'mark' => 1,
        ]);
        $this->grade($north, 'River essay', 72);
        $this->run_sms_tasks();
        $this->assertCount(1, $DB->get_records('sms_messages'));
    }

    /**
     * The weekly summary is the week's facts, once.
     */
    public function test_weekly_summary_is_sent_once(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        $CFG->enablebadges = 1;
        $sink = $this->redirectEmails();
        $north = $this->family('North School', 'River class', 'Ada', 'Pat', 'Lee', 'pat.week@example.com');
        $south = $this->family('South School', 'Hill class', 'Cleo', 'Sam', 'Nia', 'sam.week@example.com');
        $this->grade($north, 'River essay', 83, null, time() + 2 * DAYSECS);
        $this->setUser($north['teacher']);
        \local_wwattendance\attendance::save($north['course']->id, [$north['child']->id => 'absent']);
        $badge = $this->getDataGenerator()->get_plugin_generator('core_badges')->create_badge([
            'name' => 'River star',
            'type' => BADGE_TYPE_COURSE,
            'courseid' => $north['course']->id,
        ]);
        $badge->issue($north['child']->id, true);
        \local_wwpoints\points::record(
            (int) $north['child']->id,
            (int) $north['course']->id,
            20,
            'Star',
            'manual',
            'manual:week:' . $north['child']->id
        );
        $this->setUser($north['teacher']);
        $message = new \core\message\message();
        $message->courseid = $north['course']->id;
        $message->component = 'moodle';
        $message->name = 'instantmessage';
        $message->userfrom = $north['teacher']->id;
        $message->userto = $north['child']->id;
        $message->subject = 'Pencil';
        $message->fullmessage = 'Bring a pencil.';
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = 'Bring a pencil.';
        $message->notification = 0;
        $this->assertNotFalse(message_send($message));

        $now = new \DateTimeImmutable('now', \core_date::get_server_timezone_object());
        $this->setAdminUser();
        schedule::save((int) $north['category']->id, (int) $now->format('w'), (int) $now->format('G'));
        schedule::save((int) $south['category']->id, ((int) $now->format('w') + 1) % 7, 8);

        $sink->clear();
        $task = new \local_wwupdates\task\send_task();
        $task->execute();
        $pat = $this->emails_to($sink, 'pat.week@example.com');
        $weekly = null;
        foreach ($pat as $email) {
            if (str_contains($email->body, 'Absent: River class.') && str_contains($email->body, 'Badge: River star.')) {
                $weekly = $email;
            }
        }
        $this->assertNotNull($weekly);
        $this->assertSame('North School. Ada', $weekly->subject);
        $body = $weekly->body;
        $this->assertStringContainsString('North School', $body);
        $this->assertStringContainsString('Ada', $body);
        $this->assertStringContainsString('Mark:', $body);
        $this->assertStringContainsString('83', $body);
        $this->assertStringContainsString('River essay', $body);
        $this->assertStringContainsString('Due: River essay.', $body);
        $this->assertStringContainsString('Points:', $body);
        $this->assertStringContainsString('Level', $body);
        $this->assertStringContainsString('Message from Lee.', $body);
        $this->assertStringNotContainsString('pencil', $body);
        $this->assertStringNotContainsString('Cleo', $body);
        $this->assertStringNotContainsString('Hill', $body);
        $this->assertStringNotContainsString('Moodle', $body);
        $this->assertSame([], $this->emails_to($sink, 'sam.week@example.com'));
        $this->assertSame(1, $this->count_kind((int) $north['parent']->id, (int) $north['child']->id, 'weekly'));

        $task->execute();
        $again = 0;
        foreach ($this->emails_to($sink, 'pat.week@example.com') as $email) {
            if (str_contains($email->body, 'Absent: River class.') && str_contains($email->body, 'Badge: River star.')) {
                $again++;
            }
        }
        $this->assertSame(1, $again);
        $this->assertSame(1, $this->count_kind((int) $north['parent']->id, (int) $north['child']->id, 'weekly'));
        $this->assertSame([], $this->emails_to($sink, 'sam.week@example.com'));
        $sink->close();
    }

    /**
     * A school administrator cannot set another school's day.
     */
    public function test_other_school_cannot_set_the_day(): void {
        global $DB;

        $this->resetAfterTest();
        $north = $this->family('North School', 'River class', 'Ada', 'Pat', 'Lee', 'pat.day@example.com');
        $south = $this->family('South School', 'Hill class', 'Cleo', 'Sam', 'Nia', 'sam.day@example.com');
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($roleid, $manager->id, \context_coursecat::instance($south['category']->id)->id);
        $this->setUser($manager);
        $this->assertContains((int) $south['category']->id, schedule::roots());
        $this->assertNotContains((int) $north['category']->id, schedule::roots());
        try {
            schedule::save((int) $north['category']->id, 3, 9);
            $this->fail('A South administrator set the North day.');
        } catch (\moodle_exception $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->setAdminUser();
        schedule::save((int) $north['category']->id, 3, 9);
        $saved = schedule::get((int) $north['category']->id);
        $this->assertSame(3, (int) $saved->weekday);
        $this->assertSame(9, (int) $saved->hour);
        $southsaved = schedule::get((int) $south['category']->id);
        $this->assertSame(1, (int) $southsaved->weekday);
    }

    /**
     * A course that hides marks, and a badge the child has hidden, are not sent.
     */
    public function test_hidden_marks_and_badges_are_not_sent(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->enablebadges = 1;
        $north = $this->family('North School', 'River class', 'Ada', 'Pat', 'Lee', 'pat.hidden@example.com');
        $DB->set_field('course', 'showgrades', 0, ['id' => $north['course']->id]);
        rebuild_course_cache($north['course']->id, true);
        $this->grade($north, 'Secret essay', 40);
        $this->assertStringNotContainsString('Secret essay', $this->note_text((int) $north['parent']->id));

        $now = new \DateTimeImmutable('now', \core_date::get_server_timezone_object());
        $this->setAdminUser();
        schedule::save((int) $north['category']->id, (int) $now->format('w'), (int) $now->format('G'));
        (new \local_wwupdates\task\send_task())->execute();
        $this->assertStringNotContainsString('Secret essay', $this->note_text((int) $north['parent']->id));

        set_user_preference('badgeprivacysetting', 0, $north['child']->id);
        $badge = $this->getDataGenerator()->get_plugin_generator('core_badges')->create_badge([
            'name' => 'Quiet star',
            'type' => BADGE_TYPE_COURSE,
            'courseid' => $north['course']->id,
        ]);
        $badge->issue($north['child']->id, true);
        $this->assertStringNotContainsString('Quiet star', $this->note_text((int) $north['parent']->id));
    }

    /**
     * Export and delete still find the rows when the user id comes back as text.
     */
    public function test_privacy_export_and_delete_use_the_user_id(): void {
        global $DB;

        $this->resetAfterTest();
        $north = $this->family('North School', 'River class', 'Ada', 'Pat', 'Lee', 'pat.privacy@example.com');
        $this->grade($north, 'River essay', 83);
        $parent = $DB->get_record('user', ['id' => $north['parent']->id]);
        $context = \context_user::instance($parent->id);
        $list = new \core_privacy\local\request\approved_contextlist($parent, 'local_wwupdates', [$context->id]);

        \core_privacy\local\request\writer::reset();
        \local_wwupdates\privacy\provider::export_user_data($list);
        $data = \core_privacy\local\request\writer::with_context($context)->get_data(['Updates']);
        $this->assertNotEmpty($data->updates);
        $this->assertSame('mark', $data->updates[0]->kind);

        \local_wwupdates\privacy\provider::delete_data_for_user($list);
        $this->assertSame(0, $DB->count_records('local_wwupdates_sent', ['parentid' => $parent->id]));
    }

    /**
     * One school, one child, one parent, one teacher.
     *
     * @param string $school
     * @param string $course
     * @param string $child
     * @param string $parent
     * @param string $teacher
     * @param string $email
     * @return array
     */
    private function family(
        string $school,
        string $course,
        string $child,
        string $parent,
        string $teacher,
        string $email
    ): array {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category(['name' => $school]);
        $course = $generator->create_course([
            'category' => $category->id,
            'fullname' => $course,
            'shortname' => strtolower(str_replace(' ', '', $course)) . $category->id,
            'showgrades' => 1,
        ]);
        $child = $generator->create_user(['firstname' => $child, 'lastname' => 'Moss']);
        $parent = $generator->create_user(['firstname' => $parent, 'lastname' => 'Moss', 'email' => $email]);
        $teacher = $generator->create_user(['firstname' => $teacher, 'lastname' => 'Moss']);
        $generator->enrol_user($child->id, $course->id, 'student');
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setAdminUser();
        \local_wolfwhale\parents::install_role();
        role_assign(
            \local_wolfwhale\parents::role_id(),
            $parent->id,
            \context_user::instance($child->id)->id
        );
        return [
            'category' => $category,
            'course' => $course,
            'child' => $child,
            'parent' => $parent,
            'teacher' => $teacher,
        ];
    }

    /**
     * Grade an assignment. The child defaults to the family's child.
     *
     * @param array $family
     * @param string $name
     * @param float $grade
     * @param \stdClass|null $child
     * @param int $due
     */
    private function grade(array $family, string $name, float $grade, ?\stdClass $child = null, int $due = 0): void {
        global $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $family['course']->id,
            'name' => $name,
            'duedate' => $due,
        ]);
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'courseid' => $family['course']->id,
        ]);
        $userid = $child ? (int) $child->id : (int) $family['child']->id;
        $item->update_final_grade($userid, $grade, 'phpunit');
    }

    /**
     * Switches with only text and marks on.
     *
     * @return array
     */
    private function text_only(): array {
        return [
            'app' => 0,
            'email' => 0,
            'text' => 1,
            'mark' => 1,
            'absent' => 0,
            'due' => 0,
            'badge' => 0,
            'message' => 0,
            'weekly' => 0,
        ];
    }

    /**
     * Run queued text sends without printing the gateway status.
     */
    private function run_sms_tasks(): void {
        ob_start();
        try {
            $this->runAdhocTasks(\core_sms\task\send_sms_task::class);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Emails addressed to one person.
     *
     * @param \core\test\phpunit\phpmailer_sink $sink
     * @param string $address
     * @return \stdClass[]
     */
    private function emails_to(\core\test\phpunit\phpmailer_sink $sink, string $address): array {
        $found = [];
        foreach ($sink->get_messages() as $message) {
            if ($message->to === $address) {
                $found[] = $message;
            }
        }
        return $found;
    }

    /**
     * Email bodies joined for assertions.
     *
     * @param \stdClass[] $messages
     * @return string
     */
    private function joined(array $messages): string {
        $text = '';
        foreach ($messages as $message) {
            $text .= $message->subject . "\n" . $message->body . "\n";
        }
        return $text;
    }

    /**
     * The text of every in-app update for one parent.
     *
     * @param int $userid
     * @return string
     */
    private function note_text(int $userid): string {
        global $DB;

        $text = '';
        $notes = $DB->get_records('notifications', [
            'component' => 'local_wwupdates',
            'useridto' => $userid,
        ]);
        foreach ($notes as $note) {
            $text .= $note->fullmessage . "\n";
        }
        return $text;
    }

    /**
     * In-app updates for one parent.
     *
     * @param int $userid
     * @return \stdClass[]
     */
    private function popup_messages(int $userid): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT n.id, n.fullmessage
               FROM {notifications} n
               JOIN {message_popup_notifications} p ON p.notificationid = n.id
              WHERE n.useridto = ? AND n.component = ?",
            [$userid, 'local_wwupdates']
        );
    }

    /**
     * How many times this kind was sent for one parent and child.
     *
     * @param int $parentid
     * @param int $childid
     * @param string $kind
     * @return int
     */
    private function count_kind(int $parentid, int $childid, string $kind): int {
        global $DB;

        return $DB->count_records('local_wwupdates_sent', [
            'parentid' => $parentid,
            'childid' => $childid,
            'kind' => $kind,
        ]);
    }
}
