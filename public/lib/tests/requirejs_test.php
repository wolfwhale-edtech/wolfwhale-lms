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

namespace core;

use core_requirejs;

/**
 * Unit tests for requirejs.
 *
 * @package   core
 * @author    Damyon Wiese <damyon@moodle.com>
 * @copyright 2016 Damyon Wiese
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class requirejs_test extends \advanced_testcase {

    /**
     * Test requirejs loader
     */
    public function test_requirejs(): void {
        global $CFG;

        // Find a core module.
        $result = core_requirejs::find_one_amd_module('core', 'templates');
        $expected = ['core/templates' => $CFG->dirroot . '/lib/amd/build/templates.min.js'];
        $this->assertEquals($expected, $result);

        // Find a subsystem module (none exist yet).
        $result = core_requirejs::find_one_amd_module('core_group', 'doesnotexist');
        $expected = [];
        $this->assertEquals($expected, $result);

        // Find all modules.
        $result = core_requirejs::find_all_amd_modules();
        foreach ($result as $key => $path) {
            // Lets verify the first part of the key is a valid component name and the second part correctly contains "min" or not.
            list($component, $template) = explode('/', $key, 2);
            $dir = \core_component::get_component_directory($component);
            $this->assertNotEmpty($dir);
            // Only "core" is allowed to have no _ in component names.
            if (strpos($component, '_') === false) {
                $this->assertEquals('core', $component);
            }

            $this->assertStringContainsString('.min', $path);
        }

    }

    /**
     * The page bundle follows imports. It does not include every module, or a lazy player.
     */
    public function test_collect_amd_tree_follows_imports_only(): void {
        $first = core_requirejs::collect_amd_tree(['core/first']);
        $this->assertArrayHasKey('core/first', $first);
        $this->assertArrayNotHasKey('core/modal', $first);
        $this->assertLessThan(5, count($first));

        $notification = core_requirejs::collect_amd_tree(['core/notification']);
        $this->assertArrayHasKey('core/notification', $notification);
        $this->assertArrayHasKey('core/modal_events', $notification);
        // A dialogue is loaded when it opens, not with the first script.
        $this->assertArrayNotHasKey('core/modal', $notification);
        $this->assertArrayNotHasKey('media_videojs/video-lazy', $notification);

        $all = core_requirejs::find_all_amd_modules();
        $this->assertLessThan(count($all), count($notification));
    }

    /**
     * The page bundle lives in the shared cache, and another node can rebuild it from the list.
     */
    public function test_page_bundle_is_shared_and_rebuildable(): void {
        global $CFG;

        $bundle = core_requirejs::store_page_bundle(['core/first'], time());
        $this->assertNotNull($bundle);
        $path = core_requirejs::bundle_cache_file((int) time(), $bundle['hash']);
        // store used the revision it was given, not time() again.
        $stored = null;
        foreach (glob($CFG->cachedir . '/requirejs/bundle-*-' . $bundle['hash']) as $candidate) {
            $stored = $candidate;
        }
        $this->assertNotNull($stored);
        $this->assertStringStartsWith($CFG->cachedir, $stored);
        $this->assertFalse(str_starts_with($stored, $CFG->localcachedir));
        $this->assertFileExists($stored . '.list');

        unlink($stored);
        $this->assertTrue(core_requirejs::rebuild_page_bundle(
            (int) preg_replace('/^.*bundle-(\d+)-.*$/', '$1', $stored),
            $bundle['hash'],
        ));
        $this->assertFileExists($stored);
        $this->assertStringContainsString('core/first', file_get_contents($stored));
    }
}
