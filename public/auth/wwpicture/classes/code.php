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

namespace auth_wwpicture;

/**
 * Hashed picture codes, the class link, and the sign-in check.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class code {
    /** @var int Wrong tries before the account locks. */
    public const TRIES = 5;

    /** @var int Seconds a lock lasts. */
    public const LOCK = 900;

    /** @var int Seconds between tries. */
    public const GAP = 2;

    /**
     * Store a new code as a hash. The plain pictures are not written.
     *
     * @param int $userid
     * @param string[] $picks
     */
    public static function set(int $userid, array $picks): void {
        global $DB;

        $plain = self::plain($picks);
        if ($plain === '') {
            throw new \moodle_exception('picture', 'auth_wwpicture');
        }
        $now = self::now();
        $record = (object) [
            'userid' => $userid,
            'codehash' => password_hash($plain, PASSWORD_DEFAULT),
            'failures' => 0,
            'lockeduntil' => 0,
            'lastattempt' => 0,
            'timemodified' => $now,
        ];
        $existing = $DB->get_record('auth_wwpicture_code', ['userid' => $userid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('auth_wwpicture_code', $record);
            return;
        }
        $DB->insert_record('auth_wwpicture_code', $record);
    }

    /**
     * Three different pictures.
     *
     * @return string[]
     */
    public static function random(): array {
        $keys = pictures::KEYS;
        $picks = [];
        while (count($picks) < 3) {
            $pick = $keys[random_int(0, count($keys) - 1)];
            if (!in_array($pick, $picks, true)) {
                $picks[] = $pick;
            }
        }
        return $picks;
    }

    /**
     * Replace one student's code and remember the pictures for the print page.
     *
     * @param int $userid
     * @return string[]
     */
    public static function reset_student(int $userid): array {
        $picks = self::random();
        self::set($userid, $picks);
        self::remember_card($userid, $picks);
        return $picks;
    }

    /**
     * The secret class link. Created the first time a teacher opens the class page.
     *
     * @param int $courseid
     * @return string
     */
    public static function token(int $courseid): string {
        global $DB;

        $row = $DB->get_record('auth_wwpicture_link', ['courseid' => $courseid]);
        if ($row) {
            return $row->token;
        }
        $token = bin2hex(random_bytes(16));
        $DB->insert_record('auth_wwpicture_link', (object) [
            'courseid' => $courseid,
            'token' => $token,
            'timecreated' => self::now(),
        ]);
        return $token;
    }

    /**
     * The class page is open only with the teacher's link, and only while the school allows it.
     *
     * @param int $courseid
     * @param string $token
     * @return bool
     */
    public static function open(int $courseid, string $token): bool {
        global $DB;

        if ($courseid <= 0 || $token === '' || !is_enabled_auth('wwpicture') || !school::picture_on($courseid)) {
            return false;
        }
        $row = $DB->get_record('auth_wwpicture_link', ['courseid' => $courseid]);
        if (!$row) {
            return false;
        }
        return hash_equals($row->token, $token);
    }

    /**
     * Students enrolled in this course.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function students(int $courseid): array {
        global $DB;

        $context = \context_course::instance($courseid);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        $now = self::now();
        $users = get_role_users(
            $roleid,
            $context,
            false,
            'u.id, u.firstname, u.lastname, u.middlename, u.alternatename, u.firstnamephonetic, ' .
            'u.lastnamephonetic, u.suspended, u.deleted, u.auth, u.confirmed, u.picture, u.imagealt',
            'u.firstname, u.lastname',
            false,
            '',
            '',
            '',
            'ue.status = :active AND e.status = :enabled AND ue.timestart <= :now1 ' .
                'AND (ue.timeend = 0 OR ue.timeend > :now2)',
            [
                'active' => ENROL_USER_ACTIVE,
                'enabled' => ENROL_INSTANCE_ENABLED,
                'now1' => $now,
                'now2' => $now,
            ]
        );
        $rows = [];
        foreach ($users as $user) {
            if ($user->deleted || $user->suspended || !$user->confirmed || $user->auth === 'nologin') {
                continue;
            }
            $rows[] = $user;
        }
        return $rows;
    }

    /**
     * A face for the class page. Uses the points avatar when that plugin is installed.
     *
     * @param \stdClass $user
     * @return string
     */
    public static function face(\stdClass $user): string {
        if (class_exists(\local_wwpoints\avatar::class)) {
            return \local_wwpoints\avatar::svg_for((int) $user->id);
        }
        $letters = \core_text::strtoupper(
            \core_text::substr($user->firstname, 0, 1) . \core_text::substr($user->lastname, 0, 1)
        );
        return '<span class="ww-face" aria-hidden="true">' . s($letters) . '</span>';
    }

    /**
     * Add one tap. The third tap tries the code.
     *
     * @param int $courseid
     * @param string $token
     * @param int $userid
     * @param string $pick
     * @return string partial, ok, bad, locked, limited, denied, or closed
     */
    public static function tap(int $courseid, string $token, int $userid, string $pick): string {
        global $SESSION;

        if (!self::open($courseid, $token)) {
            return 'closed';
        }
        $try = $SESSION->wwpicturetry ?? null;
        if (!$try || (int) $try['course'] !== $courseid || (int) $try['user'] !== $userid) {
            $try = ['course' => $courseid, 'user' => $userid, 'picks' => []];
        }
        if (!in_array($pick, pictures::KEYS, true) || count($try['picks']) >= 3) {
            $SESSION->wwpicturetry = $try;
            return 'partial';
        }
        $try['picks'][] = $pick;
        if (count($try['picks']) < 3) {
            $SESSION->wwpicturetry = $try;
            return 'partial';
        }
        $picks = $try['picks'];
        $try['picks'] = [];
        $SESSION->wwpicturetry = $try;
        return self::sign_in($courseid, $token, $userid, $picks);
    }

    /**
     * Pictures chosen so far on this class page.
     *
     * @param int $courseid
     * @param int $userid
     * @return string[]
     */
    public static function current_picks(int $courseid, int $userid): array {
        global $SESSION;

        $try = $SESSION->wwpicturetry ?? null;
        if (!$try || (int) $try['course'] !== $courseid || (int) $try['user'] !== $userid) {
            return [];
        }
        return $try['picks'];
    }

    /**
     * Drop the pictures chosen so far.
     */
    public static function clear_try(): void {
        global $SESSION;

        unset($SESSION->wwpicturetry);
    }

    /**
     * Check a picture code and open a real session when it matches.
     *
     * @param int $courseid
     * @param string $token
     * @param int $userid
     * @param string[] $picks
     * @return string ok, bad, locked, limited, denied, or closed
     */
    public static function sign_in(int $courseid, string $token, int $userid, array $picks): string {
        if (!self::open($courseid, $token)) {
            return 'closed';
        }
        if (!self::is_student($courseid, $userid)) {
            return 'denied';
        }

        // One attempt at a time for this person, so two browsers cannot share a failure count.
        $lockfactory = \core\lock\lock_config::get_lock_factory('auth_wwpicture');
        $lock = $lockfactory->get_lock('code' . $userid, 5);
        if (!$lock) {
            return 'limited';
        }
        try {
            return self::check_code($userid, $picks);
        } finally {
            $lock->release();
        }
    }

    /**
     * Read the stored hash, count a failure, or open the session.
     *
     * The caller holds the lock for this person.
     *
     * @param int $userid
     * @param string[] $picks
     * @return string
     */
    private static function check_code(int $userid, array $picks): string {
        global $DB;

        $row = $DB->get_record('auth_wwpicture_code', ['userid' => $userid]);
        if (!$row || $row->codehash === '') {
            return 'denied';
        }

        $now = self::now();
        if ((int) $row->lockeduntil > $now) {
            return 'locked';
        }
        if ((int) $row->lockeduntil > 0) {
            $row->failures = 0;
            $row->lockeduntil = 0;
        }
        if ((int) $row->lastattempt > 0 && ($now - (int) $row->lastattempt) < self::GAP) {
            return 'limited';
        }

        $plain = self::plain($picks);
        if ($plain === '' || !password_verify($plain, $row->codehash)) {
            $row->failures = (int) $row->failures + 1;
            $row->lastattempt = $now;
            $row->timemodified = $now;
            if ((int) $row->failures >= self::TRIES) {
                $row->lockeduntil = $now + self::LOCK;
            }
            $DB->update_record('auth_wwpicture_code', $row);
            return 'bad';
        }

        $row->failures = 0;
        $row->lockeduntil = 0;
        $row->lastattempt = $now;
        $row->timemodified = $now;
        $DB->update_record('auth_wwpicture_code', $row);

        $user = get_complete_user_data('id', $userid);
        if (!$user || $user->deleted || $user->suspended || $user->auth === 'nologin') {
            return 'denied';
        }
        // Session regeneration warns under the test runner. The login is still real.
        @complete_user_login($user);
        return 'ok';
    }

    /**
     * Cards waiting to be printed in this teacher's session.
     *
     * @return array[]
     */
    public static function cards(): array {
        global $SESSION;

        if (empty($SESSION->wwpicturecards) || !is_array($SESSION->wwpicturecards)) {
            return [];
        }
        return array_values($SESSION->wwpicturecards);
    }

    /**
     * Keep the plain pictures in the teacher session so the card can be printed.
     *
     * The database row stays a hash.
     *
     * @param int $userid
     * @param string[] $picks
     */
    public static function remember_card(int $userid, array $picks): void {
        global $DB, $SESSION;

        $user = $DB->get_record('user', ['id' => $userid],
            'id, firstname, lastname, middlename, alternatename, firstnamephonetic, lastnamephonetic', MUST_EXIST);
        if (!isset($SESSION->wwpicturecards) || !is_array($SESSION->wwpicturecards)) {
            $SESSION->wwpicturecards = [];
        }
        $svgs = [];
        foreach ($picks as $pick) {
            $svgs[] = ['svg' => pictures::svg($pick)];
        }
        $SESSION->wwpicturecards[$userid] = [
            'name' => fullname($user),
            'face' => self::face($user),
            'pics' => $svgs,
        ];
    }

    /**
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private static function is_student(int $courseid, int $userid): bool {
        foreach (self::students($courseid) as $user) {
            if ((int) $user->id === $userid) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string[] $picks
     * @return string
     */
    private static function plain(array $picks): string {
        if (count($picks) !== 3) {
            return '';
        }
        foreach ($picks as $pick) {
            if (!in_array($pick, pictures::KEYS, true)) {
                return '';
            }
        }
        return implode(',', $picks);
    }

    /**
     * @return int
     */
    private static function now(): int {
        return \core\di::get(\core\clock::class)->time();
    }
}
