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

namespace local_wwpoints;

/**
 * A layered avatar made of generic shapes and colours.
 *
 * The parts are a face shape, a skin tone, hair, eyes, a clothing colour,
 * and an accessory. Nothing here is a cultural design, regalia, or a word
 * from an Indigenous language. A later change can add part keys beside these.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class avatar {
    /** @var string Part key = level required. Missing keys are open at level 1. */
    public const DEFAULT_UNLOCKS = "hair:tied=2\naccessory:glasses=2\naccessory:bow=3\naccessory:cap=3\naccessory:star=4";

    /** @var string[] */
    public const PARTS = ['face', 'skin', 'hair', 'eyes', 'clothing', 'accessory'];

    /** @var string[] */
    private const FACES = ['round', 'oval', 'square'];

    /** @var string[] */
    private const SKINS = ['apricot', 'sand', 'honey', 'oak', 'cocoa', 'rose'];

    /** @var string[] */
    private const HAIR = ['none', 'short', 'long', 'curly', 'tied'];

    /** @var string[] */
    private const EYES = ['dot', 'round', 'wide'];

    /** @var string[] */
    private const CLOTHES = ['teal', 'blue', 'gold', 'red', 'indigo', 'white'];

    /** @var string[] */
    private const EXTRAS = ['none', 'glasses', 'bow', 'cap', 'star'];

    /** @var array<string, string> */
    private const SKIN_HEX = [
        'apricot' => '#f6d7c3',
        'sand' => '#e8c4a4',
        'honey' => '#c68642',
        'oak' => '#8d5524',
        'cocoa' => '#5c3317',
        'rose' => '#f0c2b0',
    ];

    /** @var array<string, string> */
    private const CLOTH_HEX = [
        'teal' => '#149a9a',
        'blue' => '#2f5d8a',
        'gold' => '#e6b325',
        'red' => '#d64545',
        'indigo' => '#3d4db8',
        'white' => '#f4f7fb',
    ];

    /**
     * The picture for one person. A person with no saved parts gets the plain one.
     *
     * @param int $userid
     * @return string SVG markup. It contains no text from the person.
     */
    public static function svg_for(int $userid): string {
        return self::svg(self::parts_for($userid));
    }

    /**
     * Saved parts, or the plain parts when this person has not chosen any.
     *
     * @param int $userid
     * @return array<string, string>
     */
    public static function parts_for(int $userid): array {
        global $DB;

        $saved = $userid > 0 ? $DB->get_record('local_wwpoints_avatar', ['userid' => $userid]) : false;
        $parts = self::defaults();
        if ($saved) {
            foreach (self::PARTS as $part) {
                $value = (string) $saved->$part;
                if (self::known($part, $value)) {
                    $parts[$part] = $value;
                }
            }
        }
        return $parts;
    }

    /**
     * Store this person's own parts. Another person's id is refused.
     *
     * A part that the person's level has not opened is refused, unless it is
     * the part they already have.
     *
     * @param int $userid
     * @param array $incoming Part => value. Missing parts stay as they are.
     */
    public static function save(int $userid, array $incoming): void {
        global $DB, $USER;

        if ($userid <= 0 || (int) $USER->id !== $userid || isguestuser()) {
            throw new \moodle_exception('cannotview', 'local_wwpoints');
        }
        $current = self::parts_for($userid);
        $open = self::choices(levels::for_points(points::total($userid)));
        $next = $current;
        foreach (self::PARTS as $part) {
            if (!array_key_exists($part, $incoming)) {
                continue;
            }
            $value = (string) $incoming[$part];
            if (!self::known($part, $value)) {
                throw new \moodle_exception('locked', 'local_wwpoints');
            }
            $allowed = in_array($value, $open[$part], true) || $value === $current[$part];
            if (!$allowed) {
                throw new \moodle_exception('locked', 'local_wwpoints');
            }
            $next[$part] = $value;
        }
        $record = (object) $next;
        $record->userid = $userid;
        $record->timemodified = time();
        $existing = $DB->get_record('local_wwpoints_avatar', ['userid' => $userid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_wwpoints_avatar', $record);
        } else {
            $DB->insert_record('local_wwpoints_avatar', $record);
        }
    }

    /**
     * Picker data for the logged-in person.
     *
     * @param int $userid
     * @return array
     */
    public static function form(int $userid): array {
        $parts = self::parts_for($userid);
        $open = self::choices(levels::for_points(points::total($userid)));
        $groups = [];
        $speech = [];
        foreach (self::PARTS as $part) {
            $options = [];
            foreach ($open[$part] as $value) {
                $on = $parts[$part] === $value;
                $word = self::word($value);
                if ($on) {
                    $speech[] = $word;
                }
                $options[] = [
                    'part' => $part,
                    'value' => $value,
                    'word' => $word,
                    'on' => $on,
                    'swatch' => self::swatch($part, $value),
                ];
            }
            $groups[] = [
                'word' => get_string($part, 'local_wwpoints'),
                'options' => $options,
            ];
        }
        $hidden = [];
        foreach (self::PARTS as $part) {
            $hidden[] = ['name' => $part, 'value' => $parts[$part]];
        }
        return [
            'svg' => self::svg($parts),
            'groups' => $groups,
            'hidden' => $hidden,
            'speech' => implode('. ', $speech),
        ];
    }

    /**
     * Avatar rows for people in one school.
     *
     * @param int[] $userids
     * @return array[]
     */
    public static function export_rows(array $userids): array {
        global $DB;

        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'local_wwpoints_avatar',
            "userid {$insql}",
            $params,
            'userid ASC'
        );
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'userid' => (int) $record->userid,
                'face' => $record->face,
                'skin' => $record->skin,
                'hair' => $record->hair,
                'eyes' => $record->eyes,
                'clothing' => $record->clothing,
                'accessory' => $record->accessory,
            ];
        }
        return $rows;
    }

    /**
     * Values this level may choose.
     *
     * @param int $level
     * @return array<string, string[]>
     */
    public static function choices(int $level): array {
        $locks = self::locks();
        $groups = [
            'face' => self::FACES,
            'skin' => self::SKINS,
            'hair' => self::HAIR,
            'eyes' => self::EYES,
            'clothing' => self::CLOTHES,
            'accessory' => self::EXTRAS,
        ];
        $open = [];
        foreach ($groups as $part => $values) {
            $open[$part] = [];
            foreach ($values as $value) {
                $need = $locks[$part . ':' . $value] ?? 1;
                if ($level >= $need) {
                    $open[$part][] = $value;
                }
            }
        }
        return $open;
    }

    /**
     * Plain starting parts. Every one of these is open at level 1.
     *
     * @return array<string, string>
     */
    private static function defaults(): array {
        return [
            'face' => 'round',
            'skin' => 'sand',
            'hair' => 'short',
            'eyes' => 'round',
            'clothing' => 'teal',
            'accessory' => 'none',
        ];
    }

    /**
     * @param string $part
     * @param string $value
     * @return bool
     */
    private static function known(string $part, string $value): bool {
        $groups = [
            'face' => self::FACES,
            'skin' => self::SKINS,
            'hair' => self::HAIR,
            'eyes' => self::EYES,
            'clothing' => self::CLOTHES,
            'accessory' => self::EXTRAS,
        ];
        return isset($groups[$part]) && in_array($value, $groups[$part], true);
    }

    /**
     * @return array<string, int>
     */
    private static function locks(): array {
        $raw = get_config('local_wwpoints', 'unlocks');
        if (!is_string($raw) || trim($raw) === '') {
            $raw = self::DEFAULT_UNLOCKS;
        }
        $locks = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $need] = array_map('trim', explode('=', $line, 2));
            if ($key !== '' && ctype_digit($need)) {
                $locks[$key] = (int) $need;
            }
        }
        return $locks;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function word(string $value): string {
        $key = ($value === 'none') ? 'none' : $value;
        return get_string($key, 'local_wwpoints');
    }

    /**
     * @param string $part
     * @param string $value
     * @return string
     */
    private static function swatch(string $part, string $value): string {
        if ($part === 'skin') {
            return self::SKIN_HEX[$value] ?? '';
        }
        if ($part === 'clothing') {
            return self::CLOTH_HEX[$value] ?? '';
        }
        return '';
    }

    /**
     * Draw one avatar. Every colour comes from the fixed lists above.
     *
     * @param array<string, string> $parts
     * @return string
     */
    private static function svg(array $parts): string {
        $skin = self::SKIN_HEX[$parts['skin']];
        $cloth = self::CLOTH_HEX[$parts['clothing']];
        $hair = '#3a2a22';
        $bits = ['<path d="M12 64c6-14 14-18 28-18s22 4 28 18v16H12z" fill="' . $cloth . '"/>'];
        if ($parts['face'] === 'oval') {
            $bits[] = '<ellipse cx="40" cy="40" rx="15" ry="19" fill="' . $skin . '"/>';
        } else if ($parts['face'] === 'square') {
            $bits[] = '<rect x="25" y="22" width="30" height="36" rx="8" fill="' . $skin . '"/>';
        } else {
            $bits[] = '<circle cx="40" cy="40" r="18" fill="' . $skin . '"/>';
        }
        $bits[] = self::eye_markup($parts['eyes']);
        $bits[] = '<path d="M33 48c2 3 12 3 14 0" fill="none" stroke="#6b3a2a" stroke-width="1.6" stroke-linecap="round"/>';
        $bits[] = self::hair_markup($parts['hair'], $hair);
        $bits[] = self::extra_markup($parts['accessory']);
        $inner = implode('', array_filter($bits));
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80" aria-hidden="true" focusable="false">' .
            $inner . '</svg>';
    }

    /**
     * @param string $eyes
     * @return string
     */
    private static function eye_markup(string $eyes): string {
        if ($eyes === 'dot') {
            return '<circle cx="33" cy="40" r="1.7" fill="#1c1c1c"/>' .
                '<circle cx="47" cy="40" r="1.7" fill="#1c1c1c"/>';
        }
        if ($eyes === 'wide') {
            return '<ellipse cx="33" cy="40" rx="4.2" ry="2.8" fill="#fff"/>' .
                '<ellipse cx="47" cy="40" rx="4.2" ry="2.8" fill="#fff"/>' .
                '<circle cx="33" cy="40" r="1.5" fill="#1c1c1c"/>' .
                '<circle cx="47" cy="40" r="1.5" fill="#1c1c1c"/>';
        }
        return '<circle cx="33" cy="40" r="3.2" fill="#fff"/>' .
            '<circle cx="47" cy="40" r="3.2" fill="#fff"/>' .
            '<circle cx="33" cy="40" r="1.6" fill="#1c1c1c"/>' .
            '<circle cx="47" cy="40" r="1.6" fill="#1c1c1c"/>';
    }

    /**
     * @param string $style
     * @param string $colour
     * @return string
     */
    private static function hair_markup(string $style, string $colour): string {
        if ($style === 'none') {
            return '';
        }
        $short = '<path d="M22 38c0-14 8-22 18-22s18 8 18 22c-3-7-8-9-18-9s-15 2-18 9z" fill="' . $colour . '"/>';
        if ($style === 'short') {
            return $short;
        }
        if ($style === 'long') {
            return $short .
                '<path d="M22 36c-1 18 0 28 4 32 4-12 4-18 5-24 6 2 12 2 18 0 1 6 1 12 5 24 4-4 5-14 4-32" fill="' .
                $colour . '"/>';
        }
        if ($style === 'curly') {
            return '<circle cx="28" cy="24" r="6" fill="' . $colour . '"/>' .
                '<circle cx="40" cy="20" r="7" fill="' . $colour . '"/>' .
                '<circle cx="52" cy="24" r="6" fill="' . $colour . '"/>' .
                '<circle cx="22" cy="34" r="5" fill="' . $colour . '"/>' .
                '<circle cx="58" cy="34" r="5" fill="' . $colour . '"/>';
        }
        return $short . '<circle cx="40" cy="14" r="6" fill="' . $colour . '"/>';
    }

    /**
     * @param string $extra
     * @return string
     */
    private static function extra_markup(string $extra): string {
        if ($extra === 'glasses') {
            return '<circle cx="33" cy="40" r="6" fill="none" stroke="#243044" stroke-width="1.8"/>' .
                '<circle cx="47" cy="40" r="6" fill="none" stroke="#243044" stroke-width="1.8"/>' .
                '<path d="M39 40h2" stroke="#243044" stroke-width="1.8"/>';
        }
        if ($extra === 'bow') {
            return '<path d="M62 46c5-4 9-1 9 3s-4 7-9 3c-5 4-9 1-9-3s4-7 9-3z" fill="#d64545"/>';
        }
        if ($extra === 'cap') {
            return '<path d="M24 30c2-12 8-16 16-16s14 4 16 16" fill="#2f5d8a"/>' .
                '<path d="M18 30h44c0 3-8 5-22 5s-22-2-22-5z" fill="#2f5d8a"/>';
        }
        if ($extra === 'star') {
            return '<path d="M40 58l2.4 4.6 5.1.7-3.7 3.6.9 5.1L40 69.6 35.3 72l.9-5.1-3.7-3.6 5.1-.7z" fill="#e6b325"/>';
        }
        return '';
    }
}
