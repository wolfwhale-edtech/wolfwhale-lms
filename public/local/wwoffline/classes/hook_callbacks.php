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
 * Marks each signed-in response with that person, and gives a student the offline script.
 *
 * @package    local_wwoffline
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Name the signed-in person on the response so the browser cache can keep people apart.
     */
    public static function before_http_headers(): void {
        global $USER;

        if (self::skip() || !isloggedin() || isguestuser()) {
            return;
        }
        header('X-WW-User: ' . (int) $USER->id);
    }

    /**
     * Offline script, indicator, and the next lesson to fetch ahead.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $USER;

        if (self::skip() || !isloggedin() || isguestuser()) {
            return;
        }
        $userid = (int) $USER->id;
        $student = \local_wolfwhale\home::is_student($userid);
        $parent = \local_wolfwhale\parents::is_parent($userid);
        if (!$student && !$parent) {
            return;
        }

        $scope = self::scope_path();
        $sw = (new \moodle_url('/local/wwoffline/sw.php'))->out(false);
        $prefetch = [];
        if ($student && self::wants_prefetch()) {
            $prefetch = lessons::prefetch_urls($userid);
        }
        $icon = '<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false">'
            . '<path d="M1.5 8a6.5 6.5 0 0 1 10.2-5.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
            . '<path d="M14.5 8a6.5 6.5 0 0 1-10.2 5.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
            . '<path d="M3 3l10 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
            . '</svg>';

        $html = '<div id="ww-offline"'
            . ' data-user="' . (int) $userid . '"'
            . ' data-sw="' . s($sw) . '"'
            . ' data-scope="' . s($scope) . '"'
            . ' data-waiting="' . s(get_string('waiting', 'local_wwoffline')) . '"'
            . ' data-sent="' . s(get_string('sent', 'local_wwoffline')) . '">'
            . '<p class="ww-net" hidden>'
            . $icon
            . '<span class="ww-net-word">' . s(get_string('offline', 'local_wwoffline')) . '</span>'
            . '</p></div>';
        $html .= '<script type="application/json" id="ww-offline-prefetch">'
            . json_encode(array_values($prefetch), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . '</script>';
        foreach (['db.js', 'queue.js', 'client.js'] as $file) {
            $src = (new \moodle_url('/local/wwoffline/js/' . $file))->out(false);
            $html .= '<script src="' . s($src) . '" defer></script>';
        }
        $hook->add_html($html);
    }

    /**
     * Home, course, and lesson pages name the next lesson. Other pages do not.
     *
     * @return bool
     */
    private static function wants_prefetch(): bool {
        global $PAGE;

        $path = $PAGE->url->get_path();
        foreach ([
            '/local/wolfwhale/index.php',
            '/course/view.php',
            '/mod/page/view.php',
            '/mod/book/view.php',
            '/mod/resource/view.php',
        ] as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Service-worker scope for this site, including a subdirectory install.
     *
     * @return string
     */
    public static function scope_path(): string {
        global $CFG;

        $path = parse_url($CFG->wwwroot, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return '/';
        }
        return rtrim($path, '/') . '/';
    }

    /**
     * Tests, command-line scripts, and install do not inject the script.
     *
     * @return bool
     */
    private static function skip(): bool {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST) {
            return true;
        }
        if (defined('BEHAT_SITE_RUNNING')) {
            return true;
        }
        if (CLI_SCRIPT) {
            return true;
        }
        if (defined('AJAX_SCRIPT') && AJAX_SCRIPT) {
            return true;
        }
        if (defined('WS_SERVER') && WS_SERVER) {
            return true;
        }
        return during_initial_install();
    }
}
