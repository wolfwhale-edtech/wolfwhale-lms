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
 * Nine generic pictures. No cultural symbols and no language words inside the drawing.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pictures {
    /** @var string[] */
    public const KEYS = ['sun', 'tree', 'fish', 'star', 'moon', 'flower', 'house', 'bird', 'cloud'];

    /**
     * One picture, or an empty string when the key is unknown.
     *
     * @param string $key
     * @return string
     */
    public static function svg(string $key): string {
        $body = self::bodies()[$key] ?? '';
        if ($body === '') {
            return '';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80" aria-hidden="true" focusable="false">' .
            $body . '</svg>';
    }

    /**
     * The spoken name of one picture.
     *
     * @param string $key
     * @return string
     */
    public static function word(string $key): string {
        if (!in_array($key, self::KEYS, true)) {
            return '';
        }
        return get_string($key, 'auth_wwpicture');
    }

    /**
     * @return array<string, string>
     */
    private static function bodies(): array {
        return [
            'sun' => '<circle cx="40" cy="40" r="12" fill="#e6b325"/>' .
                '<g stroke="#e6b325" stroke-width="3" stroke-linecap="round">' .
                '<path d="M40 8v10M40 62v10M8 40h10M62 40h10M16 16l8 8M56 56l8 8M64 16l-8 8M24 56l-8 8"/>' .
                '</g>',
            'tree' => '<rect x="36" y="48" width="8" height="20" rx="2" fill="#8d5524"/>' .
                '<polygon points="40,10 66,50 14,50" fill="#149a9a"/>',
            'fish' => '<ellipse cx="34" cy="40" rx="18" ry="12" fill="#2f5d8a"/>' .
                '<polygon points="50,40 70,24 70,56" fill="#2f5d8a"/>' .
                '<circle cx="24" cy="37" r="2" fill="#fff"/>',
            'star' => '<polygon points="40,8 48,30 72,30 52,44 60,68 40,54 20,68 28,44 8,30 32,30" fill="#e6b325"/>',
            'moon' => '<circle cx="34" cy="40" r="16" fill="#e6b325"/>' .
                '<circle cx="44" cy="34" r="13" fill="#ffffff"/>',
            'flower' => '<circle cx="40" cy="22" r="8" fill="#d64545"/>' .
                '<circle cx="22" cy="40" r="8" fill="#d64545"/>' .
                '<circle cx="58" cy="40" r="8" fill="#d64545"/>' .
                '<circle cx="28" cy="58" r="8" fill="#d64545"/>' .
                '<circle cx="52" cy="58" r="8" fill="#d64545"/>' .
                '<circle cx="40" cy="40" r="7" fill="#e6b325"/>',
            'house' => '<polygon points="40,12 70,40 10,40" fill="#d64545"/>' .
                '<rect x="20" y="40" width="40" height="28" fill="#2f5d8a"/>' .
                '<rect x="34" y="50" width="12" height="18" fill="#e7f6ff"/>',
            'bird' => '<path d="M8 44c14-16 26-16 34 0-10-6-18-6-34 0z" fill="#2f5d8a"/>' .
                '<path d="M40 44c14-18 26-8 34 2-16-2-24 0-34-2z" fill="#149a9a"/>' .
                '<circle cx="66" cy="42" r="2" fill="#16324a"/>',
            'cloud' => '<ellipse cx="30" cy="44" rx="14" ry="10" fill="#ffffff" stroke="#7ec8e8" stroke-width="3"/>' .
                '<ellipse cx="48" cy="38" rx="16" ry="12" fill="#ffffff" stroke="#7ec8e8" stroke-width="3"/>' .
                '<ellipse cx="60" cy="48" rx="12" ry="8" fill="#ffffff" stroke="#7ec8e8" stroke-width="3"/>',
        ];
    }
}
