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

namespace local_wwtreaty;

/**
 * Treaty Education outcomes for one school.
 *
 * Nothing here is preloaded. A school imports the wording it uses.
 * The same code in a later import updates that row.
 *
 * CSV columns, in order, with a header row:
 * code, grade, theme, title, description, active.
 * code and title are required. active is 1 or 0. A missing active column means active.
 *
 * JSON is a list of objects with those keys, or one object.
 *
 * @package    local_wwtreaty
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcome {
    /** @var string Header row for a blank CSV import. */
    public const CSV_HEADER = "code,grade,theme,title,description,active\n";

    /** @var string Blank JSON import showing the keys and no outcome wording. */
    public const JSON_TEMPLATE = "[\n  {\n    \"code\": \"\",\n    \"grade\": \"\",\n    \"theme\": \"\",\n"
        . "    \"title\": \"\",\n    \"description\": \"\",\n    \"active\": 1\n  }\n]\n";

    /** @var int Longest stored description. */
    private const DESCRIPTION_MAX = 4000;

    /**
     * Active outcomes in these schools.
     *
     * @param int[] $categoryids
     * @return int
     */
    public static function active_count(array $categoryids): int {
        global $DB;

        if (!$categoryids) {
            return 0;
        }
        [$sql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);
        $params['active'] = 1;
        return $DB->count_records_select('local_wwtreaty', "categoryid {$sql} AND active = :active", $params);
    }

    /**
     * Outcomes for one school, code order.
     *
     * The caller has already checked that this person may see the school.
     *
     * @param int $categoryid
     * @param bool $activeonly
     * @return \stdClass[]
     */
    public static function records(int $categoryid, bool $activeonly): array {
        global $DB;

        $params = ['categoryid' => school::root_id($categoryid)];
        $sql = 'categoryid = :categoryid';
        if ($activeonly) {
            $sql .= ' AND active = :active';
            $params['active'] = 1;
        }
        return $DB->get_records_select('local_wwtreaty', $sql, $params, 'code ASC, id ASC');
    }

    /**
     * One outcome in a school this person may manage.
     *
     * @param int $id
     * @param int $categoryid
     * @return \stdClass
     */
    public static function get_for(int $id, int $categoryid): \stdClass {
        global $DB;

        $categoryid = school::root_id($categoryid);
        school::require_manage($categoryid);
        $row = $DB->get_record('local_wwtreaty', ['id' => $id, 'categoryid' => $categoryid], '*', IGNORE_MISSING);
        if (!$row) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        return $row;
    }

    /**
     * Add or update one outcome.
     *
     * Editing keeps the archived flag. A new row is active.
     *
     * @param int $categoryid
     * @param array $data
     * @param int $id
     * @return int
     */
    public static function save(int $categoryid, array $data, int $id = 0): int {
        global $DB;

        $categoryid = school::root_id($categoryid);
        school::require_manage($categoryid);
        $row = self::normalize($data);
        if ($id) {
            $current = $DB->get_record('local_wwtreaty', ['id' => $id, 'categoryid' => $categoryid], '*', IGNORE_MISSING);
            if (!$current) {
                throw new \moodle_exception('invalidrecord', 'error');
            }
            $row['active'] = (int) $current->active;
        } else {
            $row['active'] = 1;
        }
        return self::upsert($categoryid, $row, $id);
    }

    /**
     * Archive or restore one outcome. Tags stay. Coverage skips an archived row.
     *
     * @param int $id
     * @param bool $active
     */
    public static function set_active(int $id, bool $active): void {
        global $DB, $USER;

        $row = $DB->get_record('local_wwtreaty', ['id' => $id], '*', IGNORE_MISSING);
        if (!$row) {
            throw new \moodle_exception('invalidrecord', 'error');
        }
        school::require_manage((int) $row->categoryid);
        $row->active = $active ? 1 : 0;
        $row->usermodified = (int) $USER->id;
        $row->timemodified = time();
        $DB->update_record('local_wwtreaty', $row);
    }

    /**
     * Import a CSV or JSON file. A bad row writes nothing.
     *
     * @param int $categoryid
     * @param string $content
     * @return int How many rows were saved.
     */
    public static function import(int $categoryid, string $content): int {
        global $DB;

        $categoryid = school::root_id($categoryid);
        school::require_manage($categoryid);
        $rows = self::rows_from($content);
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($rows as $row) {
                self::upsert($categoryid, $row, 0);
            }
            $transaction->allow_commit();
        } catch (\Throwable $error) {
            $transaction->rollback($error);
        }
        return count($rows);
    }

    /**
     * Read every row before anything is written.
     *
     * @param string $content
     * @return array[]
     */
    private static function rows_from(string $content): array {
        if (strlen($content) > 2000000) {
            self::bad('File');
        }
        $trim = ltrim($content, "\xEF\xBB\xBF \t\r\n");
        if ($trim === '') {
            self::bad('File');
        }
        if ($trim[0] === '[' || $trim[0] === '{') {
            $rows = self::rows_from_json($trim);
        } else {
            $rows = self::rows_from_csv($content);
        }
        if (!$rows) {
            self::bad('File');
        }
        if (count($rows) > 5000) {
            self::bad('File');
        }
        $seen = [];
        foreach ($rows as $row) {
            if (isset($seen[$row['code']])) {
                self::bad($row['code']);
            }
            $seen[$row['code']] = true;
        }
        return $rows;
    }

    /**
     * @param string $content
     * @return array[]
     */
    private static function rows_from_json(string $content): array {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            self::bad('JSON');
        }
        if ($data === []) {
            self::bad('File');
        }
        if (!array_is_list($data)) {
            $data = [$data];
        }
        $rows = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                self::bad('JSON');
            }
            $rows[] = self::normalize($item);
        }
        return $rows;
    }

    /**
     * @param string $content
     * @return array[]
     */
    private static function rows_from_csv(string $content): array {
        global $CFG;

        require_once($CFG->libdir . '/csvlib.class.php');
        $iid = \csv_import_reader::get_new_iid('local_wwtreaty');
        $reader = new \csv_import_reader($iid, 'local_wwtreaty');
        $loaded = $reader->load_csv_content($content, 'utf-8', 'comma');
        if ($loaded === false) {
            $reader->cleanup(true);
            self::bad('CSV');
        }
        $columns = array_map(static fn($column) => \core_text::strtolower(trim((string) $column)), $reader->get_columns());
        if (!in_array('code', $columns, true)) {
            $reader->cleanup(true);
            self::bad('Code');
        }
        if (!in_array('title', $columns, true)) {
            $reader->cleanup(true);
            self::bad('Title');
        }
        $reader->init();
        try {
            $rows = [];
            while ($line = $reader->next()) {
                $raw = [];
                foreach ($columns as $index => $column) {
                    $raw[$column] = $line[$index] ?? '';
                }
                $rows[] = self::normalize($raw);
            }
            return $rows;
        } finally {
            $reader->close();
            $reader->cleanup(true);
        }
    }

    /**
     * @param array $data
     * @return array
     */
    private static function normalize(array $data): array {
        $active = self::active_flag($data['active'] ?? '');
        if ($active === null) {
            self::bad('Active');
        }
        return [
            'code' => self::line((string) ($data['code'] ?? ''), 64, 'Code', true),
            'grade' => self::line((string) ($data['grade'] ?? ''), 64, 'Grade', false),
            'theme' => self::line((string) ($data['theme'] ?? ''), 255, 'Theme', false),
            'title' => self::line((string) ($data['title'] ?? ''), 255, 'Title', true),
            'description' => self::description((string) ($data['description'] ?? '')),
            'active' => $active,
        ];
    }

    /**
     * @param string $value
     * @param int $max
     * @param string $label
     * @param bool $required
     * @return string
     */
    private static function line(string $value, int $max, string $label, bool $required): string {
        $value = trim(strip_tags(str_replace(["\r", "\n"], ' ', $value)));
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::bad($label);
        }
        return $value;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function description(string $value): string {
        $value = trim(strip_tags(str_replace("\r\n", "\n", str_replace("\r", "\n", $value))));
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) || \core_text::strlen($value) > self::DESCRIPTION_MAX) {
            self::bad('Description');
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private static function active_flag($value): ?int {
        if ($value === null || $value === '') {
            return 1;
        }
        if ($value === true || $value === 1) {
            return 1;
        }
        if ($value === false || $value === 0) {
            return 0;
        }
        $text = \core_text::strtolower(trim((string) $value));
        if (in_array($text, ['1', 'true', 'yes', 'active'], true)) {
            return 1;
        }
        if (in_array($text, ['0', 'false', 'no', 'archived'], true)) {
            return 0;
        }
        return null;
    }

    /**
     * @param int $categoryid
     * @param array $row
     * @param int $id
     * @return int
     */
    private static function upsert(int $categoryid, array $row, int $id): int {
        global $DB, $USER;

        $existing = null;
        if ($id) {
            $existing = $DB->get_record('local_wwtreaty', ['id' => $id, 'categoryid' => $categoryid], '*', IGNORE_MISSING);
            if (!$existing) {
                throw new \moodle_exception('invalidrecord', 'error');
            }
        } else {
            $existing = $DB->get_record('local_wwtreaty', ['categoryid' => $categoryid, 'code' => $row['code']]);
        }
        if ($existing && $existing->code !== $row['code']) {
            $clash = $DB->get_record('local_wwtreaty', ['categoryid' => $categoryid, 'code' => $row['code']]);
            if ($clash && (int) $clash->id !== (int) $existing->id) {
                self::bad($row['code']);
            }
        }
        $now = time();
        $record = (object) [
            'categoryid' => $categoryid,
            'code' => $row['code'],
            'grade' => $row['grade'],
            'theme' => $row['theme'],
            'title' => $row['title'],
            'description' => $row['description'],
            'active' => (int) $row['active'],
            'usermodified' => (int) ($USER->id ?? 0),
            'timemodified' => $now,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_wwtreaty', $record);
            return (int) $existing->id;
        }
        $record->timecreated = $now;
        return (int) $DB->insert_record('local_wwtreaty', $record);
    }

    /**
     * @param string $label
     * @return never
     */
    private static function bad(string $label): void {
        throw new \moodle_exception('invalidimport', 'local_wwtreaty', '', $label);
    }
}
