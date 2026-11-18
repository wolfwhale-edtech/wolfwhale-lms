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

namespace local_wolfwhale;

/**
 * Sends a student to the student home.
 *
 * The automated suites are left alone. They still open the normal dashboard.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Leave the dashboard when the person is a student.
     */
    public static function before_http_headers(): void {
        global $PAGE, $USER;

        if (self::skip() || !isloggedin() || isguestuser()) {
            return;
        }
        if (!home::should_redirect($PAGE->url, (int) $USER->id)) {
            return;
        }
        redirect(home::url());
    }

    /**
     * After login, send a student home unless they asked for a particular page.
     */
    public static function after_login_completed(): void {
        global $SESSION, $USER;

        if (self::skip() || !isloggedin() || isguestuser()) {
            return;
        }
        $current = $SESSION->wantsurl ?? null;
        if (!is_string($current) || $current === '') {
            $current = null;
        }
        $replacement = home::login_wantsurl($current, (int) $USER->id);
        if ($replacement !== null) {
            $SESSION->wantsurl = $replacement;
        }
    }

    /**
     * The Dashboard link in the bar opens this home for a student.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        global $USER;

        if (self::skip() || !isloggedin() || isguestuser() || !home::is_student((int) $USER->id)) {
            return;
        }
        $node = $hook->get_primaryview()->find('myhome', \navigation_node::TYPE_SETTING);
        if (!$node) {
            $node = $hook->get_primaryview()->find('myhome', null);
        }
        if (!$node) {
            return;
        }
        $node->action = home::url();
        $node->text = get_string('home', 'local_wolfwhale');
    }

    /**
     * The test suites and command-line scripts keep the normal pages.
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
        return during_initial_install();
    }
}
