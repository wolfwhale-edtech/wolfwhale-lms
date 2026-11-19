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
    /** @var string[] App, email, and text. */
    public const CHANNELS = ['app', 'email', 'text'];

    /** @var string[] Kinds of update. */
    public const TYPES = ['mark', 'absent', 'due', 'badge', 'message', 'weekly'];

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
     * This parent's mobile number, or an empty string.
     *
     * The number is the mobile field on this parent's own account.
     *
     * @param int $userid
     * @return string
     */
    public static function phone(int $userid): string {
        global $DB;

        $value = $DB->get_field('user', 'phone2', ['id' => $userid]);
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Store a mobile number for the signed-in parent only.
     *
     * @param int $userid
     * @param string $raw
     */
    public static function set_phone(int $userid, string $raw): void {
        global $DB, $USER;

        if ((int) $USER->id !== $userid || $userid <= 0) {
            throw new \moodle_exception('cannotview', 'local_wwupdates');
        }
        $DB->set_field('user', 'phone2', self::clean_phone($raw), ['id' => $userid]);
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
