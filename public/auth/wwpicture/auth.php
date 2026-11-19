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
 * Picture sign-in. The password box does not accept a picture code.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/authlib.php');

/**
 * Picture codes are checked on the class page, then a real session is opened.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_plugin_wwpicture extends auth_plugin_base {

    /**
     * Constructor.
     */
    public function __construct() {
        $this->authtype = 'wwpicture';
        $this->config = get_config('auth_wwpicture');
    }

    /**
     * The email form never accepts a picture code.
     *
     * @param string $username
     * @param string $password
     * @return bool
     */
    public function user_login($username, $password) {
        return false;
    }

    /**
     * Picture codes live in this plugin, not in the user password column.
     *
     * @return bool
     */
    public function is_internal() {
        return false;
    }

    /**
     * There is no password to change here.
     *
     * @return bool
     */
    public function can_change_password() {
        return false;
    }

    /**
     * There is no password to reset here.
     *
     * @return bool
     */
    public function can_reset_password() {
        return false;
    }

    /**
     * Accounts stay on their own sign-in method. This plugin only adds the picture code.
     *
     * @return bool
     */
    public function can_be_manually_set() {
        return false;
    }
}
