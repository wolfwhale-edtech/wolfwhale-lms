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

namespace local_wwtreaty;

/**
 * Shared page pieces. Each tile is a word and a fact.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page {
    /**
     * A tile that opens an address.
     *
     * @param string $word
     * @param string $fact
     * @param string|null $url
     * @param bool $empty
     * @param string $status
     * @param bool $on
     * @param int|null $percent
     * @return array
     */
    public static function link(
        string $word,
        string $fact,
        ?string $url,
        bool $empty = false,
        string $status = '',
        bool $on = false,
        ?int $percent = null
    ): array {
        $tile = [
            'word' => $word,
            'fact' => $fact,
            'empty' => $empty,
            'on' => $on,
            'post' => false,
        ];
        if ($status !== '') {
            $tile['status'] = $status;
        }
        if ($url !== null) {
            $tile['url'] = $url;
        }
        if ($percent !== null) {
            $tile['bar'] = true;
            $tile['percent'] = $percent;
        }
        return $tile;
    }

    /**
     * A tile that posts one action.
     *
     * @param string $word
     * @param string $fact
     * @param string $action
     * @param array $hidden
     * @param bool $empty
     * @param string $status
     * @param bool $on
     * @return array
     */
    public static function post(
        string $word,
        string $fact,
        string $action,
        array $hidden,
        bool $empty = false,
        string $status = '',
        bool $on = false
    ): array {
        $tile = self::link($word, $fact, null, $empty, $status, $on);
        $tile['post'] = true;
        $tile['action'] = $action;
        $tile['sesskey'] = sesskey();
        $fields = [];
        foreach ($hidden as $name => $value) {
            $fields[] = ['name' => $name, 'value' => (string) $value];
        }
        $tile['hidden'] = $fields;
        return $tile;
    }

    /**
     * Print a board of tiles.
     *
     * @param string $title
     * @param \moodle_url $url
     * @param \context $context
     * @param array $data
     */
    public static function render(string $title, \moodle_url $url, \context $context, array $data): void {
        global $OUTPUT, $PAGE;

        $PAGE->set_context($context);
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('base');
        $PAGE->set_secondary_navigation(false);
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
        echo $OUTPUT->header();
        echo $OUTPUT->render_from_template('local_wwtreaty/board', $data);
        echo $OUTPUT->footer();
    }

    /**
     * A name for the screen, with no HTML and no encoded ampersand.
     *
     * @param string $value
     * @param \context|null $context
     * @return string
     */
    public static function plain(string $value, ?\context $context = null): string {
        $options = ['escape' => false, 'filter' => false];
        if ($context) {
            $options['context'] = $context;
        }
        $text = format_string($value, true, $options);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }
}
