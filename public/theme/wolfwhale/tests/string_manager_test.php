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

namespace theme_wolfwhale;

/**
 * Tests for the product-name rewrite.
 *
 * @package    theme_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class string_manager_test extends \basic_testcase {
    /**
     * The product name is rewritten, including the all-caps form.
     */
    public function test_rebrand_replaces_the_product_name(): void {
        $this->assertSame('WolfWhale', string_manager::rebrand('Moodle'));
        $this->assertSame('Welcome to WolfWhale', string_manager::rebrand('Welcome to Moodle'));
        $this->assertSame('WOLFWHALE', string_manager::rebrand('MOODLE'));
        $this->assertSame('WolfWhale and WolfWhale', string_manager::rebrand('Moodle and moodle'));
    }

    /**
     * Addresses, placeholders, and the real cookie name stay intact.
     */
    public function test_rebrand_keeps_addresses_placeholders_and_the_cookie_name(): void {
        $url = 'See https://docs.moodle.org/en/Backup_FAQ now';
        $this->assertSame($url, string_manager::rebrand($url));
        $this->assertSame('Visit moodle.org', string_manager::rebrand('Visit moodle.org'));
        $this->assertSame(
            'WolfWhale {$a->moodlerelease}',
            string_manager::rebrand('Moodle {$a->moodlerelease}')
        );
        $this->assertSame('cookie MoodleSession', string_manager::rebrand('cookie MoodleSession'));
    }

    /**
     * The community nickname is rewritten as ordinary words.
     */
    public function test_rebrand_rewrites_moodlers(): void {
        $this->assertSame(
            'other WolfWhale users',
            string_manager::rebrand('other Moodlers')
        );
    }

    /**
     * Capability names, component names, and other identifiers stay as written.
     */
    public function test_rebrand_keeps_identifiers(): void {
        $this->assertSame(
            'By default, moodle/role:assign is required',
            string_manager::rebrand('By default, moodle/role:assign is required')
        );
        $this->assertSame(
            'users with the capability (moodle/course:request) will',
            string_manager::rebrand('users with the capability (moodle/course:request) will')
        );
        $this->assertSame(
            'Enabling this removes the moodle/role:assign requirement in WolfWhale.',
            string_manager::rebrand('Enabling this removes the moodle/role:assign requirement in Moodle.')
        );
        $this->assertSame('mod_moodle', string_manager::rebrand('mod_moodle'));
        $this->assertSame('core_moodle', string_manager::rebrand('core_moodle'));
        $this->assertSame('moodle\\output\\core_renderer', string_manager::rebrand('moodle\\output\\core_renderer'));
        $this->assertSame('WolfWhale.', string_manager::rebrand('Moodle.'));
    }

    /**
     * Text with no product name is returned unchanged.
     */
    public function test_rebrand_leaves_unrelated_text(): void {
        $this->assertSame('Course', string_manager::rebrand('Course'));
        $this->assertSame('', string_manager::rebrand(''));
    }
}
