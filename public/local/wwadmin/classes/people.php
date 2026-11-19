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
 * Add, suspend, remove, and upload people inside one school.
 *
 * Creating an account uses the core user record. The system-wide upload
 * screen can touch every school, so a school administrator uses the scoped
 * import here. A person who holds the system upload capability is also
 * offered that screen.
 *
 * @package    local_wwadmin
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class people {
    /** @var string[] Roles a school administrator may give from this screen. */
    public const ROLES = ['student', 'teacher', 'editingteacher'];

    /**
     * People in one school.
     *
     * @param int $categoryid
     * @return \stdClass[]
     */
    public static function list_users(int $categoryid): array {
        scope::require($categoryid, 'local/wwadmin:view');
        return scope::users(scope::tree_ids($categoryid));
    }

    /**
     * Create a person and enrol them in a course in this school.
     *
     * An existing person who already belongs to this school is enrolled.
     * An existing person who belongs to another school is left unchanged.
     *
     * @param int $categoryid
     * @param string $username
     * @param string $firstname
     * @param string $lastname
     * @param string $email
     * @param string $password
     * @param int $courseid
     * @param string $roleshortname
     * @return int User id.
     */
    public static function add(
        int $categoryid,
        string $username,
        string $firstname,
        string $lastname,
        string $email,
        string $password,
        int $courseid,
        string $roleshortname
    ): int {
        global $CFG, $DB;

        $username = \core_text::strtolower(trim($username));
        $firstname = trim($firstname);
        $lastname = trim($lastname);
        $email = trim($email);
        $roleshortname = trim($roleshortname);
        if ($username === '' || $firstname === '' || $lastname === '' || $email === '' || $password === '') {
            throw new \moodle_exception('missing', 'local_wwadmin');
        }

        scope::require($categoryid, 'local/wwadmin:managepeople');
        $context = \context_coursecat::instance($categoryid);
        require_capability('moodle/user:create', $context);

        $course = self::course_in_school($categoryid, $courseid);
        $roleid = self::role_id($roleshortname);
        $coursecontext = \context_course::instance($course->id);
        require_capability('enrol/manual:enrol', $coursecontext);

        $existing = $DB->get_record('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        if ($existing) {
            self::refuse_if_outside($categoryid, (int) $existing->id);
            self::enrol((int) $course->id, (int) $existing->id, $roleid);
            return (int) $existing->id;
        }

        $userid = \core\user::create_user((object) [
            'username' => $username,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'password' => $password,
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'city' => '',
            'country' => !empty($CFG->country) ? $CFG->country : 'CA',
        ]);
        self::enrol((int) $course->id, $userid, $roleid);
        return $userid;
    }

    /**
     * Read a CSV file and add each row to this school.
     *
     * Columns are username, firstname, lastname, email, password, course, role.
     * The course value is a course short name. A short name outside this school
     * is refused and that row is not created. Other rows still run.
     *
     * @param int $categoryid
     * @param string $content
     * @return \stdClass created (int), errors (array of word/fact).
     */
    public static function import_csv(int $categoryid, string $content): \stdClass {
        global $CFG;

        require_once($CFG->libdir . '/csvlib.class.php');
        scope::require($categoryid, 'local/wwadmin:managepeople');

        $iid = \csv_import_reader::get_new_iid('local_wwadmin');
        $reader = new \csv_import_reader($iid, 'local_wwadmin');
        $loaded = $reader->load_csv_content($content, 'utf-8', 'comma');
        if ($loaded === false) {
            $error = $reader->get_error();
            $reader->cleanup(true);
            throw new \moodle_exception('missing', 'local_wwadmin', '', null, $error);
        }
        $columns = array_map(static fn($column) => \core_text::strtolower(trim($column)), $reader->get_columns());
        $reader->init();
        $created = 0;
        $errors = [];
        while ($line = $reader->next()) {
            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = $line[$index] ?? '';
            }
            $label = trim((string) ($row['username'] ?? ''));
            if ($label === '') {
                $label = get_string('notadded', 'local_wwadmin');
            }
            try {
                $course = self::course_by_shortname($categoryid, (string) ($row['course'] ?? ''));
                self::add(
                    $categoryid,
                    (string) ($row['username'] ?? ''),
                    (string) ($row['firstname'] ?? ''),
                    (string) ($row['lastname'] ?? ''),
                    (string) ($row['email'] ?? ''),
                    (string) ($row['password'] ?? ''),
                    (int) $course->id,
                    (string) ($row['role'] ?? '')
                );
                $created++;
            } catch (\moodle_exception $error) {
                $errors[] = [
                    'word' => $label,
                    'fact' => self::error_fact($error),
                ];
            }
        }
        $reader->close();
        $reader->cleanup(true);
        return (object) [
            'created' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * Suspend a person who belongs only to this school.
     *
     * Suspending an account is site-wide, so a person who also belongs to
     * another school is refused and left active.
     *
     * @param int $categoryid
     * @param int $userid
     */
    public static function suspend(int $categoryid, int $userid): void {
        global $USER;

        self::guard_person($categoryid, $userid, true);
        if ($userid === (int) $USER->id) {
            throw new \moodle_exception('notinschool', 'local_wwadmin');
        }
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $user->suspended = 1;
        \core\user::update_user($user, false);
    }

    /**
     * Take a person out of this school.
     *
     * Enrolments and roles in this school are removed. The account is deleted
     * only when the person belongs nowhere else, holds no site-wide role, and
     * the administrator is allowed to delete users. A person who also belongs
     * to another school keeps that account and those enrolments.
     *
     * @param int $categoryid
     * @param int $userid
     */
    public static function remove(int $categoryid, int $userid): void {
        global $DB, $USER;

        self::guard_person($categoryid, $userid, false);
        if ($userid === (int) $USER->id || is_siteadmin($userid)) {
            throw new \moodle_exception('notinschool', 'local_wwadmin');
        }
        $tree = scope::tree_ids($categoryid);
        foreach (scope::courses($tree) as $course) {
            $context = \context_course::instance($course->id);
            require_capability('enrol/manual:unenrol', $context);
            $plugins = enrol_get_plugins(false);
            foreach (enrol_get_instances($course->id, false) as $instance) {
                if (!isset($plugins[$instance->enrol])) {
                    continue;
                }
                if (!is_enrolled($context, $userid)) {
                    continue;
                }
                $plugins[$instance->enrol]->unenrol_user($instance, $userid);
            }
            role_unassign_all(['userid' => $userid, 'contextid' => $context->id]);
        }
        foreach ($tree as $id) {
            $context = \context_coursecat::instance($id);
            role_unassign_all(['userid' => $userid, 'contextid' => $context->id]);
        }

        if (scope::school_category_ids($userid)) {
            return;
        }
        $systemrole = $DB->record_exists_sql(
            "SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = ? AND ctx.contextlevel = ?",
            [$userid, CONTEXT_SYSTEM]
        );
        $context = \context_coursecat::instance($categoryid);
        if ($systemrole || !has_capability('moodle/user:delete', $context)) {
            return;
        }
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        \core\user::delete_user($user);
    }

    /**
     * Courses in this school, for the add form.
     *
     * @param int $categoryid
     * @return \stdClass[]
     */
    public static function course_choices(int $categoryid): array {
        scope::require($categoryid, 'local/wwadmin:managepeople');
        return scope::courses(scope::tree_ids($categoryid));
    }

    /**
     * Check the person is in this school, and optionally nowhere else.
     *
     * @param int $categoryid
     * @param int $userid
     * @param bool $exclusive Suspend needs a person who is not in another school.
     */
    private static function guard_person(int $categoryid, int $userid, bool $exclusive): void {
        scope::require($categoryid, 'local/wwadmin:managepeople');
        $context = \context_coursecat::instance($categoryid);
        require_capability('moodle/user:update', $context);
        if (isguestuser($userid) || is_siteadmin($userid)) {
            throw new \moodle_exception('notinschool', 'local_wwadmin');
        }
        self::refuse_if_outside($categoryid, $userid, $exclusive);
    }

    /**
     * Refuse when the person is outside this school.
     *
     * @param int $categoryid
     * @param int $userid
     * @param bool $exclusive Also refuse when they belong to another school.
     */
    private static function refuse_if_outside(int $categoryid, int $userid, bool $exclusive = true): void {
        $tree = scope::tree_ids($categoryid);
        $member = scope::school_category_ids($userid);
        if (!array_intersect($member, $tree)) {
            throw new \moodle_exception('notinschool', 'local_wwadmin');
        }
        if ($exclusive && array_diff($member, $tree)) {
            throw new \moodle_exception('outside', 'local_wwadmin');
        }
    }

    /**
     * A course in this school.
     *
     * @param int $categoryid
     * @param int $courseid
     * @return \stdClass
     */
    private static function course_in_school(int $categoryid, int $courseid): \stdClass {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || !in_array((int) $course->category, scope::tree_ids($categoryid), true)) {
            throw new \moodle_exception('notincategory', 'local_wwadmin');
        }
        return $course;
    }

    /**
     * A course in this school, looked up by short name.
     *
     * @param int $categoryid
     * @param string $shortname
     * @return \stdClass
     */
    private static function course_by_shortname(int $categoryid, string $shortname): \stdClass {
        global $DB;

        $shortname = trim($shortname);
        $course = $shortname === '' ? false : $DB->get_record('course', ['shortname' => $shortname]);
        if (!$course || !in_array((int) $course->category, scope::tree_ids($categoryid), true)) {
            throw new \moodle_exception('notincategory', 'local_wwadmin');
        }
        return $course;
    }

    /**
     * A role a school administrator is allowed to give.
     *
     * @param string $shortname
     * @return int
     */
    private static function role_id(string $shortname): int {
        global $DB;

        if (!in_array($shortname, self::ROLES, true)) {
            throw new \moodle_exception('invalidrole', 'local_wwadmin');
        }
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Enrol one person with the manual plugin.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $roleid
     */
    private static function enrol(int $courseid, int $userid, int $roleid): void {
        if (!enrol_try_internal_enrol($courseid, $userid, $roleid)) {
            throw new \moodle_exception('notadded', 'local_wwadmin');
        }
    }

    /**
     * A short reason safe to show for one failed row.
     *
     * @param \moodle_exception $error
     * @return string
     */
    private static function error_fact(\moodle_exception $error): string {
        $known = ['missing', 'notincategory', 'notinschool', 'outside', 'invalidrole', 'notadded'];
        if (in_array($error->errorcode, $known, true)) {
            return get_string($error->errorcode, 'local_wwadmin');
        }
        return get_string('notadded', 'local_wwadmin');
    }
}
