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
 * A library resource inside a course.
 *
 * @package    mod_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Features supported by the activity.
 *
 * @param string $feature
 * @return mixed
 */
function wwlibrary_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_ARCHETYPE => MOD_ARCHETYPE_RESOURCE,
        FEATURE_GROUPS => false,
        FEATURE_GROUPINGS => false,
        FEATURE_MOD_INTRO => false,
        FEATURE_COMPLETION_TRACKS_VIEWS => true,
        FEATURE_GRADE_HAS_GRADE => false,
        FEATURE_GRADE_OUTCOMES => false,
        FEATURE_BACKUP_MOODLE2 => false,
        FEATURE_SHOW_DESCRIPTION => false,
        FEATURE_MOD_PURPOSE => MOD_PURPOSE_CONTENT,
        default => null,
    };
}

/**
 * Add an instance.
 *
 * @param \stdClass $data
 * @param \MoodleQuickForm|null $mform
 * @return int
 */
function wwlibrary_add_instance($data, $mform = null): int {
    global $DB;

    if (!\local_wwlibrary\library::resource_allowed_in_course((int) $data->resourceid, (int) $data->course)) {
        throw new moodle_exception('cannotview', 'local_wwlibrary');
    }
    $record = (object) [
        'course' => (int) $data->course,
        'name' => trim(clean_param((string) $data->name, PARAM_TEXT)),
        'intro' => '',
        'introformat' => FORMAT_HTML,
        'resourceid' => (int) $data->resourceid,
        'timemodified' => time(),
    ];
    if ($record->name === '') {
        throw new moodle_exception('refused', 'local_wwlibrary');
    }
    return $DB->insert_record('wwlibrary', $record);
}

/**
 * Update an instance.
 *
 * @param \stdClass $data
 * @param \MoodleQuickForm|null $mform
 * @return bool
 */
function wwlibrary_update_instance($data, $mform = null): bool {
    global $DB;

    if (!\local_wwlibrary\library::resource_allowed_in_course((int) $data->resourceid, (int) $data->course)) {
        return false;
    }
    $record = (object) [
        'id' => (int) $data->instance,
        'course' => (int) $data->course,
        'name' => trim(clean_param((string) $data->name, PARAM_TEXT)),
        'intro' => '',
        'introformat' => FORMAT_HTML,
        'resourceid' => (int) $data->resourceid,
        'timemodified' => time(),
    ];
    if ($record->name === '') {
        return false;
    }
    return $DB->update_record('wwlibrary', $record);
}

/**
 * Delete an instance. The library resource stays.
 *
 * @param int $id
 * @return bool
 */
function wwlibrary_delete_instance($id): bool {
    global $DB;

    if (!$DB->record_exists('wwlibrary', ['id' => $id])) {
        return false;
    }
    $DB->delete_records('wwlibrary', ['id' => $id]);
    return true;
}

/**
 * Mark the activity viewed.
 *
 * @param \stdClass $wwlibrary
 * @param \stdClass $course
 * @param \stdClass $cm
 * @param \context $context
 */
function wwlibrary_view($wwlibrary, $course, $cm, $context): void {
    $event = \mod_wwlibrary\event\course_module_viewed::create([
        'objectid' => $wwlibrary->id,
        'context' => $context,
    ]);
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('wwlibrary', $wwlibrary);
    $event->trigger();

    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}
