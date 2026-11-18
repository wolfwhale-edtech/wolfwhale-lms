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

namespace theme_wolfwhale;

/**
 * Language strings with the product name replaced.
 *
 * The upstream English files stay untouched. This class rewrites the word
 * Moodle when a string is read, before any user-supplied placeholder is filled in.
 *
 * @package    theme_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class string_manager extends \core_string_manager_standard {
    /**
     * Return a string with the product name rewritten, then placeholders filled.
     *
     * @param string $identifier The identifier of the string to search for
     * @param string $component The module the string is associated with
     * @param string|object|array $a An object, string or number that can be used within translation strings
     * @param string $lang Translation language, null means use current
     * @return string
     */
    public function get_string($identifier, $component = '', $a = null, $lang = null) {
        $string = parent::get_string($identifier, $component, null, $lang);
        $string = self::rebrand($string);
        if ($a === null) {
            return $string;
        }
        return self::substitute($string, $a);
    }

    /**
     * Replace the product name in a language string.
     *
     * Web addresses, the session cookie name, and identifiers are left as they are.
     * An identifier is a capability (moodle/role:assign), a component, or any token
     * joined to the product name by a slash, colon, underscore, or dot.
     * Placeholder tokens such as {$a->moodlerelease} are left as they are.
     *
     * @param string $value Language string template
     * @return string
     */
    public static function rebrand(string $value): string {
        if ($value === '' || stripos($value, 'moodle') === false) {
            return $value;
        }

        $protected = [];
        $value = preg_replace_callback(
            '~(?:https?://|www\.)[^\s<>"\']+|(?:[a-z0-9-]+\.)*moodle[a-z0-9-]*\.(?:org|com|net|io)\b(?:/[^\s<>"\']*)?~i',
            function(array $match) use (&$protected): string {
                $token = "\x1E" . count($protected) . "\x1F";
                $protected[$token] = $match[0];
                return $token;
            },
            $value
        );

        $value = preg_replace('/(?<![\w\/\\\\:-])moodlers(?![\w\/\\\\:-])/i', 'WolfWhale users', $value);
        // Ordinary words only. A slash, colon, underscore, or hyphen means this is a
        // capability, component, or other identifier, and it must stay unchanged.
        $value = preg_replace_callback(
            '/(?<![\w\/\\\\:-])moodle(?![\w\/\\\\:-])/i',
            function(array $match): string {
                if ($match[0] === 'MOODLE') {
                    return 'WOLFWHALE';
                }
                return 'WolfWhale';
            },
            $value
        );

        if ($protected) {
            $value = strtr($value, $protected);
        }
        return $value;
    }

    /**
     * Fill {$a} placeholders the same way the standard string manager does.
     *
     * @param string $string String template
     * @param string|object|array $a Placeholder value
     * @return string
     */
    private static function substitute(string $string, $a): string {
        if (is_array($a) || (is_object($a) && !($a instanceof \Stringable))) {
            $a = (array) $a;
            $search = [];
            $replace = [];
            foreach ($a as $key => $value) {
                if (is_int($key)) {
                    continue;
                }
                if (is_array($value) || (is_object($value) && !($value instanceof \Stringable))) {
                    continue;
                }
                $search[] = '{$a->' . $key . '}';
                $replace[] = (string) $value;
            }
            if ($search) {
                $string = str_replace($search, $replace, $string);
            }
            return $string;
        }
        return str_replace('{$a}', (string) $a, $string);
    }
}
