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

namespace local_wwupdates;

/**
 * Send one update on the channels a parent left on.
 *
 * The same parent, child, kind, and source are sent once.
 * Text is skipped when no SMS gateway is enabled.
 *
 * @package    local_wwupdates
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deliver {
    /**
     * Send unless this exact update was already sent.
     *
     * @param int $parentid
     * @param int $childid
     * @param string $kind
     * @param string $sourcekey
     * @param string $school
     * @param string $child
     * @param string[] $lines
     * @return bool True when this call sent it.
     */
    public static function send(
        int $parentid,
        int $childid,
        string $kind,
        string $sourcekey,
        string $school,
        string $child,
        array $lines
    ): bool {
        global $DB;

        if ($school === '' || $child === '' || !audience::linked($parentid, $childid)) {
            return false;
        }
        if (!preferences::on($parentid, $kind)) {
            return false;
        }
        $sourcekey = \core_text::substr($sourcekey, 0, 255);
        $match = [
            'parentid' => $parentid,
            'childid' => $childid,
            'kind' => $kind,
            'sourcekey' => $sourcekey,
        ];
        if ($DB->record_exists('local_wwupdates_sent', $match)) {
            return false;
        }
        try {
            $DB->insert_record('local_wwupdates_sent', (object) ($match + ['timecreated' => time()]));
        } catch (\dml_exception $error) {
            if ($DB->record_exists('local_wwupdates_sent', $match)) {
                return false;
            }
            throw $error;
        }

        $body = self::body($school, $child, $lines);
        self::app_and_email($parentid, $childid, $kind, $school, $child, $body);
        self::text($parentid, $kind, $body);
        return true;
    }

    /**
     * School, the child's first name, then the short lines.
     *
     * @param string $school
     * @param string $child
     * @param string[] $lines
     * @return string
     */
    public static function body(string $school, string $child, array $lines): string {
        $kept = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $kept[] = $line;
            }
        }
        if (!$kept) {
            $kept[] = get_string('nothingnew', 'local_wwupdates');
        }
        return $school . "\n" . $child . "\n" . implode("\n", array_slice($kept, 0, 8));
    }

    /**
     * In-app notification and email, for the channels that are on.
     *
     * @param int $parentid
     * @param int $childid
     * @param string $kind
     * @param string $school
     * @param string $child
     * @param string $body
     */
    private static function app_and_email(
        int $parentid,
        int $childid,
        string $kind,
        string $school,
        string $child,
        string $body
    ): void {
        $processors = [];
        if (preferences::on($parentid, 'app')) {
            $processors[] = 'popup';
        }
        if (preferences::on($parentid, 'email')) {
            $processors[] = 'email';
        }
        if (!$processors) {
            return;
        }
        set_user_preference(
            'message_provider_local_wwupdates_' . $kind . '_enabled',
            implode(',', $processors),
            $parentid
        );
        $message = new \core\message\message();
        $message->component = 'local_wwupdates';
        $message->name = $kind;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $parentid;
        $message->subject = $school . '. ' . $child;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>' . str_replace("\n", "<br>\n", s($body)) . '</p>';
        $message->smallmessage = $body;
        $message->fullmessagesms = $body;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/local/wolfwhale/parent.php', ['child' => $childid]))->out(false);
        $message->contexturlname = $child;
        message_send($message);
    }

    /**
     * Text through the core SMS manager. Nothing is queued when no gateway is on.
     *
     * @param int $parentid
     * @param string $kind
     * @param string $body
     */
    private static function text(int $parentid, string $kind, string $body): void {
        if (!preferences::on($parentid, 'text')) {
            return;
        }
        $number = preferences::phone($parentid);
        if ($number === '') {
            return;
        }
        $manager = \core\di::get(\core_sms\manager::class);
        if (!$manager->get_enabled_gateway_instances()) {
            return;
        }
        try {
            $manager->send(
                recipientnumber: $number,
                content: $body,
                component: 'local_wwupdates',
                messagetype: $kind,
                recipientuserid: $parentid,
                issensitive: false,
                async: true,
            );
        } catch (\Throwable $error) {
            if (PHPUNIT_TEST) {
                throw $error;
            }
            debugging($error->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
