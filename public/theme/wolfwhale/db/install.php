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

/**
 * Install and first-upgrade steps for the WolfWhale theme.
 *
 * @package    theme_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Set WolfWhale as the site theme, and fill a blank site name.
 *
 * PHPUnit and Behat keep Boost. Their fixtures assume that theme.
 *
 * @return bool
 */
function xmldb_theme_wolfwhale_install() {
    global $DB;

    if ((defined('PHPUNIT_TEST') && PHPUNIT_TEST) || defined('BEHAT_SITE_RUNNING')) {
        return true;
    }

    set_config('theme', 'wolfwhale');

    $site = $DB->get_record('course', ['format' => 'site'], 'id, fullname, shortname');
    if ($site) {
        $blank = ['', 'moodle', 'moodle site', 'new site'];
        if (in_array(strtolower(trim((string) $site->fullname)), $blank, true)) {
            $DB->set_field('course', 'fullname', 'WolfWhale LMS', ['id' => $site->id]);
        }
        if (in_array(strtolower(trim((string) $site->shortname)), $blank, true)) {
            $DB->set_field('course', 'shortname', 'WOLFWHALE', ['id' => $site->id]);
        }
    }

    if (function_exists('theme_reset_all_caches')) {
        theme_reset_all_caches();
    }

    return true;
}
