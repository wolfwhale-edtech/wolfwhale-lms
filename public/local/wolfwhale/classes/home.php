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
 * Data for the student home.
 *
 * Every read uses the logged-in person. There is no user id argument.
 *
 * @package    local_wolfwhale
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class home {
    /** @var string[] Role archetypes that keep the normal dashboard. */
    public const STAFF_ARCHETYPES = ['manager', 'coursecreator', 'editingteacher', 'teacher'];

    /**
     * A person with no teacher, manager, course-creator, or admin role.
     *
     * @param int $userid The person to check.
     * @return bool
     */
    public static function is_student(int $userid): bool {
        global $DB;

        if ($userid <= 0 || isguestuser($userid) || is_siteadmin($userid)) {
            return false;
        }
        if (parents::is_parent($userid)) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal(self::STAFF_ARCHETYPES, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $sql = "SELECT 1
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = :userid
                   AND r.archetype {$insql}";
        return !$DB->record_exists_sql($sql, $params);
    }

    /**
     * Students leave the dashboard. Everyone else stays.
     *
     * @param \moodle_url $url Page they asked for.
     * @param int $userid The logged-in person.
     * @return bool
     */
    public static function should_redirect(\moodle_url $url, int $userid): bool {
        if (!self::is_student($userid)) {
            return false;
        }
        $dashboard = new \moodle_url('/my/index.php');
        $my = new \moodle_url('/my/');
        return $url->compare($dashboard, URL_MATCH_BASE) || $url->compare($my, URL_MATCH_BASE);
    }

    /**
     * Where a student should land after login, or null to leave the address alone.
     *
     * A link they asked for (a course, an activity) is kept. The site front page
     * and the dashboard are replaced with this home.
     *
     * @param string|null $wantsurl Address stored at login, if any.
     * @param int $userid The person who just signed in.
     * @return string|null Absolute URL, or null when the stored address should stay.
     */
    public static function login_wantsurl(?string $wantsurl, int $userid): ?string {
        if (!self::is_student($userid)) {
            return null;
        }
        if ($wantsurl === null || $wantsurl === '' || self::is_homepage_url($wantsurl)) {
            return self::url()->out(false);
        }
        return null;
    }

    /**
     * The student home address.
     *
     * @return \moodle_url
     */
    public static function url(): \moodle_url {
        return new \moodle_url('/local/wolfwhale/index.php');
    }

    /**
     * Tiles for the logged-in person, in display order.
     *
     * Badges are left out when badges are switched off. Messages are left out
     * when messaging is switched off. Points, levels, avatars, the library,
     * and maps are not built, so they are not here.
     *
     * @return array[]
     */
    public static function get_tiles(): array {
        $events = self::upcoming_events();
        $tiles = [
            self::today_tile($events),
            self::due_tile($events),
        ];
        $badges = self::badges_tile();
        if ($badges !== null) {
            $tiles[] = $badges;
        }
        $messages = self::messages_tile();
        if ($messages !== null) {
            $tiles[] = $messages;
        }
        $tiles[] = self::courses_tile();
        return $tiles;
    }

    /**
     * Template data: the tiles, plus one line that reads the whole screen.
     *
     * @return array
     */
    public static function export(): array {
        $tiles = self::get_tiles();
        $speech = [];
        foreach ($tiles as $tile) {
            $speech[] = $tile['speech'];
        }
        return [
            'tiles' => $tiles,
            'screenspeech' => implode(' ', $speech),
            'read' => get_string('read', 'local_wolfwhale'),
        ];
    }

    /**
     * The next activity from the calendar action events.
     *
     * @param \stdClass[] $events Upcoming action events for the logged-in person.
     * @return array
     */
    private static function today_tile(array $events): array {
        $word = get_string('today', 'local_wolfwhale');
        $next = self::next_activity($events);
        if ($next === null) {
            $url = (new \moodle_url('/calendar/view.php', ['view' => 'day']))->out(false);
            return self::tile('today', $word, get_string('nothingtoday', 'local_wolfwhale'), true, $url);
        }
        return self::tile('today', $word, self::event_label($next), false, self::event_url($next));
    }

    /**
     * Upcoming assignments and quizzes.
     *
     * @param \stdClass[] $events Upcoming action events for the logged-in person.
     * @return array
     */
    private static function due_tile(array $events): array {
        $word = get_string('due', 'local_wolfwhale');
        $due = self::due_events($events);
        $count = count($due);
        if ($count === 0) {
            $url = (new \moodle_url('/calendar/view.php', ['view' => 'upcoming']))->out(false);
            return self::tile('due', $word, get_string('nothingdue', 'local_wolfwhale'), true, $url);
        }
        if ($count === 1) {
            $url = self::event_url(reset($due));
        } else {
            $url = (new \moodle_url('/calendar/view.php', ['view' => 'upcoming']))->out(false);
        }
        return self::tile('due', $word, (string) $count, false, $url);
    }

    /**
     * Earned badges, or null when badges are switched off.
     *
     * @return array|null
     */
    private static function badges_tile(): ?array {
        global $CFG, $USER;

        if (empty($CFG->enablebadges)) {
            return null;
        }
        require_once($CFG->libdir . '/badgeslib.php');

        $badges = badges_get_user_badges($USER->id);
        $word = get_string('badges', 'local_wolfwhale');
        $url = (new \moodle_url('/badges/mybadges.php'))->out(false);
        $count = count($badges);
        if ($count === 0) {
            return self::tile('badges', $word, get_string('none', 'local_wolfwhale'), true, $url);
        }
        if ($count === 1) {
            $badge = reset($badges);
            return self::tile('badges', $word, self::plain_name($badge->name), false, $url);
        }
        return self::tile('badges', $word, (string) $count, false, $url);
    }

    /**
     * Unread conversations, or null when messaging is switched off.
     *
     * @return array|null
     */
    private static function messages_tile(): ?array {
        global $CFG;

        if (empty($CFG->messaging)) {
            return null;
        }
        $count = \core_message\api::count_unread_conversations();
        $word = get_string('messages', 'local_wolfwhale');
        $url = (new \moodle_url('/message/index.php'))->out(false);
        if ($count < 1) {
            return self::tile('messages', $word, get_string('none', 'local_wolfwhale'), true, $url);
        }
        return self::tile('messages', $word, (string) $count, false, $url);
    }

    /**
     * Enrolled courses for the logged-in person.
     *
     * @return array
     */
    private static function courses_tile(): array {
        $courses = enrol_get_my_courses(null, 'fullname ASC');
        $word = get_string('courses', 'local_wolfwhale');
        $count = count($courses);
        if ($count === 0) {
            $url = (new \moodle_url('/my/courses.php'))->out(false);
            return self::tile('courses', $word, get_string('none', 'local_wolfwhale'), true, $url);
        }
        if ($count === 1) {
            $course = reset($courses);
            $url = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
            return self::tile('courses', $word, self::plain_name($course->fullname), false, $url);
        }
        $url = (new \moodle_url('/my/courses.php'))->out(false);
        return self::tile('courses', $word, (string) $count, false, $url);
    }

    /**
     * One tile.
     *
     * @param string $id Tile id.
     * @param string $word The one word on the tile.
     * @param string $fact The real fact, or a short empty line.
     * @param bool $empty True when there is nothing to show.
     * @param string $url Where one tap goes.
     * @return array
     */
    private static function tile(string $id, string $word, string $fact, bool $empty, string $url): array {
        $speech = get_string('speech', 'local_wolfwhale', (object) ['word' => $word, 'fact' => $fact]);
        return [
            'id' => $id,
            'word' => $word,
            'fact' => $fact,
            'empty' => $empty,
            'url' => $url,
            'speech' => $speech,
            'istoday' => $id === 'today',
            'isdue' => $id === 'due',
            'isbadges' => $id === 'badges',
            'ismessages' => $id === 'messages',
            'iscourses' => $id === 'courses',
        ];
    }

    /**
     * Upcoming action events for the logged-in person.
     *
     * Uses Moodle's calendar external API, which refuses another person's events
     * unless the caller can manage that person's calendar.
     *
     * @return \stdClass[]
     */
    private static function upcoming_events(): array {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/externallib.php');

        $events = [];
        $after = 0;
        $from = time();
        for ($page = 0; $page < 10; $page++) {
            $result = \core_calendar_external::get_calendar_action_events_by_timesort(
                $from,
                null,
                $after,
                50,
                true
            );
            if (is_array($result)) {
                $batch = $result['events'] ?? [];
                $lastid = $result['lastid'] ?? 0;
            } else {
                $batch = $result->events ?? [];
                $lastid = $result->lastid ?? 0;
            }
            if (!$batch) {
                break;
            }
            foreach ($batch as $event) {
                $events[] = self::event_object($event);
            }
            $lastid = (int) $lastid;
            if (count($batch) < 50 || $lastid <= 0 || $lastid === $after) {
                break;
            }
            $after = $lastid;
        }
        return $events;
    }

    /**
     * The soonest upcoming activity that has a name and an address.
     *
     * @param \stdClass[] $events
     * @return \stdClass|null
     */
    private static function next_activity(array $events): ?\stdClass {
        foreach ($events as $event) {
            if (self::event_url($event) !== '' && self::event_label($event) !== '') {
                return $event;
            }
        }
        return null;
    }

    /**
     * Assignments and quizzes, one each, in the order the calendar returned them.
     *
     * @param \stdClass[] $events
     * @return \stdClass[]
     */
    private static function due_events(array $events): array {
        $seen = [];
        $due = [];
        foreach ($events as $event) {
            $module = (string) ($event->modulename ?? '');
            if ($module !== 'assign' && $module !== 'quiz') {
                continue;
            }
            $key = $module . ':' . (string) ($event->instance ?? $event->id ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $due[] = $event;
        }
        return $due;
    }

    /**
     * Activity name, or the event name when the activity name is missing.
     *
     * @param \stdClass $event
     * @return string
     */
    private static function event_label(\stdClass $event): string {
        $label = trim((string) ($event->activityname ?? ''));
        if ($label === '') {
            $label = trim((string) ($event->name ?? ''));
        }
        return self::plain_name($label);
    }

    /**
     * A name for a tile and for speech, with no HTML and no encoded ampersand.
     *
     * The template escapes this again when it is drawn.
     *
     * @param string $value Stored name.
     * @return string
     */
    private static function plain_name(string $value): string {
        $text = format_string($value, true, ['escape' => false, 'filter' => false]);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }

    /**
     * Address of the activity.
     *
     * @param \stdClass $event
     * @return string
     */
    private static function event_url(\stdClass $event): string {
        $url = $event->url ?? '';
        if ($url instanceof \moodle_url) {
            return $url->out(false);
        }
        return (string) $url;
    }

    /**
     * Normalise one exported calendar event.
     *
     * @param mixed $event
     * @return \stdClass
     */
    private static function event_object(mixed $event): \stdClass {
        $event = (object) (array) $event;
        if (isset($event->action) && is_array($event->action)) {
            $event->action = (object) $event->action;
        }
        return $event;
    }

    /**
     * True when the address is the site front page or the dashboard.
     *
     * @param string $wantsurl
     * @return bool
     */
    private static function is_homepage_url(string $wantsurl): bool {
        $path = self::path_inside_wwwroot($wantsurl);
        return in_array($path, ['/', '/index.php', '/my', '/my/index.php'], true);
    }

    /**
     * Path of a site address, without the wwwroot directory.
     *
     * @param string $url
     * @return string
     */
    private static function path_inside_wwwroot(string $url): string {
        global $CFG;

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }
        $root = parse_url($CFG->wwwroot, PHP_URL_PATH);
        if (is_string($root) && $root !== '' && $root !== '/' && str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }
        if ($path === '' || $path === '/') {
            return '/';
        }
        return rtrim($path, '/');
    }
}
