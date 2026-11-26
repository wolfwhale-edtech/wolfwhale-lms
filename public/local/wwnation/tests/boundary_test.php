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

namespace local_wwnation;

use local_wwadmin\exporter;
use local_wwadmin\sovereignty;

/**
 * Cross-Nation access is refused, and a Nation export stays inside that Nation.
 *
 * @package    local_wwnation
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(nation::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sovereignty::class)]
final class boundary_test extends \advanced_testcase {
    /**
     * A teacher, student, or parent cannot open the other Nation.
     */
    public function test_cross_nation_access_is_denied(): void {
        $this->resetAfterTest();
        $schools = $this->schools();

        $this->setUser($schools->northteacher);
        nation::require_course($schools->northteacher->id, $schools->northcourse->id);
        $names = array_column(\local_wolfwhale\teacher::courses(), 'fullname');
        $this->assertContains('North room', $names);
        $this->assertNotContains('South room', $names);
        try {
            nation::require_course($schools->northteacher->id, $schools->southcourse->id);
            $this->fail('A teacher opened the other Nation');
        } catch (\moodle_exception $error) {
            $this->assertSame('denied', $error->errorcode);
        }
        try {
            nation::require_user($schools->northteacher->id, $schools->southstudent->id);
            $this->fail('A teacher opened a person in the other Nation');
        } catch (\moodle_exception $error) {
            $this->assertSame('denied', $error->errorcode);
        }

        $this->setUser($schools->northstudent);
        nation::require_course($schools->northstudent->id, $schools->northcourse->id);
        try {
            nation::require_course($schools->northstudent->id, $schools->southcourse->id);
            $this->fail('A student opened the other Nation');
        } catch (\moodle_exception $error) {
            $this->assertSame('denied', $error->errorcode);
        }
        try {
            nation::require_user($schools->northstudent->id, $schools->southstudent->id);
            $this->fail('A student opened a person in the other Nation');
        } catch (\moodle_exception $error) {
            $this->assertSame('denied', $error->errorcode);
        }

        $this->setAdminUser();
        \local_wolfwhale\parents::install_role();
        \local_wolfwhale\parents::link($schools->northparent->id, $schools->northstudent->id);
        $this->setUser($schools->northteacher);
        try {
            \local_wolfwhale\parents::link($schools->northparent->id, $schools->southstudent->id);
            $this->fail('A teacher linked a parent into the other Nation');
        } catch (\moodle_exception $error) {
            $this->assertSame('cannotview', $error->errorcode);
        }
        $this->setUser($schools->northparent);
        $childids = array_map('intval', array_column(\local_wolfwhale\parents::children(), 'id'));
        $this->assertContains((int) $schools->northstudent->id, $childids);
        $this->assertNotContains((int) $schools->southstudent->id, $childids);
        try {
            nation::require_user($schools->northparent->id, $schools->southstudent->id);
            $this->fail('A parent opened a person in the other Nation');
        } catch (\moodle_exception $error) {
            $this->assertSame('denied', $error->errorcode);
        }
    }

    /**
     * Stored membership is the check. A role without that row is refused.
     */
    public function test_removed_membership_is_denied(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $nationid = nation::sync_root($schools->north->id);
        $this->assertTrue(nation::is_member($schools->northteacher->id, $nationid));
        $DB->delete_records('local_wwnation_member', [
            'nationid' => $nationid,
            'userid' => $schools->northteacher->id,
        ]);
        $this->setUser($schools->northteacher);
        try {
            \local_wwattendance\attendance::students($schools->northcourse->id);
            $this->fail('Attendance was opened without a membership row');
        } catch (\moodle_exception $error) {
            $this->assertSame('denied', $error->errorcode);
        }
    }

    /**
     * A manager role on the whole site is not a Nation administrator.
     */
    public function test_a_system_manager_cannot_read_a_nation(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $manager = $this->getDataGenerator()->create_user(['username' => 'sitemanager']);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($roleid, $manager->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($manager);

        $this->assertNotContains((int) $schools->north->id, \local_wwadmin\scope::managed_root_ids($manager->id));
        try {
            \local_wwadmin\people::list_users($schools->north->id);
            $this->fail('A system manager listed a Nation');
        } catch (\moodle_exception $error) {
            $this->assertSame('denied', $error->errorcode);
        }
        $this->assertFalse(\local_wolfwhale\parents::can_link_child($schools->northstudent->id, $manager->id));
    }

    /**
     * The archive holds one Nation and leaves passwords and secrets out.
     */
    public function test_export_contains_only_this_nation_and_no_secrets(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setAdminUser();
        \local_wolfwhale\parents::install_role();
        \local_wolfwhale\parents::link($schools->northparent->id, $schools->northstudent->id);
        \local_wwupdates\preferences::save($schools->northparent->id, [
            'app' => 1,
            'email' => 0,
            'text' => 0,
            'mark' => 1,
            'absent' => 1,
            'due' => 1,
            'badge' => 1,
            'message' => 1,
            'weekly' => 1,
        ]);
        set_user_preference('local_wwupdates_secret', 'WWSECRET9f3a7c', $schools->northparent->id);
        $hash = 'bcrypt-WWSEC9f3a7c-hash';
        $DB->set_field('user', 'password', $hash, ['id' => $schools->northstudent->id]);
        $DB->set_field('user', 'secret', 'WWSEC9f3a7c', ['id' => $schools->northstudent->id]);

        $this->setUser($schools->northteacher);
        \local_wwattendance\attendance::save($schools->northcourse->id, [
            $schools->northstudent->id => 'present',
        ]);
        $DB->insert_record('local_wwpoints_ledger', (object) [
            'userid' => $schools->northstudent->id,
            'courseid' => $schools->northcourse->id,
            'amount' => 30,
            'reason' => 'North star',
            'eventname' => 'manual',
            'sourcekey' => 'north-star-' . $schools->northstudent->id,
            'awardedby' => $schools->northteacher->id,
            'timecreated' => time(),
        ]);
        $DB->insert_record('local_wwpoints_ledger', (object) [
            'userid' => $schools->southstudent->id,
            'courseid' => $schools->southcourse->id,
            'amount' => 90,
            'reason' => 'South star',
            'eventname' => 'manual',
            'sourcekey' => 'south-star-' . $schools->southstudent->id,
            'awardedby' => $schools->southteacher->id,
            'timecreated' => time(),
        ]);

        $CFG->enablebadges = 1;
        $this->setAdminUser();
        $badges = $this->getDataGenerator()->get_plugin_generator('core_badges');
        $northbadge = $badges->create_badge([
            'name' => 'North badge',
            'type' => BADGE_TYPE_COURSE,
            'courseid' => $schools->northcourse->id,
        ]);
        $southbadge = $badges->create_badge([
            'name' => 'South badge',
            'type' => BADGE_TYPE_COURSE,
            'courseid' => $schools->southcourse->id,
        ]);
        $northbadge->issue($schools->northstudent->id, true);
        $southbadge->issue($schools->southstudent->id, true);

        $this->setUser($schools->northadmin);
        try {
            exporter::build($schools->south->id);
            $this->fail('The other Nation was exported');
        } catch (\required_capability_exception $error) {
            $this->assertNotEmpty($error->errorcode);
        }
        $id = exporter::build($schools->north->id);
        $log = $DB->get_record('local_wwadmin_export', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame(exporter::READY, $log->status);
        $this->assertSame((string) $schools->northadmin->id, (string) $log->userid);

        $file = exporter::require_file($id);
        $path = $file->copy_content_to_temp();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $blob = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $blob .= $zip->getFromIndex($i);
        }
        $users = $zip->getFromName('users.json');
        $courses = $zip->getFromName('courses.json');
        $badgesjson = $zip->getFromName('badges.json');
        $levels = $zip->getFromName('levels.json');
        $preferences = $zip->getFromName('preferences.json');
        $ocap = $zip->getFromName('ocap.json');
        $zip->close();
        @unlink($path);

        $this->assertStringContainsString('northstudent', $users);
        $this->assertStringContainsString('North room', $courses);
        $this->assertStringContainsString('North badge', $badgesjson);
        $this->assertStringContainsString('North star', $blob);
        $this->assertStringContainsString('"level": 2', $levels);
        $this->assertStringContainsString('northparent', $blob);
        $this->assertStringNotContainsString('southstudent', $blob);
        $this->assertStringNotContainsString('South room', $blob);
        $this->assertStringNotContainsString('South badge', $blob);
        $this->assertStringNotContainsString('South star', $blob);
        $this->assertStringNotContainsString((string) $hash, $blob);
        $this->assertStringNotContainsString('WWSECRET9f3a7c', $blob);
        $this->assertStringNotContainsString('WWSEC9f3a7c', $blob);
        $decoded = json_decode($users, true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('password', $decoded[0]);
        $this->assertArrayNotHasKey('secret', $decoded[0]);
        $this->assertStringContainsString('"app": 1', $preferences);
        $this->assertStringContainsString('ownership', $ocap);
        $this->assertStringContainsString('control', $ocap);
        $this->assertStringContainsString('access', $ocap);
        $this->assertStringContainsString('possession', $ocap);
        $this->assertStringContainsString('LAFOIP', $ocap);

        $this->setUser($schools->southadmin);
        $this->expectException(\moodle_exception::class);
        exporter::require_file($id);
    }

    /**
     * The sovereignty counts are this Nation's rows.
     */
    public function test_sovereignty_counts_are_this_nation(): void {
        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        \local_wwattendance\attendance::save($schools->northcourse->id, [
            $schools->northstudent->id => 'present',
        ]);

        $this->setUser($schools->northadmin);
        $view = sovereignty::export($schools->north->id);
        $facts = [];
        foreach ($view['counts'] as $row) {
            $facts[$row['word']] = $row['fact'];
        }
        $this->assertSame('North School', $view['nation']);
        $this->assertSame('1', $facts['Courses']);
        $this->assertGreaterThan(0, (int) $facts['Users']);
        $this->assertSame('1', $facts['Attendance']);
        $this->assertSame('1', $facts['Points']);
        $this->assertSame('Isolated', $facts['Isolation']);
        $this->assertSame(0, $view['shared']);

        $this->getDataGenerator()->enrol_user($schools->northstudent->id, $schools->southcourse->id, 'student');
        nation::sync_root($schools->north->id);
        nation::sync_root($schools->south->id);
        $again = sovereignty::export($schools->north->id);
        $isolation = '';
        foreach ($again['counts'] as $row) {
            if ($row['word'] === 'Isolation') {
                $isolation = $row['fact'];
            }
        }
        $this->assertSame('1', $isolation);

        try {
            sovereignty::export($schools->south->id);
            $this->fail('The other Nation was counted');
        } catch (\required_capability_exception $error) {
            $this->assertNotEmpty($error->errorcode);
        }
    }

    /**
     * Two Nations.
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
        $southstudent = $this->getDataGenerator()->create_user(['username' => 'southstudent']);
        $northparent = $this->getDataGenerator()->create_user(['username' => 'northparent']);
        $this->getDataGenerator()->enrol_user($northteacher->id, $northcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($southteacher->id, $southcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($northstudent->id, $northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($southstudent->id, $southcourse->id, 'student');
        accesslib_clear_all_caches_for_unit_testing();

        return (object) [
            'north' => $north,
            'south' => $south,
            'northadmin' => $northadmin,
            'southadmin' => $southadmin,
            'northteacher' => $northteacher,
            'southteacher' => $southteacher,
            'northstudent' => $northstudent,
            'southstudent' => $southstudent,
            'northparent' => $northparent,
            'northcourse' => $northcourse,
            'southcourse' => $southcourse,
        ];
    }
}
