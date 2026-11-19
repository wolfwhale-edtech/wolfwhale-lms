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

namespace local_wwterms;

/**
 * Tests for school terms.
 *
 * @package    local_wwterms
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(term::class)]
final class term_test extends \advanced_testcase {
    /**
     * A school administrator can store any name and dates, and cannot read another school.
     */
    public function test_terms_stay_inside_one_school(): void {
        global $DB;

        $this->resetAfterTest();
        $north = $this->getDataGenerator()->create_category(['name' => 'North School']);
        $south = $this->getDataGenerator()->create_category(['name' => 'South School']);
        $northadmin = $this->getDataGenerator()->create_user();
        $southadmin = $this->getDataGenerator()->create_user();
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $northadmin->id, \context_coursecat::instance($north->id)->id);
        role_assign($manager, $southadmin->id, \context_coursecat::instance($south->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $start = strtotime('2026-03-02 00:00:00 UTC');
        $end = strtotime('2026-04-09 23:59:59 UTC');
        $this->setUser($northadmin);
        $id = term::create($north->id, 'Harvest', $start, $end);
        $stored = term::list_for($north->id);
        $this->assertCount(1, $stored);
        $term = reset($stored);
        $this->assertSame($id, (int) $term->id);
        $this->assertSame('Harvest', $term->name);
        $this->assertSame($start, (int) $term->timestart);
        $this->assertSame($end, (int) $term->timeend);
        $this->assertSame((int) $northadmin->id, (int) $term->usermodified);

        $this->expectException(\required_capability_exception::class);
        term::list_for($south->id);
    }

    /**
     * A term with the end before the start is refused.
     */
    public function test_a_term_needs_an_end_after_the_start(): void {
        global $DB;

        $this->resetAfterTest();
        $north = $this->getDataGenerator()->create_category();
        $admin = $this->getDataGenerator()->create_user();
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $admin->id, \context_coursecat::instance($north->id)->id);
        $this->setUser($admin);

        $this->expectException(\moodle_exception::class);
        term::create($north->id, 'Backwards', time() + 100, time());
    }

    /**
     * An administrator of another school cannot delete this term.
     */
    public function test_another_school_cannot_delete_a_term(): void {
        global $DB;

        $this->resetAfterTest();
        $north = $this->getDataGenerator()->create_category();
        $south = $this->getDataGenerator()->create_category();
        $northadmin = $this->getDataGenerator()->create_user();
        $southadmin = $this->getDataGenerator()->create_user();
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $northadmin->id, \context_coursecat::instance($north->id)->id);
        role_assign($manager, $southadmin->id, \context_coursecat::instance($south->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($northadmin);
        $id = term::create($north->id, 'Harvest', time(), time() + 86400);
        $this->setUser($southadmin);
        $this->assertFalse(term::delete($id));
        $this->assertTrue($DB->record_exists('local_wwterms', ['id' => $id]));
    }
}
