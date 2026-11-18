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

namespace theme_wolfwhale\output;

/**
 * WolfWhale renderer. Boost supplies the layout; this class only removes upstream branding.
 *
 * @package    theme_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_renderer extends \theme_boost\output\core_renderer {
    /**
     * Home link without the upstream product logo.
     *
     * @return string HTML fragment
     */
    public function home_link() {
        global $CFG, $SITE;

        $duringinstall = !empty($CFG->target_release) && $CFG->target_release != $CFG->release;
        if ($this->page->pagetype == 'site-index' || $duringinstall) {
            return '<div class="homelink"><a href="' . $CFG->wwwroot . '/">' . get_string('home') . '</a></div>';
        }
        if ($this->page->course->id == $SITE->id || strpos($this->page->pagetype, 'course-view') === 0) {
            return '<div class="homelink"><a href="' . $CFG->wwwroot . '/">' . get_string('home') . '</a></div>';
        }
        $name = format_string($this->page->course->shortname, true, ['context' => $this->page->context]);
        return '<div class="homelink"><a href="' . $CFG->wwwroot . '/course/view.php?id=' . $this->page->course->id . '">' .
            $name . '</a></div>';
    }

    /**
     * No services link. The upstream link points at moodle.com.
     *
     * @return string
     */
    public function services_support_link(): string {
        return '';
    }
}
