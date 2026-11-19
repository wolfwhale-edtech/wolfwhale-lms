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

namespace local_wwupdates;

/**
 * What a parent wants to hear, and the phone number used only for that parent.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preferences {
    /** @var string Profile field shortname. */
    public const FIELD = 'parentphone';

    /** @var string[] App, email, and text. */
    public const CHANNELS = ['app', 'email', 'text'];

    /** @var string[] Kinds of update. */
    public const TYPES = ['mark', 'absent', 'due', 'badge', 'message', 'weekly'];

    /**
     * Create the phone field when it is missing.
     */
    public static function install_phone_field(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/profile/lib.php');
        if ($DB->record_exists('user_info_field', ['shortname' => self::FIELD])) {
            return;
        }
        $categoryid = $DB->get_field('user_info_category', 'id', ['name' => 'Parent']);
        if (!$categoryid) {
            $sort = (int) $DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {user_info_category}');
            $categoryid = $DB->insert_record('user_info_category', (object) [
                'name' => 'Parent',
                'sortorder' => $sort,
            ]);
        }
        $sort = (int) $DB->get_field_sql(
            'SELECT COALESCE(MAX(sortorder), 0) + 1 FROM {user_info_field} WHERE categoryid = ?',
            [$categoryid]
        );
        $DB->insert_record('user_info_field', (object) [
            'shortname' => self::FIELD,
            'name' => get_string('phone', 'local_wwupdates'),
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'categoryid' => $categoryid,
            'sortorder' => $sort,
            'required' => 0,
            'locked' => 0,
            'visible' => PROFILE_VISIBLE_PRIVATE,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => 0,
            'param1' => '20',
            'param2' => '20',
            'param3' => '0',
            'param4' => '',
            'param5' => '',
        ]);
    }

    /**
     * True when this switch is on. Text is off until the parent turns it on.
     *
     * @param int $userid
     * @param string $key
     * @return bool
     */
    public static function on(int $userid, string $key): bool {
        $default = $key === 'text' ? '0' : '1';
        return (string) get_user_preferences(self::name($key), $default, $userid) === '1';
    }

    /**
     * True when at least one channel is on.
     *
     * @param int $userid
     * @return bool
     */
    public static function any_channel(int $userid): bool {
        foreach (self::CHANNELS as $key) {
            if (self::on($userid, $key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Store the switches for this parent.
     *
     * @param int $userid
     * @param array $on Key => truthy when the switch is on.
     */
    public static function save(int $userid, array $on): void {
        foreach (array_merge(self::CHANNELS, self::TYPES) as $key) {
            set_user_preference(self::name($key), empty($on[$key]) ? '0' : '1', $userid);
        }
    }

    /**
     * The preference name.
     *
     * @param string $key
     * @return string
     */
    public static function name(string $key): string {
        return 'local_wwupdates_' . $key;
    }

    /**
     * This parent's phone number, or an empty string.
     *
     * @param int $userid
     * @return string
     */
    public static function phone(int $userid): string {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');
        $record = profile_user_record($userid, false);
        $value = $record->{self::FIELD} ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Store a phone number for the signed-in parent only.
     *
     * @param int $userid
     * @param string $raw
     */
    public static function set_phone(int $userid, string $raw): void {
        global $CFG, $USER;

        if ((int) $USER->id !== $userid || $userid <= 0) {
            throw new \moodle_exception('cannotview', 'local_wwupdates');
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $user = (object) [
            'id' => $userid,
            'profile_field_' . self::FIELD => self::clean_phone($raw),
        ];
        profile_save_data($user);
    }

    /**
     * Digits and a leading plus, or empty when the value cannot be used.
     *
     * @param string $raw
     * @return string
     */
    public static function clean_phone(string $raw): string {
        $clean = preg_replace('/[^0-9+]/', '', trim($raw));
        if (!is_string($clean) || !preg_match('/^\+?[0-9]{8,15}$/', $clean)) {
            return '';
        }
        return $clean;
    }

    /**
     * Template data for the settings page.
     *
     * @param int $userid
     * @return array
     */
    public static function form(int $userid): array {
        return [
            'channels' => self::switches(self::CHANNELS, $userid),
            'types' => self::switches(self::TYPES, $userid),
            'phone' => self::phone($userid),
            'phoneword' => get_string('phone', 'local_wwupdates'),
            'save' => get_string('save', 'local_wwupdates'),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * One row of switches.
     *
     * @param string[] $keys
     * @param int $userid
     * @return array[]
     */
    private static function switches(array $keys, int $userid): array {
        $out = [];
        foreach ($keys as $key) {
            $on = self::on($userid, $key);
            $out[] = [
                'key' => $key,
                'word' => get_string($key, 'local_wwupdates'),
                'on' => $on,
                'state' => get_string($on ? 'on' : 'off', 'local_wwupdates'),
                'is' . $key => true,
            ];
        }
        return $out;
    }
}
