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

namespace local_wwattendance\event;

/**
 * A student was marked absent.
 *
 * @package    local_wwattendance
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance_missed extends \core\event\base {
    /**
     * Set the basic event properties.
     */
    protected function init() {
        $this->data['objecttable'] = 'local_wwattendance_log';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    /**
     * The name shown in the log.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('absent', 'local_wwattendance');
    }

    /**
     * Description for the log.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->relateduserid' was marked absent in the course with id '$this->courseid'.";
    }

    /**
     * The attendance page for this course.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/wwattendance/take.php', ['courseid' => $this->courseid]);
    }

    /**
     * The student and the session are required.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        if (empty($this->other['sessionid'])) {
            throw new \coding_exception('The \'sessionid\' value must be set in other.');
        }
    }
}
