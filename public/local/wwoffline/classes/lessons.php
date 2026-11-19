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
 * The next page, book, or file in a student's courses.
 *
 * A lesson here is a page, a book, or a file. The separate lesson activity
 * is not in this fork.
 *
 * @package    local_wwoffline
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lessons {
    /** @var string[] Activity types that count as a lesson. */
    public const MODULES = ['page', 'book', 'resource'];

    /**
     * Addresses to fetch ahead for one student: the next lesson in each course.
     *
     * The address never includes a session key.
     *
     * @param int $userid The student.
     * @return string[]
     */
    public static function prefetch_urls(int $userid): array {
        if ($userid <= 0) {
            return [];
        }

        $courses = enrol_get_users_courses($userid, true, 'id', 'id ASC');
        $urls = [];
        foreach ($courses as $course) {
            $ordered = self::visible_lessons($course, $userid);
            if (!$ordered) {
                continue;
            }
            $ids = [];
            foreach ($ordered as $cm) {
                $ids[] = (int) $cm->id;
            }
            $next = self::pick_next($ordered, self::last_viewed((int) $course->id, $userid, $ids));
            if ($next === null) {
                continue;
            }
            foreach (self::urls_for($next) as $url) {
                $urls[] = $url;
                if (count($urls) >= 30) {
                    return $urls;
                }
            }
        }
        return $urls;
    }

    /**
     * Page, book, and file activities this person can see, in course order.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return \cm_info[]
     */
    private static function visible_lessons(\stdClass $course, int $userid): array {
        $modinfo = get_fast_modinfo($course, $userid);
        $ordered = [];
        foreach ($modinfo->get_sections() as $cmids) {
            foreach ($cmids as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!$cm->uservisible || !in_array($cm->modname, self::MODULES, true)) {
                    continue;
                }
                $ordered[] = $cm;
            }
        }
        return $ordered;
    }

    /**
     * The lesson after the one they last opened, or the first when none was opened.
     *
     * @param \cm_info[] $ordered
     * @param int|null $lastid Course-module id of the last view, if any.
     * @return \cm_info|null
     */
    private static function pick_next(array $ordered, ?int $lastid): ?\cm_info {
        if (!$ordered) {
            return null;
        }
        if ($lastid === null) {
            return $ordered[0];
        }
        $seen = false;
        foreach ($ordered as $cm) {
            if ($seen) {
                return $cm;
            }
            if ((int) $cm->id === $lastid) {
                $seen = true;
            }
        }
        return $seen ? null : $ordered[0];
    }

    /**
     * Latest page, book, or file this person opened in one course.
     *
     * @param int $courseid
     * @param int $userid
     * @param int[] $cmids
     * @return int|null Course-module id.
     */
    private static function last_viewed(int $courseid, int $userid, array $cmids): ?int {
        global $DB;

        if (!$cmids || !$DB->get_manager()->table_exists('logstore_standard_log')) {
            return null;
        }

        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $params['courseid'] = $courseid;
        $params['pageview'] = '\mod_page\event\course_module_viewed';
        $params['bookview'] = '\mod_book\event\course_module_viewed';
        $params['fileview'] = '\mod_resource\event\course_module_viewed';
        $sql = "SELECT id, contextinstanceid
                  FROM {logstore_standard_log}
                 WHERE userid = :userid
                   AND courseid = :courseid
                   AND contextinstanceid {$insql}
               AND eventname IN (:pageview, :bookview, :fileview)
          ORDER BY timecreated DESC, id DESC";
        $rows = $DB->get_records_sql($sql, $params, 0, 1);
        if (!$rows) {
            return null;
        }
        $row = reset($rows);
        return (int) $row->contextinstanceid;
    }

    /**
     * Address that shows one lesson without recording that it was opened.
     *
     * @param \cm_info $cm
     * @return \moodle_url
     */
    public static function read_url(\cm_info $cm): \moodle_url {
        return new \moodle_url('/local/wwoffline/lesson.php', ['id' => $cm->id]);
    }

    /**
     * The real activity address. Opening this one records the visit.
     *
     * @param \cm_info $cm
     * @return \moodle_url
     */
    public static function view_url(\cm_info $cm): \moodle_url {
        return new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]);
    }

    /**
     * Lesson text for a person who can already see the activity.
     *
     * Reading this does not mark the activity viewed or complete.
     *
     * @param \cm_info $cm
     * @return string HTML
     */
    public static function readable(\cm_info $cm): string {
        global $CFG, $DB;

        $context = \context_module::instance($cm->id);
        if ($cm->modname === 'page') {
            $page = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
            $content = file_rewrite_pluginfile_urls(
                $page->content,
                'pluginfile.php',
                $context->id,
                'mod_page',
                'content',
                $page->revision
            );
            return format_text($content, $page->contentformat, [
                'noclean' => true,
                'overflowdiv' => true,
                'context' => $context,
            ]);
        }
        if ($cm->modname === 'book') {
            require_once($CFG->dirroot . '/mod/book/locallib.php');
            $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
            $chapters = book_preload_chapters($book);
            $html = '';
            foreach ($chapters as $chapter) {
                if (!empty($chapter->hidden)) {
                    continue;
                }
                $text = file_rewrite_pluginfile_urls(
                    $chapter->content,
                    'pluginfile.php',
                    $context->id,
                    'mod_book',
                    'chapter',
                    $chapter->id
                );
                $html .= format_text($text, $chapter->contentformat, [
                    'noclean' => true,
                    'overflowdiv' => true,
                    'context' => $context,
                ]);
            }
            return $html;
        }
        $links = [];
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', false, 'itemid, filepath, filename', false);
        foreach ($files as $file) {
            $url = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            );
            $links[] = \html_writer::link($url, $file->get_filename());
        }
        return implode('<br>', $links);
    }

    /**
     * The read address and the files that belong to one lesson.
     *
     * @param \cm_info $cm
     * @return string[]
     */
    private static function urls_for(\cm_info $cm): array {
        // This address renders the lesson without recording a visit or completion.
        // The activity view address would do both.
        $urls = [self::read_url($cm)->out(false)];

        $area = $cm->modname === 'book' ? 'chapter' : 'content';
        $context = \context_module::instance($cm->id);
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_' . $cm->modname,
            $area,
            false,
            'itemid, filepath, filename',
            false
        );
        $count = 0;
        foreach ($files as $file) {
            if ($count >= 20) {
                break;
            }
            $urls[] = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out(false);
            $count++;
        }

        $clean = [];
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '' || str_contains($url, 'sesskey')) {
                continue;
            }
            $clean[$url] = $url;
        }
        return array_values($clean);
    }
}
