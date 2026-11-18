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
 * WolfWhale theme callbacks.
 *
 * @package    theme_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * SCSS variables inserted before Boost's preset.
 *
 * @param theme_config $theme The theme config object.
 * @return string
 */
function theme_wolfwhale_get_pre_scss($theme) {
    return file_get_contents(__DIR__ . '/scss/pre.scss');
}

/**
 * SCSS appended after Boost, including the login photo override.
 *
 * @param theme_config $theme The theme config object.
 * @return string
 */
function theme_wolfwhale_get_extra_scss($theme) {
    return file_get_contents(__DIR__ . '/scss/post.scss');
}
