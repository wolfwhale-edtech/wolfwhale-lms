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
 * Admin settings for levels, point amounts, and locked avatar parts.
 *
 * @package    local_wwpoints
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_wwpoints', get_string('pluginname', 'local_wwpoints'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtextarea(
        'local_wwpoints/levels',
        get_string('levels', 'local_wwpoints'),
        '',
        \local_wwpoints\levels::DEFAULT_TABLE,
        PARAM_RAW,
        40,
        8
    ));
    $settings->add(new admin_setting_configtext(
        'local_wwpoints/points_assign',
        get_string('pointsassign', 'local_wwpoints'),
        '',
        10,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_wwpoints/points_completion',
        get_string('pointscompletion', 'local_wwpoints'),
        '',
        5,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_wwpoints/points_present',
        get_string('pointspresent', 'local_wwpoints'),
        '',
        5,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_wwpoints/points_badge',
        get_string('pointsbadge', 'local_wwpoints'),
        '',
        15,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_wwpoints/unlocks',
        get_string('unlocks', 'local_wwpoints'),
        '',
        \local_wwpoints\avatar::DEFAULT_UNLOCKS,
        PARAM_RAW,
        40,
        8
    ));
}
