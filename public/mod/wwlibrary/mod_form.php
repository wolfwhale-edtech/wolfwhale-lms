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
 * Form for a library resource inside a course.
 *
 * @package    mod_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Pick a library resource for this course.
 */
class mod_wwlibrary_mod_form extends moodleform_mod {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $courseid = (int) $this->get_course()->id;

        $mform->addElement('text', 'name', get_string('name'), ['size' => '48']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $options = [0 => get_string('none', 'wwlibrary')];
        foreach (\local_wwlibrary\library::resources_for_course($courseid) as $resource) {
            $options[(int) $resource->id] = \local_wwlibrary\library::plain($resource->name);
        }
        $mform->addElement('select', 'resourceid', get_string('library', 'local_wwlibrary'), $options);
        $mform->setType('resourceid', PARAM_INT);
        $mform->setDefault('resourceid', 0);

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $courseid = (int) $this->get_course()->id;
        $resourceid = (int) ($data['resourceid'] ?? 0);
        if (!$resourceid || !\local_wwlibrary\library::resource_allowed_in_course($resourceid, $courseid)) {
            $errors['resourceid'] = get_string('refused', 'local_wwlibrary');
        }
        return $errors;
    }
}
