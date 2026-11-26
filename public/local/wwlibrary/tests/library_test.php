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

namespace local_wwlibrary;

/**
 * Library storage, school separation, and course links.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(library::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(storage::class)]
final class library_test extends \advanced_testcase {
    /**
     * A teacher can store a map link, a world file, and a model. Another school cannot open them.
     */
    public function test_a_school_keeps_its_own_resources(): void {
        global $DB, $OUTPUT;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);

        $map = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'North map',
            'kind' => 'map',
            'externalurl' => 'https://example.com/north',
        ]);
        $this->assertSame('url', $map->source);
        $this->assertSame('https://example.com/north', $map->externalurl);

        $world = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'North world',
            'kind' => 'world',
            'externalurl' => 'https://example.com/world',
        ], $this->mcworld(), 'room.mcworld');
        $this->assertSame('file', $world->source);
        $this->assertSame('room.mcworld', $world->mainfile);
        $this->assertSame('https://example.com/world', $world->externalurl);
        $stored = storage::main($world);
        $this->assertSame('room.mcworld', $stored->get_filename());
        $this->assertSame("PK\x03\x04", substr($stored->get_content(), 0, 4));

        $model = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'North model',
            'kind' => 'model',
        ], $this->glb(), 'room.glb');
        $this->assertSame('file', $model->source);
        $this->assertSame('glTF', substr(storage::main($model)->get_content(), 0, 4));

        $this->setUser($schools->northstudent);
        $this->assertTrue(library::can_view_resource($map));
        $this->assertFalse(library::can_manage_resource($map));
        $page = library::index_data((int) $schools->north->id, '', 0);
        $names = array_column($page['resources'], 'name');
        $this->assertContains('North map', $names);
        $this->assertContains('North world', $names);
        $this->assertContains('North model', $names);
        $this->assertFalse($page['manage']);
        $view = library::viewer($map);
        $this->assertTrue($view['isurl']);
        $this->assertStringContainsString('allow-scripts', $view['sandbox']);
        $this->assertStringNotContainsString('allow-top-navigation', $view['sandbox']);
        $html = $OUTPUT->render_from_template('local_wwlibrary/viewer', $view);
        $this->assertStringContainsString('https://example.com/north', $html);
        $this->assertStringNotContainsString('moodle', strtolower($html));
        $worldview = library::viewer($world);
        $this->assertNotEmpty($worldview['launchurl']);
        $this->assertNotEmpty($worldview['downloadurl']);

        $this->setUser($schools->southstudent);
        $this->assertFalse(library::can_view_resource($map));
        $this->assertFalse(library::can_view_resource($world));
        $this->assertFalse(library::can_view_resource($model));
        $south = library::index_data((int) $schools->south->id, '', 0);
        $this->assertSame([], $south['resources']);
        $this->assertSame(0, library::visible_count($schools->southstudent->id));
        $this->assertSame(3, library::visible_count($schools->northstudent->id));

        $this->setUser($schools->southteacher);
        $this->expectException(\moodle_exception::class);
        library::save($schools->southteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'Stolen',
            'kind' => 'map',
            'externalurl' => 'https://example.com/stolen',
        ]);
    }

    /**
     * A package is stored. A package with a program file, or a path that climbs out, is refused.
     */
    public function test_packages_are_checked_before_they_are_stored(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);

        $good = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'North room',
            'kind' => 'map',
        ], $this->zip([
            'index.html' => '<p id="room">Room</p><script src="app.js"></script>',
            'app.js' => 'document.body.dataset.ready = "1";',
        ]), 'room.zip');
        $this->assertSame('package', $good->source);
        $this->assertSame('index.html', $good->mainfile);
        $files = storage::list_files((int) $good->id);
        $names = array_map(static function(\stored_file $file): string {
            return $file->get_filename();
        }, $files);
        $this->assertContains('index.html', $names);
        $this->assertContains('app.js', $names);
        $this->assertSame('package', library::mode($good));
        $manifest = storage::manifest($good);
        $this->assertCount(2, $manifest);

        $wrapped = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'Wrapped model',
            'kind' => 'model',
        ], $this->zip([
            'pack/index.html' => '<p>Model</p>',
        ]), 'model.zip');
        $this->assertSame('index.html', $wrapped->mainfile);

        try {
            library::save($schools->northteacher->id, [
                'categoryid' => $schools->north->id,
                'name' => 'Bad package',
                'kind' => 'map',
            ], $this->zip([
                'index.html' => '<p>Room</p>',
                'bad.php' => '<?php echo 1;',
            ]), 'bad.zip');
            $this->fail('A program file was stored');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Refused', $e->getMessage());
        }
        $this->assertFalse($DB->record_exists('local_wwlibrary_resource', ['name' => 'Bad package']));

        try {
            library::save($schools->northteacher->id, [
                'categoryid' => $schools->north->id,
                'name' => 'Climbed',
                'kind' => 'map',
            ], $this->rawzip([
                '../index.html' => '<p>Out</p>',
            ]), 'climb.zip');
            $this->fail('A path that climbs out was stored');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Refused', $e->getMessage());
        }

        try {
            library::save($schools->northteacher->id, [
                'categoryid' => $schools->north->id,
                'name' => 'Plain link',
                'kind' => 'map',
                'externalurl' => 'http://example.com/map',
            ]);
            $this->fail('An http link was stored');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Refused', $e->getMessage());
        }

        $this->setUser($schools->southstudent);
        require_once(__DIR__ . '/../lib.php');
        $served = local_wwlibrary_pluginfile(
            null,
            null,
            \context_system::instance(),
            'content',
            [(int) $good->id, 'index.html'],
            false
        );
        $this->assertFalse($served);
    }

    /**
     * A teacher attaches a resource to a course. A student opens it. Another school cannot.
     */
    public function test_a_resource_can_be_attached_inside_a_course(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        $map = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'Course map',
            'kind' => 'map',
            'externalurl' => 'https://example.com/course',
        ]);

        $moduleinfo = library::attach((int) $map->id, (int) $schools->northcourse->id, 0);
        $cm = get_coursemodule_from_id('wwlibrary', $moduleinfo->coursemodule, 0, false, MUST_EXIST);
        $instance = $DB->get_record('wwlibrary', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame((int) $map->id, (int) $instance->resourceid);
        $this->assertSame('Course map', $instance->name);

        $this->setUser($schools->northstudent);
        $context = \context_module::instance($cm->id);
        $this->assertTrue(has_capability('mod/wwlibrary:view', $context));
        $this->assertTrue(library::can_view_resource($map));

        $this->setUser($schools->southteacher);
        $this->assertFalse(library::resource_allowed_in_course((int) $map->id, (int) $schools->southcourse->id));
        $this->expectException(\moodle_exception::class);
        library::attach((int) $map->id, (int) $schools->southcourse->id, 0);
    }

    /**
     * Removing a resource removes its files and the course activity.
     */
    public function test_removing_a_resource_removes_its_files_and_course_link(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        $model = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'Gone model',
            'kind' => 'model',
        ], $this->glb(), 'gone.glb');
        $moduleinfo = library::attach((int) $model->id, (int) $schools->northcourse->id, 0);
        library::remove((int) $model->id, $schools->northteacher->id);

        $this->assertFalse($DB->record_exists('local_wwlibrary_resource', ['id' => $model->id]));
        $this->assertSame([], storage::list_files((int) $model->id));
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $moduleinfo->coursemodule]));
    }

    /**
     * Folders order resources, and deleting a folder leaves the resources.
     */
    public function test_folders_organise_resources_inside_one_school(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        $folder = library::save_folder($schools->northteacher->id, (int) $schools->north->id, 'Shelf');
        $second = library::save_folder($schools->northteacher->id, (int) $schools->north->id, 'Later shelf');
        library::nudge_folder((int) $second->id, -1, $schools->northteacher->id);
        $ordered = array_values($DB->get_records(
            'local_wwlibrary_folder',
            ['categoryid' => $schools->north->id],
            'sortorder ASC, id ASC'
        ));
        $this->assertSame('Later shelf', $ordered[0]->name);

        $first = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'folderid' => $folder->id,
            'name' => 'First',
            'kind' => 'map',
            'externalurl' => 'https://example.com/first',
        ]);
        $later = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'folderid' => $folder->id,
            'name' => 'Second',
            'kind' => 'map',
            'externalurl' => 'https://example.com/second',
        ]);
        library::nudge((int) $later->id, -1, $schools->northteacher->id);
        $rows = array_values($DB->get_records('local_wwlibrary_resource', ['folderid' => $folder->id], 'sortorder ASC, id ASC'));
        $this->assertSame('Second', $rows[0]->name);

        $page = library::index_data((int) $schools->north->id, '', (int) $folder->id);
        $this->assertSame(['Second', 'First'], array_column($page['resources'], 'name'));
        $root = library::index_data((int) $schools->north->id, '', 0);
        $this->assertNotContains('Second', array_column($root['resources'], 'name'));
        $this->assertContains('Later shelf', array_column($root['folders'], 'name'));

        library::remove_folder((int) $folder->id, $schools->northteacher->id);
        $kept = $DB->get_record('local_wwlibrary_resource', ['id' => $first->id]);
        $this->assertSame(0, (int) $kept->folderid);
        $this->assertFalse($DB->record_exists('local_wwlibrary_folder', ['id' => $folder->id]));
    }

    /**
     * A student home, a teacher home, and an admin home each show the library count for that person.
     */
    public function test_homes_show_the_library_count_for_that_person(): void {
        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'Counted',
            'kind' => 'map',
            'externalurl' => 'https://example.com/counted',
        ]);
        $this->setUser($schools->southteacher);
        library::save($schools->southteacher->id, [
            'categoryid' => $schools->south->id,
            'name' => 'South counted',
            'kind' => 'world',
            'externalurl' => 'https://example.com/south',
        ]);

        $this->setUser($schools->northstudent);
        $student = $this->tiles(\local_wolfwhale\home::get_tiles());
        $this->assertSame('Library', $student['library']['word']);
        $this->assertSame('1', $student['library']['fact']);
        $this->assertStringContainsString('/local/wwlibrary/index.php', $student['library']['url']);

        $this->setUser($schools->northteacher);
        $teacher = $this->tiles(\local_wolfwhale\teacher::get_tiles());
        $this->assertSame('1', $teacher['library']['fact']);
        $actions = \local_wolfwhale\teacher::post_actions((int) $schools->northcourse->id);
        $mods = array_column($actions, 'mod');
        $this->assertContains('wwlibrary', $mods);

        $this->setUser($schools->northadmin);
        $admin = $this->tiles(\local_wwadmin\home::tiles());
        $this->assertSame('1', $admin['library']['fact']);
        $this->assertStringContainsString('/local/wwlibrary/index.php', $admin['library']['url']);
    }

    /**
     * A prohibited view capability closes the library. Deleting the author clears the name and leaves the resource.
     */
    public function test_view_can_be_prohibited_and_deletion_clears_the_author(): void {
        global $DB;

        $this->resetAfterTest();
        $schools = $this->schools();
        $this->setUser($schools->northteacher);
        $map = library::save($schools->northteacher->id, [
            'categoryid' => $schools->north->id,
            'name' => 'Kept',
            'kind' => 'map',
            'externalurl' => 'https://example.com/kept',
        ]);

        $role = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $context = \context_course::instance($schools->northcourse->id);
        assign_capability('local/wwlibrary:view', CAP_PROHIBIT, $role, $context->id);
        $context->mark_dirty();
        $this->setUser($schools->northstudent);
        $this->assertFalse(library::can_view_resource($map, $schools->northstudent->id));

        $author = $DB->get_record('user', ['id' => $schools->northteacher->id], '*', MUST_EXIST);
        $list = new \core_privacy\local\request\approved_contextlist(
            $author,
            'local_wwlibrary',
            [\context_coursecat::instance($schools->north->id)->id]
        );
        privacy\provider::delete_data_for_user($list);
        $kept = $DB->get_record('local_wwlibrary_resource', ['id' => $map->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $kept->userid);
        $this->assertSame('Kept', $kept->name);
    }

    /**
     * A non-editing teacher can change the library and cannot add an activity.
     */
    public function test_a_non_editing_teacher_can_change_the_library_but_not_attach(): void {
        $this->resetAfterTest();
        $schools = $this->schools();
        $plain = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($plain->id, $schools->northcourse->id, 'teacher');
        $this->setUser($plain);
        $map = library::save($plain->id, [
            'categoryid' => $schools->north->id,
            'name' => 'Staff map',
            'kind' => 'map',
            'externalurl' => 'https://example.com/staff',
        ]);
        $this->assertSame('Staff map', $map->name);
        $this->expectException(\required_capability_exception::class);
        library::attach((int) $map->id, (int) $schools->northcourse->id, 0);
    }

    /**
     * Two schools, each with a manager, a teacher, and a student.
     *
     * @return \stdClass
     */
    private function schools(): \stdClass {
        global $DB;

        $north = $this->getDataGenerator()->create_category(['name' => 'North School']);
        $south = $this->getDataGenerator()->create_category(['name' => 'South School']);
        $northadmin = $this->getDataGenerator()->create_user(['username' => 'northadmin']);
        $manager = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($manager, $northadmin->id, \context_coursecat::instance($north->id)->id);
        $northcourse = $this->getDataGenerator()->create_course([
            'category' => $north->id,
            'fullname' => 'North room',
        ]);
        $southcourse = $this->getDataGenerator()->create_course([
            'category' => $south->id,
            'fullname' => 'South room',
        ]);
        $northteacher = $this->getDataGenerator()->create_user();
        $southteacher = $this->getDataGenerator()->create_user();
        $northstudent = $this->getDataGenerator()->create_user();
        $southstudent = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($northteacher->id, $northcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($southteacher->id, $southcourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($northstudent->id, $northcourse->id, 'student');
        $this->getDataGenerator()->enrol_user($southstudent->id, $southcourse->id, 'student');

        return (object) [
            'north' => $north,
            'south' => $south,
            'northadmin' => $northadmin,
            'northcourse' => $northcourse,
            'southcourse' => $southcourse,
            'northteacher' => $northteacher,
            'southteacher' => $southteacher,
            'northstudent' => $northstudent,
            'southstudent' => $southstudent,
        ];
    }

    /**
     * @param array $tiles
     * @return array
     */
    private function tiles(array $tiles): array {
        $byid = [];
        foreach ($tiles as $tile) {
            $byid[$tile['id']] = $tile;
        }
        return $byid;
    }

    /**
     * A zip with the entry names left exactly as given.
     *
     * @param array $files
     * @return string
     */
    private function rawzip(array $files): string {
        $path = make_request_directory() . '/raw.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE) === true);
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $zip->close();
        return $path;
    }

    /**
     * A tiny zip whose entries are the given paths.
     *
     * @param array $files
     * @return string
     */
    private function zip(array $files): string {
        $path = make_request_directory() . '/pack.zip';
        $zip = new \zip_archive();
        $this->assertTrue($zip->open($path, \file_archive::CREATE));
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->add_file_from_string($name, $contents));
        }
        $zip->close();
        return $path;
    }

    /**
     * A .mcworld file, which is a zip.
     *
     * @return string
     */
    private function mcworld(): string {
        $zip = $this->zip(['level.txt' => 'room']);
        $path = make_request_directory() . '/room.mcworld';
        $this->assertTrue(copy($zip, $path));
        return $path;
    }

    /**
     * A tiny glTF binary.
     *
     * @return string
     */
    private function glb(): string {
        $json = '{"asset":{"version":"2.0"},"scenes":[{"nodes":[0]}],"nodes":[{"mesh":0}],'
            . '"meshes":[{"primitives":[{"attributes":{"POSITION":0}}]}],'
            . '"buffers":[{"byteLength":36}],"bufferViews":[{"buffer":0,"byteOffset":0,"byteLength":36}],'
            . '"accessors":[{"bufferView":0,"componentType":5126,"count":3,"type":"VEC3","max":[1,1,0],"min":[0,0,0]}]}';
        $pad = (4 - (strlen($json) % 4)) % 4;
        $json .= str_repeat(' ', $pad);
        $bin = pack('g*', 0, 0, 0, 1, 0, 0, 0, 1, 0);
        $jsonlen = strlen($json);
        $binlen = strlen($bin);
        $length = 12 + 8 + $jsonlen + 8 + $binlen;
        $bytes = 'glTF' . pack('VV', 2, $length)
            . pack('V', $jsonlen) . 'JSON' . $json
            . pack('V', $binlen) . "BIN\0" . $bin;
        $path = make_request_directory() . '/room.glb';
        file_put_contents($path, $bytes);
        return $path;
    }
}
