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

namespace local_wwoffline;

/**
 * The next lesson is the next page, book, or file this student can open.
 *
 * @package    local_wwoffline
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lessons::class)]
final class lessons_test extends \advanced_testcase {
    /**
     * A student is offered the next visible page, book, or file, not another course.
     */
    public function test_next_lesson_follows_what_they_opened(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $this->setAdminUser();

        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $secret = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $secret->id, 'student');

        $this->getDataGenerator()->create_module('url', [
            'course' => $course->id,
            'name' => 'River link',
            'externalurl' => 'https://example.com/river',
        ]);
        $first = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'River start',
        ]);
        $second = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'River next',
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'River hidden',
            'visible' => 0,
        ]);
        $file = $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id,
            'name' => 'River file',
        ]);
        $secretpage = $this->getDataGenerator()->create_module('page', [
            'course' => $secret->id,
            'name' => 'Secret page',
        ]);

        $this->setUser($student);
        $urls = lessons::prefetch_urls($student->id);
        $joined = implode("\n", $urls);
        $this->assert_has_cm($joined, '/mod/page/view.php', (int) $first->cmid);
        $this->assert_lacks_cm($joined, (int) $second->cmid);
        $this->assert_lacks_cm($joined, (int) $secretpage->cmid);
        $this->assertStringNotContainsString('/mod/url/', $joined);
        $this->assertStringNotContainsString('sesskey', $joined);

        $this->view_page($first);
        $urls = lessons::prefetch_urls($student->id);
        $joined = implode("\n", $urls);
        $this->assert_has_cm($joined, '/mod/page/view.php', (int) $second->cmid);
        $this->assert_lacks_cm($joined, (int) $first->cmid);
        $this->assert_lacks_cm($joined, (int) $secretpage->cmid);

        $this->view_page($second);
        $urls = lessons::prefetch_urls($student->id);
        $joined = implode("\n", $urls);
        $this->assert_has_cm($joined, '/mod/resource/view.php', (int) $file->cmid);
        $this->assertStringContainsString('/pluginfile.php/', $joined);
        $this->assertStringContainsString('mod_resource', $joined);
        $this->assertStringNotContainsString('sesskey', $joined);

        $otherurls = implode("\n", lessons::prefetch_urls($other->id));
        $this->assert_has_cm($otherurls, '/mod/page/view.php', (int) $secretpage->cmid);
        $this->assert_lacks_cm($otherurls, (int) $first->cmid);
        $this->assert_lacks_cm($otherurls, (int) $file->cmid);
    }

    /**
     * A book is a lesson.
     */
    public function test_book_is_a_lesson(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'River book',
        ]);

        $this->setUser($student);
        $joined = implode("\n", lessons::prefetch_urls($student->id));
        $this->assert_has_cm($joined, '/mod/book/view.php', (int) $book->cmid);
        $this->assertStringNotContainsString('sesskey', $joined);
    }

    /**
     * The address is for this course module and no longer one.
     *
     * @param string $joined
     * @param string $path
     * @param int $cmid
     */
    private function assert_has_cm(string $joined, string $path, int $cmid): void {
        $this->assertMatchesRegularExpression(
            '#' . preg_quote($path, '#') . '\?id=' . $cmid . '(?:&|$)#m',
            $joined
        );
    }

    /**
     * @param string $joined
     * @param int $cmid
     */
    private function assert_lacks_cm(string $joined, int $cmid): void {
        $this->assertDoesNotMatchRegularExpression('/[?&]id=' . $cmid . '(?:&|$)/m', $joined);
    }

    /**
     * Record that the student opened a page.
     *
     * @param \stdClass $page
     */
    private function view_page(\stdClass $page): void {
        $cm = get_coursemodule_from_id('page', $page->cmid);
        \mod_page\event\course_module_viewed::create([
            'context' => \context_module::instance($cm->id),
            'objectid' => $cm->instance,
        ])->trigger();
        foreach (get_log_manager()->get_readers() as $reader) {
            if (method_exists($reader, 'flush')) {
                $reader->flush();
            }
        }
    }
}
