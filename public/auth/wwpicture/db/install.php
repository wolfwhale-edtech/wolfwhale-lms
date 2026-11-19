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
 * Turn on email sign-in and the OAuth 2 plugin.
 *
 * PHPUnit and Behat keep the upstream auth settings.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Enable the sign-in plugins a school site uses.
 *
 * @return bool
 */
function xmldb_auth_wwpicture_install() {
    if ((defined('PHPUNIT_TEST') && PHPUNIT_TEST) || defined('BEHAT_SITE_RUNNING')) {
        return true;
    }

    \core\plugininfo\auth::enable_plugin('oauth2', 1);
    \core\plugininfo\auth::enable_plugin('wwpicture', 1);
    set_config('authloginviaemail', 1);

    return true;
}
