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

namespace local_wwadmin;

/**
 * Tests that one school cannot see or export another school's data.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(scope::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(people::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(snapshot::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(report::class)]
final class scope_test extends \advanced_testcase {
    /**
     * A category administrator is sent home. A teacher and a student are not.
     */
    public function test_only_an_administrator_is_sent_to_the_admin_home(): void {
        global $CFG;

        $this->resetAfterTest();
        $schools = $this->schools();
        $home = scope::url()->out(false);

        $this->assertTrue(scope::is_admin($schools->northadmin->id));
        $this->assertTrue(scope::should_redirect(new \moodle_url('/my/'), $schools->northadmin->id));
        $this->assertTrue(scope::should_redirect(new \moodle_url('/my/index.php'), $schools->northadmin->id));
        $this->assertFalse(scope::should_redirect(new \moodle_url('/course/view.php'), $schools->northadmin->id));
        $this->assertSame($home, scope::login_wantsurl(null, $schools->northadmin->id));
        $this->assertSame($home, scope::login_wantsurl($CFG->wwwroot . '/my/index.php', $schools->northadmin->id));
        $this->assertSame($home, scope::login_wantsurl($CFG->wwwroot . '/local/wolfwhale/teacher.php', $schools->northadmin->id));
        $course = (new \moodle_url('/course/view.php', ['id' => $schools->northcourse->id]))->out(false);
        $this->assertNull(scope::login_wantsurl($course, $schools->northadmin->id));

        $this->assertFalse(scope::is_admin($schools->northteacher->id));
        $this->assertFalse(scope::is_admin($schools->northstudent->id));
        $this->assertFalse(scope::should_redirect(new \moodle_url('/my/'), $schools->northteacher->id));

        $this->setAdminUser();
        $this->assertTrue(scope::is_admin((int) $GLOBALS['USER']->id));
        $roots = scope::managed_root_ids((int) $GLOBALS['USER']->id);
        $this->assertContains((int) $schools->north->id, $roots);
        $this->assertContains((int) $schools->south->id, $roots);
    }

    /**
     * A school administrator cannot list another school's people or courses.
     */
    public function test_a_school_administrator_cannot_list_another_school(): void {
        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northadmin);

        $names = array_column(people::list_users($schools->north->id), 'username');
        $this->assertContains('northstudent', $names);
        $this->assertContains('northteacher', $names);
        $this->assertNotContains('southstudent', $names);
        $this->assertNotContains('southadmin', $names);

        $courses = array_column(scope::courses(scope::tree_ids($schools->north->id)), 'fullname');
        $this->assertContains('North room', $courses);
        $this->assertNotContains('South room', $courses);

        $this->expectException(\required_capability_exception::class);
        people::list_users($schools->south->id);
    }

    /**
     * A manager of a child category does not see the parent or a sibling.
     */
    public function test_a_child_category_does_not_include_the_parent_or_a_sibling(): void {
        global $DB;

        $this->resetAfterTest();
        $parent = $this->getDataGenerator()->create_category(['name' => 'Nation']);
        $child = $this->getDataGenerator()->create_category(['name' => 'Village', 'parent' => $parent->id]);
        $sibling = $this->getDataGenerator()->create_category(['name' => 'Other village', 'parent' => $parent->id]);
        $parentcourse = $this->getDataGenerator()->create_course(['category' => $parent->id, 'fullname' => 'Nation room']);
        $childcourse = $this->getDataGenerator()->create_course(['category' => $child->id, 'fullname' => 'Village room']);
        $siblingcourse = $this->getDataGenerator()->create_course(['category' => $sibling->id, 'fullname' => 'Other room']);
        $parentstudent = $this->getDataGenerator()->create_user(['username' => 'nationstudent']);
        $childstudent = $this->getDataGenerator()->create_user(['username' => 'villagestudent']);
        $siblingstudent = $this->getDataGenerator()->create_user(['username' => 'otherstudent']);
        $this->getDataGenerator()->enrol_user($parentstudent->id, $parentcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($childstudent->id, $childcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($siblingstudent->id, $siblingcourse->id, 'student');

        $admin = $this->getDataGenerator()->create_user();
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $admin->id, \context_coursecat::instance($child->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($admin);

        $this->assertSame([(int) $child->id], scope::managed_root_ids($admin->id));
        $courses = array_column(scope::courses(scope::visible_ids()), 'fullname');
        $this->assertSame(['Village room'], array_values($courses));
        $names = array_column(people::list_users($child->id), 'username');
        $this->assertContains('villagestudent', $names);
        $this->assertNotContains('nationstudent', $names);
        $this->assertNotContains('otherstudent', $names);
    }

    /**
     * Adding, uploading, suspending, and removing stay inside the school.
     */
    public function test_people_changes_cannot_cross_schools(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northadmin);

        $added = people::add(
            $schools->north->id,
            'newnorth',
            'New',
            'North',
            'newnorth@example.com',
            'Wolfwhale-2026!',
            $schools->northcourse->id,
            'student'
        );
        $this->assertTrue(is_enrolled(\context_course::instance($schools->northcourse->id), $added));
        $this->assertFalse(is_enrolled(\context_course::instance($schools->southcourse->id), $added));

        $csv = "username,firstname,lastname,email,password,course,role\n";
        $csv .= "northcsv,North,Csv,northcsv@example.com,Wolfwhale-2026!,northroom,student\n";
        $csv .= "southcsv,South,Csv,southcsv@example.com,Wolfwhale-2026!,southroom,student\n";
        $result = people::import_csv($schools->north->id, $csv);
        $this->assertSame(1, $result->created);
        $this->assertCount(1, $result->errors);
        $this->assertTrue($DB->record_exists('user', ['username' => 'northcsv', 'deleted' => 0]));
        $this->assertFalse($DB->record_exists('user', ['username' => 'southcsv']));

        $shared = $this->getDataGenerator()->create_user(['username' => 'shared']);
        $this->getDataGenerator()->enrol_user($shared->id, $schools->northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($shared->id, $schools->southcourse->id, 'student');
        try {
            people::suspend($schools->north->id, $shared->id);
            $this->fail('A person in two schools was suspended');
        } catch (\moodle_exception $error) {
            $this->assertSame('outside', $error->errorcode);
        }
        $this->assertSame('0', $DB->get_field('user', 'suspended', ['id' => $shared->id]));

        people::remove($schools->north->id, $shared->id);
        $this->assertSame('0', $DB->get_field('user', 'deleted', ['id' => $shared->id]));
        $this->assertFalse(is_enrolled(\context_course::instance($schools->northcourse->id), $shared->id));
        $this->assertTrue(is_enrolled(\context_course::instance($schools->southcourse->id), $shared->id));

        $only = $DB->get_record('user', ['username' => 'northcsv']);
        people::suspend($schools->north->id, (int) $only->id);
        $this->assertSame('1', $DB->get_field('user', 'suspended', ['id' => $only->id]));

        $removable = $DB->get_record('user', ['username' => 'newnorth']);
        people::remove($schools->north->id, (int) $removable->id);
        $this->assertSame('1', $DB->get_field('user', 'deleted', ['id' => $removable->id]));
    }

    /**
     * The snapshot uses this school's attendance and overdue work only.
     */
    public function test_the_snapshot_uses_this_school_only(): void {
        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        \local_wwattendance\attendance::save($schools->northcourse->id, [
            $schools->northstudent->id => 'present',
            $schools->northother->id => 'absent',
        ]);
        $this->setUser($schools->southteacher);
        \local_wwattendance\attendance::save($schools->southcourse->id, [
            $schools->southstudent->id => 'absent',
        ]);

        $this->setUser($schools->northadmin);
        $counts = snapshot::for_category($schools->north->id);
        $this->assertSame(2, $counts->students);
        $this->assertSame(1, $counts->teachers);
        $this->assertSame(1, $counts->courses);
        $this->assertSame(50.0, $counts->attendance);
        $this->assertSame('50%', snapshot::format_rate($counts->attendance));
        $this->assertSame(1, $counts->overdue);

        $this->expectException(\required_capability_exception::class);
        snapshot::for_category($schools->south->id);
    }

    /**
     * The summary file names only this school's rows.
     */
    public function test_the_report_names_only_this_school(): void {
        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northadmin);
        $start = time() - 7 * DAYSECS;
        $end = time() + 7 * DAYSECS;
        \local_wwterms\term::create($schools->north->id, 'Harvest', $start, $end);

        $rows = report::rows();
        $this->assertCount(1, $rows);
        $this->assertSame('North School', $rows[0][0]);
        $this->assertSame('Harvest', $rows[0][1]);
        $this->assertSame('2', $rows[0][4]);
        $this->assertSame('1', $rows[0][5]);
        $flat = implode(' ', $rows[0]);
        $this->assertStringNotContainsString('South', $flat);

        $future = time() + 30 * DAYSECS;
        \local_wwterms\term::create($schools->north->id, 'Later', $future, $future + DAYSECS);
        $later = report::rows()[1];
        $this->assertSame('Later', $later[1]);
        $this->assertSame('0', $later[8]);
    }

    /**
     * An export contains one school, is logged, and is refused for the other school.
     */
    public function test_an_export_cannot_include_another_school(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        \local_wwattendance\attendance::save($schools->northcourse->id, [
            $schools->northstudent->id => 'present',
        ]);

        $this->setUser($schools->northadmin);
        try {
            exporter::request($schools->south->id);
            $this->fail('The other school was exported');
        } catch (\required_capability_exception $error) {
            $this->assertNotEmpty($error->errorcode);
        }

        $id = exporter::request($schools->north->id);
        $tasks = \core\task\manager::get_adhoc_tasks(\local_wwadmin\task\export_task::class);
        $this->assertNotEmpty($tasks);
        reset($tasks)->execute();

        $log = $DB->get_record('local_wwadmin_export', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame((string) $schools->northadmin->id, (string) $log->userid);
        $this->assertSame((string) $schools->north->id, (string) $log->categoryid);
        $this->assertGreaterThan(0, (int) $log->timecreated);
        $this->assertSame(exporter::READY, $log->status);

        $file = exporter::require_file($id);
        $path = $file->copy_content_to_temp();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $users = $zip->getFromName('users.csv');
        $usersjson = $zip->getFromName('users.json');
        $enrolments = $zip->getFromName('enrolments.csv');
        $attendance = $zip->getFromName('attendance.csv');
        $submissions = $zip->getFromName('submissions.csv');
        $zip->close();
        $this->assertStringContainsString('northstudent', $users);
        $this->assertStringContainsString('northstudent', $usersjson);
        $this->assertStringNotContainsString('southstudent', $users);
        $this->assertStringNotContainsString('southstudent', $usersjson);
        $this->assertStringNotContainsString('southroom', $enrolments);
        $this->assertStringContainsString((string) $schools->northcourse->id, $attendance);
        $this->assertStringNotContainsString((string) $schools->southcourse->id, $attendance);
        $this->assertStringContainsString('submitted', $submissions);
        $this->assertStringNotContainsString('South essay', $submissions);
        @unlink($path);

        $this->setUser($schools->southadmin);
        $this->assertSame([], exporter::list_for_current());
        $this->setUser($schools->northadmin);
        $listed = exporter::list_for_current();
        $this->assertArrayHasKey($id, $listed);

        $forged = $DB->insert_record('local_wwadmin_export', (object) [
            'userid' => $schools->northadmin->id,
            'categoryid' => $schools->south->id,
            'status' => exporter::QUEUED,
            'detail' => '',
            'timecreated' => time(),
            'timecompleted' => 0,
        ]);
        exporter::run((int) $forged);
        $failed = $DB->get_record('local_wwadmin_export', ['id' => $forged], '*', MUST_EXIST);
        $this->assertSame(exporter::FAILED, $failed->status);
        $this->assertNull(exporter::stored_file($failed));
    }

    /**
     * Seat numbers are per school. A school administrator cannot set them or read another school.
     */
    public function test_seats_are_per_school_and_have_no_price(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setAdminUser();
        seats::set($schools->north->id, 40);
        $record = $DB->get_record('local_wwadmin_seat', ['categoryid' => $schools->north->id], '*', MUST_EXIST);
        $this->assertSame('40', (string) $record->seats);
        $this->assertObjectNotHasProperty('price', $record);

        $this->setUser($schools->northadmin);
        $this->assertSame(40, seats::count($schools->north->id));
        $active = seats::active_count($schools->north->id);
        $this->assertGreaterThan(0, $active);
        $this->assertSame(seats::fact($active, 40), $active . ' / 40');

        try {
            seats::set($schools->north->id, 5);
            $this->fail('A school administrator changed the seat count');
        } catch (\moodle_exception $error) {
            $this->assertSame('nopermissions', $error->errorcode);
        }
        $this->expectException(\required_capability_exception::class);
        seats::count($schools->south->id);
    }

    /**
     * Prohibiting the export capability stops the archive.
     */
    public function test_export_follows_the_capability(): void {
        $this->resetAfterTest();
        $schools = $this->schools();
        $roleid = $GLOBALS['DB']->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $context = \context_coursecat::instance($schools->north->id);
        assign_capability('local/wwadmin:export', CAP_PROHIBIT, $roleid, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($schools->northadmin);

        $this->expectException(\required_capability_exception::class);
        exporter::request($schools->north->id);
    }

    /**
     * Two schools, each with an administrator, a teacher, and students.
     *
     * North has one submitted assignment, so one piece of work is still overdue.
     *
     * @return \stdClass
     */
    private function schools(): \stdClass {
        global $DB;

        $north = $this->getDataGenerator()->create_category(['name' => 'North School']);
        $south = $this->getDataGenerator()->create_category(['name' => 'South School']);
        $northadmin = $this->getDataGenerator()->create_user(['username' => 'northadmin']);
        $southadmin = $this->getDataGenerator()->create_user(['username' => 'southadmin']);
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $northadmin->id, \context_coursecat::instance($north->id)->id);
        role_assign($manager, $southadmin->id, \context_coursecat::instance($south->id)->id);

        $northcourse = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'fullname' => 'North room',
            'shortname' => 'northroom',
        ]);
        $southcourse = $this->getDataGenerator()->create_course([
            'category' => $south->id,
            'fullname' => 'South room',
            'shortname' => 'southroom',
        ]);
        $northteacher = $this->getDataGenerator()->create_user(['username' => 'northteacher']);
        $southteacher = $this->getDataGenerator()->create_user(['username' => 'southteacher']);
        $northstudent = $this->getDataGenerator()->create_user(['username' => 'northstudent']);
        $northother = $this->getDataGenerator()->create_user(['username' => 'northother']);
        $southstudent = $this->getDataGenerator()->create_user(['username' => 'southstudent']);
        $this->getDataGenerator()->enrol_user($northteacher->id, $northcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($southteacher->id, $southcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($northstudent->id, $northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($northother->id, $northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($southstudent->id, $southcourse->id, 'student');

        $northassign = $this->getDataGenerator()->create_module('assign', [
            'course' => $northcourse->id,
            'name' => 'North essay',
            'duedate' => time() - DAYSECS,
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $southassign = $this->getDataGenerator()->create_module('assign', [
            'course' => $southcourse->id,
            'name' => 'South essay',
            'duedate' => time() - DAYSECS,
            'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $northother->id,
            'cmid' => $northassign->cmid,
            'status' => 'submitted',
            'onlinetext' => 'Done',
        ]);
        accesslib_clear_all_caches_for_unit_testing();

        return (object) [
            'north' => $north,
            'south' => $south,
            'northadmin' => $northadmin,
            'southadmin' => $southadmin,
            'northteacher' => $northteacher,
            'southteacher' => $southteacher,
            'northstudent' => $northstudent,
            'northother' => $northother,
            'southstudent' => $southstudent,
            'northcourse' => $northcourse,
            'southcourse' => $southcourse,
            'northassign' => $northassign,
            'southassign' => $southassign,
        ];
    }
}
