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

namespace auth_wwpicture;

/**
 * Google and Microsoft buttons. Each one is shown only when that issuer is configured and enabled.
 *
 * @package    auth_wwpicture
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class buttons {
    /**
     * Login buttons for the configured issuers.
     *
     * @return array<string, array{url: string}>
     */
    public static function oauth(): array {
        if (!is_enabled_auth('oauth2')) {
            return [];
        }
        $out = [];
        foreach (\core\oauth2\api::get_all_issuers(true) as $issuer) {
            $type = (string) $issuer->get('servicetype');
            if (($type !== 'google' && $type !== 'microsoft') || isset($out[$type])) {
                continue;
            }
            if (!$issuer->is_available_for_login()) {
                continue;
            }
            $out[$type] = [
                'url' => (new \moodle_url('/auth/oauth2/login.php', [
                    'id' => $issuer->get('id'),
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }
        return $out;
    }
}
