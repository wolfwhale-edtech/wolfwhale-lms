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
 * School library of maps, worlds, and models.
 *
 * A school is the top-level course category. A resource belongs to one school.
 * A teacher of a course in that school, or a manager of a category in that
 * school, can change it. A student enrolled in a course in that school can
 * open it. Another school cannot.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library {
    /** @var string[] Resource kinds. */
    public const KINDS = ['map', 'world', 'model'];

    /**
     * One resource, or null.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public static function get(int $id): ?\stdClass {
        global $DB;

        if ($id <= 0) {
            return null;
        }
        $record = $DB->get_record('local_wwlibrary_resource', ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Top-level category that contains this category.
     *
     * @param int $categoryid
     * @return int
     */
    public static function school_of_category(int $categoryid): int {
        global $DB;

        $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path', IGNORE_MISSING);
        if (!$cat) {
            return 0;
        }
        $parts = array_values(array_filter(explode('/', $cat->path), 'strlen'));
        return $parts ? (int) $parts[0] : 0;
    }

    /**
     * Top-level category that contains this course.
     *
     * @param int $courseid
     * @return int
     */
    public static function school_of_course(int $courseid): int {
        if ($courseid <= 0 || $courseid === (int) SITEID) {
            return 0;
        }
        $course = get_course($courseid);
        return self::school_of_category((int) $course->category);
    }

    /**
     * A category and every category under it.
     *
     * @param int $categoryid
     * @return int[]
     */
    public static function tree_ids(int $categoryid): array {
        global $DB;

        $cat = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path', IGNORE_MISSING);
        if (!$cat) {
            return [];
        }
        $ids = [];
        $prefix = $cat->path . '/';
        foreach ($DB->get_records('course_categories', null, '', 'id, path') as $row) {
            if ((int) $row->id === $categoryid || str_starts_with($row->path, $prefix)) {
                $ids[] = (int) $row->id;
            }
        }
        return $ids;
    }

    /**
     * Every top-level category.
     *
     * @return int[]
     */
    public static function top_school_ids(): array {
        global $DB;

        $ids = $DB->get_fieldset_select('course_categories', 'id', 'parent = 0', []);
        return array_map('intval', $ids);
    }

    /**
     * Schools whose library this person may open.
     *
     * @param int $userid
     * @return int[]
     */
    public static function viewable_school_ids(int $userid): array {
        return self::school_ids_for($userid, false);
    }

    /**
     * Schools whose library this person may change.
     *
     * @param int $userid
     * @return int[]
     */
    public static function manageable_school_ids(int $userid): array {
        return self::school_ids_for($userid, true);
    }

    /**
     * True when this person may open this school's library.
     *
     * @param int $userid
     * @param int $schoolid Top-level category.
     * @return bool
     */
    public static function can_view_school(int $userid, int $schoolid): bool {
        if (self::can_manage_school($userid, $schoolid)) {
            return true;
        }
        if ($userid <= 0 || isguestuser($userid) || !self::is_top($schoolid)) {
            return false;
        }
        foreach (self::courses_in($schoolid) as $courseid) {
            $context = \context_course::instance($courseid);
            // Active enrolment. A suspended enrolment does not open the library,
            // even when the role assignment is still there.
            if (is_enrolled($context, $userid, 'local/wwlibrary:view', true)) {
                return true;
            }
        }
        foreach (self::tree_ids($schoolid) as $categoryid) {
            $context = \context_coursecat::instance($categoryid);
            if (has_capability('local/wwlibrary:view', $context, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when this person may change this school's library.
     *
     * @param int $userid
     * @param int $schoolid Top-level category.
     * @return bool
     */
    public static function can_manage_school(int $userid, int $schoolid): bool {
        if ($userid <= 0 || isguestuser($userid) || !self::is_top($schoolid)) {
            return false;
        }
        if (is_siteadmin($userid)) {
            return true;
        }
        foreach (self::tree_ids($schoolid) as $categoryid) {
            $context = \context_coursecat::instance($categoryid);
            if (has_capability('local/wwlibrary:manage', $context, $userid)) {
                return true;
            }
        }
        foreach (self::courses_in($schoolid) as $courseid) {
            $context = \context_course::instance($courseid);
            if (has_capability('local/wwlibrary:manage', $context, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when this person may open this resource.
     *
     * @param \stdClass $resource
     * @param int|null $userid
     * @return bool
     */
    public static function can_view_resource(\stdClass $resource, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        return self::can_view_school($userid, (int) $resource->categoryid);
    }

    /**
     * True when this person may change this resource.
     *
     * @param \stdClass $resource
     * @param int|null $userid
     * @return bool
     */
    public static function can_manage_resource(\stdClass $resource, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        return self::can_manage_school($userid, (int) $resource->categoryid);
    }

    /**
     * How many resources this person can open.
     *
     * @param int|null $userid
     * @return int
     */
    public static function visible_count(?int $userid = null): int {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $schools = self::viewable_school_ids($userid);
        if (!$schools) {
            return 0;
        }
        [$sql, $params] = $DB->get_in_or_equal($schools, SQL_PARAMS_NAMED);
        return $DB->count_records_select('local_wwlibrary_resource', "categoryid {$sql}", $params);
    }

    /**
     * Save a resource. A file path replaces the stored files.
     *
     * @param int $userid
     * @param array $data id, categoryid, folderid, name, kind, externalurl
     * @param string|null $path Upload path, when a file was sent.
     * @param string|null $filename Original file name.
     * @return \stdClass
     */
    public static function save(int $userid, array $data, ?string $path = null, ?string $filename = null): \stdClass {
        global $DB;

        $categoryid = (int) ($data['categoryid'] ?? 0);
        if (!self::can_manage_school($userid, $categoryid)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        $name = trim(clean_param((string) ($data['name'] ?? ''), PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        $folderid = (int) ($data['folderid'] ?? 0);
        if ($folderid) {
            $folder = $DB->get_record('local_wwlibrary_folder', ['id' => $folderid, 'categoryid' => $categoryid]);
            if (!$folder) {
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
        }
        $url = self::https_url((string) ($data['externalurl'] ?? ''));
        if ($url === '') {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        if ($path !== null) {
            // Refuse a bad upload before a row is written. A test database
            // already has a transaction open, so a later rollback would not
            // hide the row from the rest of the request.
            storage::validate($kind, $path, (string) $filename);
        }

        $existing = null;
        $id = (int) ($data['id'] ?? 0);
        if ($id) {
            $existing = $DB->get_record('local_wwlibrary_resource', ['id' => $id]);
            if (!$existing || (int) $existing->categoryid !== $categoryid || !self::can_manage_resource($existing, $userid)) {
                throw new \moodle_exception('cannotview', 'local_wwlibrary');
            }
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            if (!$existing) {
                $record = (object) [
                    'categoryid' => $categoryid,
                    'folderid' => 0,
                    'name' => $name,
                    'kind' => $kind,
                    'source' => 'url',
                    'externalurl' => '',
                    'mainfile' => '',
                    'sortorder' => 0,
                    'userid' => $userid,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ];
                $record->id = $DB->insert_record('local_wwlibrary_resource', $record);
            } else {
                $record = $existing;
            }

            $stored = self::resolve_source($record, $existing, $kind, $url, $path, $filename, $userid);
            $record->name = $name;
            $record->kind = $kind;
            $record->folderid = $folderid;
            $record->source = $stored['source'];
            $record->mainfile = $stored['mainfile'];
            $record->externalurl = $stored['externalurl'];
            $record->timemodified = time();
            if (!$existing || (int) $existing->folderid !== $folderid) {
                $record->sortorder = self::next_sort('local_wwlibrary_resource', $categoryid, $folderid);
            }
            $DB->update_record('local_wwlibrary_resource', $record);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $DB->get_record('local_wwlibrary_resource', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Remove a resource, its files, and course links this person can change.
     *
     * A course link in a course this person cannot change is left in place.
     * That lesson then has nothing to open.
     *
     * @param int $id
     * @param int|null $userid
     */
    public static function remove(int $id, ?int $userid = null): void {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $resource = self::get($id);
        if (!$resource || !self::can_manage_resource($resource, $userid)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        if ($DB->get_manager()->table_exists('wwlibrary')) {
            $links = $DB->get_records('wwlibrary', ['resourceid' => $id]);
            foreach ($links as $link) {
                $cm = get_coursemodule_from_instance('wwlibrary', $link->id, $link->course, false, IGNORE_MISSING);
                if (!$cm) {
                    $DB->delete_records('wwlibrary', ['id' => $link->id]);
                    continue;
                }
                $context = \context_course::instance((int) $link->course);
                if (has_capability('moodle/course:manageactivities', $context, $userid)) {
                    \core_courseformat\formatactions::cm((int) $link->course)->delete((int) $cm->id);
                }
            }
        }
        storage::clear($id);
        $DB->delete_records('local_wwlibrary_resource', ['id' => $id]);
    }

    /**
     * Swap a resource with the neighbour in its folder.
     *
     * @param int $id
     * @param int $direction -1 earlier, 1 later.
     * @param int|null $userid
     */
    public static function nudge(int $id, int $direction, ?int $userid = null): void {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        $resource = self::get($id);
        if (!$resource || !self::can_manage_resource($resource, $userid)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        self::swap('local_wwlibrary_resource', $resource, $direction, [
            'categoryid' => (int) $resource->categoryid,
            'folderid' => (int) $resource->folderid,
        ]);
    }

    /**
     * Save a folder.
     *
     * @param int $userid
     * @param int $categoryid
     * @param string $name
     * @param int $id Existing folder, or 0.
     * @return \stdClass
     */
    public static function save_folder(int $userid, int $categoryid, string $name, int $id = 0): \stdClass {
        global $DB;

        if (!self::can_manage_school($userid, $categoryid)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        $name = trim(clean_param($name, PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 255) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        if ($id) {
            $folder = $DB->get_record('local_wwlibrary_folder', ['id' => $id, 'categoryid' => $categoryid]);
            if (!$folder) {
                throw new \moodle_exception('cannotview', 'local_wwlibrary');
            }
            $folder->name = $name;
            $folder->timemodified = time();
            $DB->update_record('local_wwlibrary_folder', $folder);
            return $folder;
        }
        $folder = (object) [
            'categoryid' => $categoryid,
            'name' => $name,
            'sortorder' => self::next_sort('local_wwlibrary_folder', $categoryid, null),
            'userid' => $userid,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $folder->id = $DB->insert_record('local_wwlibrary_folder', $folder);
        return $folder;
    }

    /**
     * Remove a folder. Its resources stay in the library, outside a folder.
     *
     * @param int $id
     * @param int|null $userid
     */
    public static function remove_folder(int $id, ?int $userid = null): void {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $folder = $DB->get_record('local_wwlibrary_folder', ['id' => $id]);
        if (!$folder || !self::can_manage_school($userid, (int) $folder->categoryid)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        $DB->set_field('local_wwlibrary_resource', 'folderid', 0, ['folderid' => $id]);
        $DB->delete_records('local_wwlibrary_folder', ['id' => $id]);
    }

    /**
     * Swap a folder with its neighbour.
     *
     * @param int $id
     * @param int $direction
     * @param int|null $userid
     */
    public static function nudge_folder(int $id, int $direction, ?int $userid = null): void {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        $folder = $DB->get_record('local_wwlibrary_folder', ['id' => $id]);
        if (!$folder || !self::can_manage_school($userid, (int) $folder->categoryid)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        self::swap('local_wwlibrary_folder', $folder, $direction, [
            'categoryid' => (int) $folder->categoryid,
        ]);
    }

    /**
     * True when this resource may be attached to this course.
     *
     * The course and the resource must be in the same school, and the
     * logged-in person must be allowed to view the resource.
     *
     * @param int $resourceid
     * @param int $courseid
     * @param int|null $userid
     * @return bool
     */
    public static function resource_allowed_in_course(int $resourceid, int $courseid, ?int $userid = null): bool {
        global $USER;

        $userid = $userid ?? (int) $USER->id;
        $resource = self::get($resourceid);
        if (!$resource || !self::can_view_resource($resource, $userid)) {
            return false;
        }
        return self::school_of_course($courseid) === (int) $resource->categoryid;
    }

    /**
     * Add the resource to a course as a real activity.
     *
     * @param int $resourceid
     * @param int $courseid
     * @param int $sectionnum
     * @return \stdClass Module info, including coursemodule.
     */
    public static function attach(int $resourceid, int $courseid, int $sectionnum): \stdClass {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/course/modlib.php');

        $resource = self::get($resourceid);
        if (!$resource || !self::resource_allowed_in_course($resourceid, $courseid, (int) $USER->id)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        if (!self::can_manage_resource($resource)) {
            throw new \moodle_exception('cannotview', 'local_wwlibrary');
        }
        $course = get_course($courseid);
        $context = \context_course::instance($courseid);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('mod/wwlibrary:addinstance', $context);

        [, , , , $info] = prepare_new_moduleinfo_data($course, 'wwlibrary', $sectionnum);
        $info->name = $resource->name;
        $info->resourceid = (int) $resource->id;
        $info->intro = '';
        $info->introformat = FORMAT_HTML;
        return add_moduleinfo($info, $course);
    }

    /**
     * Courses in this school where the logged-in person can attach a resource.
     *
     * @param int $schoolid
     * @return \stdClass[]
     */
    public static function attachable_courses(int $schoolid): array {
        $courses = [];
        foreach (self::courses_in($schoolid) as $courseid) {
            $context = \context_course::instance($courseid);
            if (has_capability('moodle/course:manageactivities', $context)
                    && has_capability('mod/wwlibrary:addinstance', $context)) {
                $courses[] = get_course($courseid);
            }
        }
        return $courses;
    }

    /**
     * Resources in the same school as a course.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function resources_for_course(int $courseid): array {
        global $DB, $USER;

        $school = self::school_of_course($courseid);
        if (!$school || !self::can_view_school((int) $USER->id, $school)) {
            return [];
        }
        return array_values($DB->get_records('local_wwlibrary_resource', ['categoryid' => $school], 'name ASC, id ASC'));
    }

    /**
     * An https address, null when empty, or an empty string when it is refused.
     *
     * @param string $url
     * @return string|null
     */
    public static function https_url(string $url): ?string {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#\Ahttps://#i', $url) || preg_match('/[\s\x00-\x1f\\\\]/', $url)) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
            return '';
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return '';
        }
        return $url;
    }

    /**
     * Sandbox tokens for an embedded https page.
     *
     * A page on another site keeps its own origin so it can run. A page on
     * this site does not, so it cannot read this site.
     *
     * @param string $url
     * @return string
     */
    public static function sandbox_for(string $url): string {
        global $CFG;

        $tokens = 'allow-scripts allow-popups allow-forms';
        $host = parse_url($url, PHP_URL_HOST);
        $site = parse_url($CFG->wwwroot, PHP_URL_HOST);
        if (is_string($host) && is_string($site) && strcasecmp($host, $site) !== 0) {
            $tokens .= ' allow-same-origin';
        }
        return $tokens;
    }

    /**
     * What the viewer should draw for one resource.
     *
     * @param \stdClass $resource
     * @return array
     */
    public static function viewer(\stdClass $resource): array {
        $mode = self::mode($resource);
        $data = [
            'name' => self::plain($resource->name),
            'showtitle' => true,
            'isurl' => $mode === 'url',
            'ismodel' => $mode === 'model',
            'ispackage' => $mode === 'package',
            'isworld' => $mode === 'world',
            'isnone' => $mode === 'none',
            'none' => get_string('none', 'local_wwlibrary'),
            'launch' => get_string('launch', 'local_wwlibrary'),
            'download' => get_string('download', 'local_wwlibrary'),
        ];
        if ($mode === 'url') {
            $data['frameurl'] = $resource->externalurl;
            $data['sandbox'] = self::sandbox_for($resource->externalurl);
        }
        if ($mode === 'model') {
            $file = storage::main($resource);
            $data['modelurl'] = storage::url((int) $resource->id, $file->get_filepath(), $file->get_filename())->out(false);
            $data['modelscript'] = (new \moodle_url('/local/wwlibrary/js/vendor/model-viewer.min.js'))->out(false);
        }
        if ($mode === 'package') {
            $data['manifest'] = json_encode(storage::manifest($resource));
            $data['viewerscript'] = (new \moodle_url('/local/wwlibrary/js/viewer.js'))->out(false);
        }
        if ($mode === 'world') {
            $download = '';
            if ($resource->source === 'file' && $resource->mainfile !== '') {
                $file = storage::main($resource);
                $download = storage::url(
                    (int) $resource->id,
                    $file->get_filepath(),
                    $file->get_filename(),
                    true
                )->out(false);
            }
            if ($resource->externalurl !== '') {
                $data['launchurl'] = $resource->externalurl;
                $data['externallaunch'] = true;
                if ($download !== '') {
                    $data['downloadurl'] = $download;
                }
            } else if ($download !== '') {
                $data['launchurl'] = $download;
            }
        }
        return $data;
    }

    /**
     * Page data for the library tiles.
     *
     * @param int $schoolid
     * @param string $kind map, world, model, or empty.
     * @param int $folderid
     * @return array
     */
    public static function index_data(int $schoolid, string $kind, int $folderid): array {
        global $DB, $USER;

        $userid = (int) $USER->id;
        $schools = self::viewable_school_ids($userid);
        if ($schoolid && !in_array($schoolid, $schools, true)) {
            $schoolid = 0;
        }
        if (!$schoolid && $schools) {
            $schoolid = (int) $schools[0];
        }
        if (!in_array($kind, self::KINDS, true)) {
            $kind = '';
        }
        $manage = $schoolid && self::can_manage_school($userid, $schoolid);
        if ($folderid && $schoolid) {
            $folder = $DB->get_record('local_wwlibrary_folder', ['id' => $folderid, 'categoryid' => $schoolid]);
            if (!$folder || $kind !== '') {
                $folderid = 0;
            }
        } else {
            $folderid = 0;
        }

        $counts = ['map' => 0, 'world' => 0, 'model' => 0];
        if ($schoolid) {
            $rows = $DB->get_records_sql(
                "SELECT kind, COUNT(1) AS n
                   FROM {local_wwlibrary_resource}
                  WHERE categoryid = :categoryid
               GROUP BY kind",
                ['categoryid' => $schoolid]
            );
            foreach ($rows as $row) {
                if (isset($counts[$row->kind])) {
                    $counts[$row->kind] = (int) $row->n;
                }
            }
        }

        $kinds = [];
        $speech = [];
        foreach (['map' => 'maps', 'world' => 'worlds', 'model' => 'models'] as $value => $stringkey) {
            $word = get_string($stringkey, 'local_wwlibrary');
            $fact = $counts[$value] > 0 ? (string) $counts[$value] : get_string('none', 'local_wwlibrary');
            $params = ['school' => $schoolid];
            if ($kind !== $value) {
                $params['kind'] = $value;
            }
            $kinds[] = [
                'word' => $word,
                'fact' => $fact,
                'empty' => $counts[$value] < 1,
                'selected' => $kind === $value,
                'url' => (new \moodle_url('/local/wwlibrary/index.php', $params))->out(false),
                'ismap' => $value === 'map',
                'isworld' => $value === 'world',
                'ismodel' => $value === 'model',
            ];
            $speech[] = get_string('speech', 'local_wwlibrary', (object) ['word' => $word, 'fact' => $fact]);
        }

        $folders = [];
        if ($schoolid && $kind === '' && !$folderid) {
            $rows = $DB->get_records('local_wwlibrary_folder', ['categoryid' => $schoolid], 'sortorder ASC, id ASC');
            foreach ($rows as $row) {
                $folders[] = [
                    'name' => self::plain($row->name),
                    'url' => (new \moodle_url('/local/wwlibrary/index.php', [
                        'school' => $schoolid,
                        'folder' => $row->id,
                    ]))->out(false),
                    'editurl' => (new \moodle_url('/local/wwlibrary/folder.php', ['id' => $row->id]))->out(false),
                ];
                $speech[] = self::plain($row->name);
            }
        }

        $resources = [];
        if ($schoolid) {
            $params = ['categoryid' => $schoolid];
            $select = 'categoryid = :categoryid';
            if ($kind !== '') {
                $select .= ' AND kind = :kind';
                $params['kind'] = $kind;
            } else {
                $select .= ' AND folderid = :folderid';
                $params['folderid'] = $folderid;
            }
            $rows = $DB->get_records_select('local_wwlibrary_resource', $select, $params, 'sortorder ASC, id ASC');
            foreach ($rows as $row) {
                $resources[] = [
                    'name' => self::plain($row->name),
                    'url' => (new \moodle_url('/local/wwlibrary/view.php', ['id' => $row->id]))->out(false),
                    'editurl' => (new \moodle_url('/local/wwlibrary/edit.php', ['id' => $row->id]))->out(false),
                    'ismap' => $row->kind === 'map',
                    'isworld' => $row->kind === 'world',
                    'ismodel' => $row->kind === 'model',
                ];
                $speech[] = self::plain($row->name);
            }
        }

        $schoolbuttons = [];
        if (count($schools) > 1) {
            $cats = $DB->get_records_list('course_categories', 'id', $schools, '', 'id, name');
            foreach ($schools as $id) {
                if (!isset($cats[$id])) {
                    continue;
                }
                $schoolbuttons[] = [
                    'name' => self::plain($cats[$id]->name),
                    'url' => (new \moodle_url('/local/wwlibrary/index.php', ['school' => $id]))->out(false),
                    'current' => (int) $id === $schoolid,
                ];
            }
        }

        return [
            'schools' => $schoolbuttons,
            'manyschools' => $schoolbuttons !== [],
            'kinds' => $kinds,
            'folders' => $folders,
            'resources' => $resources,
            'manage' => $manage,
            'addurl' => (new \moodle_url('/local/wwlibrary/edit.php', [
                'school' => $schoolid,
                'folder' => $folderid,
            ]))->out(false),
            'folderurl' => (new \moodle_url('/local/wwlibrary/folder.php', ['school' => $schoolid]))->out(false),
            'upurl' => $folderid
                ? (new \moodle_url('/local/wwlibrary/index.php', ['school' => $schoolid]))->out(false)
                : '',
            'add' => get_string('add', 'local_wwlibrary'),
            'folderword' => get_string('folder', 'local_wwlibrary'),
            'edit' => get_string('edit', 'local_wwlibrary'),
            'library' => get_string('library', 'local_wwlibrary'),
            'screenspeech' => implode(' ', $speech),
            'read' => get_string('read', 'local_wwlibrary'),
        ];
    }

    /**
     * Address of the add or edit form.
     *
     * A new resource keeps the school in the address and in the submitted
     * fields. An existing resource is found by its id.
     *
     * @param int $id
     * @param int $schoolid
     * @param int $folderid
     * @return \moodle_url
     */
    public static function edit_action(int $id, int $schoolid, int $folderid): \moodle_url {
        $url = new \moodle_url('/local/wwlibrary/edit.php');
        if ($id > 0) {
            $url->param('id', $id);
            return $url;
        }
        $url->param('school', $schoolid);
        if ($folderid > 0) {
            $url->param('folder', $folderid);
        }
        return $url;
    }

    /**
     * Page setup for a library screen.
     *
     * @param string $title
     * @param \moodle_url $url
     */
    public static function setup_page(string $title, \moodle_url $url): void {
        global $PAGE, $USER;

        $PAGE->set_context(\context_user::instance($USER->id));
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('base');
        $PAGE->set_secondary_navigation(false);
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
    }

    /**
     * Plain text for a name. Ampersands stay as ampersands.
     *
     * @param string $value
     * @return string
     */
    public static function plain(string $value): string {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * How a stored resource opens.
     *
     * @param \stdClass $resource
     * @return string url, model, package, world, or none.
     */
    public static function mode(\stdClass $resource): string {
        if ($resource->kind === 'world' && ($resource->source === 'url' || $resource->source === 'file')) {
            if ($resource->source === 'url' && self::https_url((string) $resource->externalurl)) {
                return 'world';
            }
            if ($resource->source === 'file' && $resource->mainfile !== '') {
                return 'world';
            }
        }
        if ($resource->kind === 'map' && $resource->source === 'url' && self::https_url((string) $resource->externalurl)) {
            return 'url';
        }
        if ($resource->source === 'file' && $resource->kind === 'model' && $resource->mainfile !== '') {
            return 'model';
        }
        if ($resource->source === 'package' && $resource->mainfile !== '') {
            $ext = strtolower(pathinfo($resource->mainfile, PATHINFO_EXTENSION));
            if ($ext === 'glb' || $ext === 'gltf') {
                return 'model';
            }
            if ($ext === 'html' || $ext === 'htm') {
                return 'package';
            }
        }
        return 'none';
    }

    /**
     * Schools collected from enrolment and from roles.
     *
     * @param int $userid
     * @param bool $manage
     * @return int[]
     */
    private static function school_ids_for(int $userid, bool $manage): array {
        if ($userid <= 0 || isguestuser($userid)) {
            return [];
        }
        if (is_siteadmin($userid)) {
            return self::top_school_ids();
        }
        $ids = [];
        $courses = enrol_get_users_courses($userid, true, 'id, category');
        foreach ($courses as $course) {
            $school = self::school_of_category((int) $course->category);
            $ids[$school] = $school;
        }
        if (class_exists(\local_wolfwhale\teacher::class)) {
            foreach (\local_wolfwhale\teacher::course_ids_for($userid) as $courseid) {
                $school = self::school_of_course((int) $courseid);
                $ids[$school] = $school;
            }
        }
        foreach (self::category_ids_for($userid) as $categoryid) {
            $school = self::school_of_category($categoryid);
            $ids[$school] = $school;
        }
        $kept = [];
        foreach ($ids as $school) {
            if (!$school) {
                continue;
            }
            $allowed = $manage
                ? self::can_manage_school($userid, $school)
                : self::can_view_school($userid, $school);
            if ($allowed) {
                $kept[] = $school;
            }
        }
        return $kept;
    }

    /**
     * Categories where this person holds any role.
     *
     * @param int $userid
     * @return int[]
     */
    private static function category_ids_for(int $userid): array {
        global $DB;

        $ids = $DB->get_fieldset_sql(
            "SELECT ctx.instanceid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = :userid
                AND ctx.contextlevel = :level",
            ['userid' => $userid, 'level' => CONTEXT_COURSECAT]
        );
        return array_map('intval', $ids);
    }

    /**
     * Course ids in one school, excluding the front page.
     *
     * @param int $schoolid
     * @return int[]
     */
    private static function courses_in(int $schoolid): array {
        global $DB;

        $tree = self::tree_ids($schoolid);
        if (!$tree) {
            return [];
        }
        [$sql, $params] = $DB->get_in_or_equal($tree, SQL_PARAMS_NAMED);
        $params['siteid'] = SITEID;
        $ids = $DB->get_fieldset_select('course', 'id', "category {$sql} AND id <> :siteid", $params);
        return array_map('intval', $ids);
    }

    /**
     * True when the category is a top-level category.
     *
     * @param int $categoryid
     * @return bool
     */
    private static function is_top(int $categoryid): bool {
        global $DB;

        if ($categoryid <= 0) {
            return false;
        }
        return $DB->record_exists('course_categories', ['id' => $categoryid, 'parent' => 0]);
    }

    /**
     * Decide the stored source from the upload and the link.
     *
     * @param \stdClass $record
     * @param \stdClass|null $existing
     * @param string $kind
     * @param string|null $url
     * @param string|null $path
     * @param string|null $filename
     * @param int $userid
     * @return array{source: string, mainfile: string, externalurl: string}
     */
    private static function resolve_source(
        \stdClass $record,
        ?\stdClass $existing,
        string $kind,
        ?string $url,
        ?string $path,
        ?string $filename,
        int $userid
    ): array {
        if ($path !== null) {
            $stored = storage::ingest((int) $record->id, $userid, $kind, $path, (string) $filename);
            $external = '';
            if ($kind === 'world' && $url) {
                $external = $url;
            }
            return [
                'source' => $stored['source'],
                'mainfile' => $stored['mainfile'],
                'externalurl' => $external,
            ];
        }

        if ($existing && $existing->kind === $kind && $existing->source !== 'url') {
            if ($kind === 'map' && $url) {
                storage::clear((int) $record->id);
                return ['source' => 'url', 'mainfile' => '', 'externalurl' => $url];
            }
            return [
                'source' => $existing->source,
                'mainfile' => $existing->mainfile,
                'externalurl' => ($kind === 'world' && $url) ? $url : '',
            ];
        }

        if ($url && ($kind === 'map' || $kind === 'world')) {
            if ($existing) {
                storage::clear((int) $record->id);
            }
            return ['source' => 'url', 'mainfile' => '', 'externalurl' => $url];
        }

        throw new \moodle_exception('refused', 'local_wwlibrary');
    }

    /**
     * Next sort value in a school, and in a folder when the table has one.
     *
     * @param string $table
     * @param int $categoryid
     * @param int|null $folderid
     * @return int
     */
    private static function next_sort(string $table, int $categoryid, ?int $folderid): int {
        global $DB;

        $params = ['categoryid' => $categoryid];
        $select = 'categoryid = :categoryid';
        if ($folderid !== null) {
            $select .= ' AND folderid = :folderid';
            $params['folderid'] = $folderid;
        }
        $max = $DB->get_field_select($table, 'MAX(sortorder)', $select, $params);
        return ((int) $max) + 1;
    }

    /**
     * Swap sort positions with the neighbour.
     *
     * @param string $table
     * @param \stdClass $row
     * @param int $direction
     * @param array $select
     */
    private static function swap(string $table, \stdClass $row, int $direction, array $select): void {
        global $DB;

        $rows = array_values($DB->get_records($table, $select, 'sortorder ASC, id ASC'));
        $index = null;
        foreach ($rows as $i => $candidate) {
            if ((int) $candidate->id === (int) $row->id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return;
        }
        $swap = $index + ($direction < 0 ? -1 : 1);
        if ($swap < 0 || $swap >= count($rows)) {
            return;
        }
        $other = $rows[$swap];
        $first = (int) $row->sortorder;
        $second = (int) $other->sortorder;
        if ($first === $second) {
            $second = $first + ($direction < 0 ? -1 : 1);
        }
        $DB->set_field($table, 'sortorder', $second, ['id' => $row->id]);
        $DB->set_field($table, 'sortorder', $first, ['id' => $other->id]);
    }
}
