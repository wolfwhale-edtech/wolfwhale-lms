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

namespace local_wwlibrary;

/**
 * Files for one library resource, stored with the file API.
 *
 * A world is one .mcworld file. A model is one .glb or .gltf file, or a
 * package. A map or an interactive model can be one HTML file or a zip
 * package whose entry point is index.html. Packages are checked before
 * anything is stored: a path that climbs out of the package, or a file
 * the browser would run as a program, is refused.
 *
 * @package    local_wwlibrary
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class storage {
    /** @var int Largest accepted upload, in bytes. */
    public const MAX_BYTES = 67108864;

    /** @var int Most files a package may contain. */
    public const MAX_FILES = 200;

    /** @var string[] Extensions a package may contain. */
    private const PACKAGE_EXT = [
        'html', 'htm', 'js', 'mjs', 'css', 'json', 'map', 'txt', 'md',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico',
        'woff', 'woff2', 'ttf',
        'mp3', 'mp4', 'webm', 'wasm',
        'glb', 'gltf', 'bin',
    ];

    /**
     * System context. One library file area, permission checked on the resource.
     *
     * @return \context_system
     */
    public static function context(): \context_system {
        return \context_system::instance();
    }

    /**
     * Refuse an upload that cannot be stored. Nothing is written.
     *
     * @param string $kind
     * @param string $path
     * @param string $filename
     */
    public static function validate(string $kind, string $path, string $filename): void {
        self::describe($kind, $path, $filename);
    }

    /**
     * Replace the files for one resource with a single upload.
     *
     * @param int $resourceid
     * @param int $userid Person who sent the file.
     * @param string $kind map, world, or model.
     * @param string $path Local path of the upload.
     * @param string $filename Name the person gave the file.
     * @return array{source: string, mainfile: string}
     */
    public static function ingest(int $resourceid, int $userid, string $kind, string $path, string $filename): array {
        $plan = self::describe($kind, $path, $filename);
        if ($plan['source'] === 'package' && $plan['entries'] !== []) {
            return self::write_zip($resourceid, $userid, $path, $plan);
        }
        self::clear($resourceid);
        self::store_one($resourceid, $userid, $path, '/', $plan['mainfile']);
        return ['source' => $plan['source'], 'mainfile' => $plan['mainfile']];
    }

    /**
     * Decide what an upload is, without storing it.
     *
     * @param string $kind
     * @param string $path
     * @param string $filename
     * @return array{source: string, mainfile: string, entries: array}
     */
    private static function describe(string $kind, string $path, string $filename): array {
        $filename = self::safe_filename($filename);
        $ext = self::extension($filename);
        self::check_size($path, filesize($path) ?: 0);

        if ($kind === 'world') {
            if ($ext !== 'mcworld' || !self::is_zip($path)) {
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
            return ['source' => 'file', 'mainfile' => $filename, 'entries' => []];
        }

        if ($kind === 'model' && ($ext === 'glb' || $ext === 'gltf')) {
            if ($ext === 'glb' && !self::is_glb($path)) {
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
            if ($ext === 'gltf' && !self::is_gltf($path)) {
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
            return ['source' => 'file', 'mainfile' => $filename, 'entries' => []];
        }

        if ($ext === 'html' || $ext === 'htm') {
            return ['source' => 'package', 'mainfile' => 'index.html', 'entries' => []];
        }

        if ($ext === 'zip') {
            $entries = self::read_zip($kind, $path);
            return [
                'source' => 'package',
                'mainfile' => $entries['main'],
                'entries' => $entries['pairs'],
            ];
        }

        throw new \moodle_exception('refused', 'local_wwlibrary');
    }

    /**
     * Delete every file stored for one resource.
     *
     * @param int $resourceid
     */
    public static function clear(int $resourceid): void {
        $fs = get_file_storage();
        $fs->delete_area_files(self::context()->id, 'local_wwlibrary', 'content', $resourceid);
    }

    /**
     * Files stored for one resource, directories left out.
     *
     * @param int $resourceid
     * @return \stored_file[]
     */
    public static function list_files(int $resourceid): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            self::context()->id,
            'local_wwlibrary',
            'content',
            $resourceid,
            'filepath, filename',
            false
        );
        return array_values($files);
    }

    /**
     * The file named by the resource record.
     *
     * @param \stdClass $resource
     * @return \stored_file
     */
    public static function main(\stdClass $resource): \stored_file {
        [$filepath, $filename] = self::split((string) $resource->mainfile);
        $fs = get_file_storage();
        $file = $fs->get_file(
            self::context()->id,
            'local_wwlibrary',
            'content',
            (int) $resource->id,
            $filepath,
            $filename
        );
        if (!$file) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        return $file;
    }

    /**
     * Address of one stored file.
     *
     * @param int $resourceid
     * @param string $filepath
     * @param string $filename
     * @param bool $download Force a download.
     * @return \moodle_url
     */
    public static function url(int $resourceid, string $filepath, string $filename, bool $download = false): \moodle_url {
        return \moodle_url::make_pluginfile_url(
            self::context()->id,
            'local_wwlibrary',
            'content',
            $resourceid,
            $filepath,
            $filename,
            $download
        );
    }

    /**
     * Paths and addresses for every file in a package.
     *
     * @param \stdClass $resource
     * @return array[]
     */
    public static function manifest(\stdClass $resource): array {
        $rows = [];
        foreach (self::list_files((int) $resource->id) as $file) {
            $rel = ltrim($file->get_filepath(), '/') . $file->get_filename();
            $rows[] = [
                'path' => $rel,
                'url' => self::url((int) $resource->id, $file->get_filepath(), $file->get_filename())->out(false),
            ];
        }
        return $rows;
    }

    /**
     * Send one file, or return false when the path is not allowed.
     *
     * HTML, script, and style files are sent as plain text with a sandbox
     * policy so opening the file address does not run them in this site.
     * The viewer reads the bytes and runs a package inside a framed page
     * that is not this site.
     *
     * @param string $filearea
     * @param array $args
     * @param bool $forcedownload
     * @param array $options
     * @return bool
     */
    public static function serve(string $filearea, array $args, bool $forcedownload, array $options = []): bool {
        if ($filearea !== 'content') {
            return false;
        }
        $resourceid = (int) array_shift($args);
        if ($resourceid <= 0) {
            return false;
        }
        $resource = library::get($resourceid);
        if (!$resource || !library::can_view_resource($resource)) {
            return false;
        }
        $filename = array_pop($args);
        if (!is_string($filename) || $filename === '' || $filename === '.' || $filename === '..') {
            return false;
        }
        $filepath = '/';
        if ($args) {
            foreach ($args as $part) {
                if (!is_string($part) || $part === '' || $part === '.' || $part === '..' || str_contains($part, '\\')) {
                    return false;
                }
            }
            $filepath .= implode('/', $args) . '/';
        }
        $fs = get_file_storage();
        $file = $fs->get_file(self::context()->id, 'local_wwlibrary', 'content', $resourceid, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }
        $ext = self::extension($filename);
        if (in_array($ext, ['html', 'htm', 'js', 'mjs', 'css', 'svg'], true)) {
            header('Content-Security-Policy: default-src \'none\'; sandbox');
            header('X-Content-Type-Options: nosniff');
        }
        send_stored_file($file, 0, 0, $forcedownload, $options);
        return true;
    }

    /**
     * Store a zip package that has already been checked.
     *
     * @param int $resourceid
     * @param int $userid
     * @param string $path
     * @param array $plan
     * @return array{source: string, mainfile: string}
     */
    private static function write_zip(int $resourceid, int $userid, string $path, array $plan): array {
        $zip = new \zip_archive();
        if (!$zip->open($path, \file_archive::OPEN)) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        $temp = make_request_directory();
        self::clear($resourceid);
        foreach ($plan['entries'] as $pair) {
            $info = $pair['info'];
            if (!$zip->extract_to($temp, $info->index)) {
                $zip->close();
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
            $extracted = $temp . '/' . $info->pathname;
            if (!is_file($extracted)) {
                $extracted = $temp . '/' . $info->original_pathname;
            }
            if (!is_file($extracted)) {
                $zip->close();
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
            [$filepath, $filename] = self::split($pair['rel']);
            self::store_one($resourceid, $userid, $extracted, $filepath, $filename);
        }
        $zip->close();
        return ['source' => 'package', 'mainfile' => $plan['mainfile']];
    }

    /**
     * Read a zip and return the files it may contain.
     *
     * @param string $kind
     * @param string $path
     * @return array{main: string, pairs: array}
     */
    private static function read_zip(string $kind, string $path): array {
        if ($kind === 'world' || !self::is_zip($path)) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        $zip = new \zip_archive();
        if (!$zip->open($path, \file_archive::OPEN)) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        $entries = [];
        $total = 0;
        $max = (int) $zip->estimated_count();
        for ($i = 0; $i < $max; $i++) {
            $info = $zip->get_info($i);
            if (!$info || !empty($info->is_directory)) {
                continue;
            }
            $entries[] = $info;
            $total += (int) $info->size;
            if (count($entries) > self::MAX_FILES || $total > self::limit()) {
                $zip->close();
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
        }
        if (!$entries) {
            $zip->close();
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }

        $raw = [];
        foreach ($entries as $info) {
            $original = str_replace('\\', '/', (string) $info->original_pathname);
            $clean = str_replace('\\', '/', (string) $info->pathname);
            if ($original === '' || str_contains($original, '..') || str_contains($clean, '..')
                    || str_starts_with($original, '/') || str_contains($original, ':')) {
                $zip->close();
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
            $raw[] = $clean;
        }
        $relative = self::strip_wrapper($raw);
        $pairs = [];
        foreach ($entries as $index => $info) {
            $rel = $relative[$index];
            self::check_relative($rel);
            $pairs[] = ['info' => $info, 'rel' => $rel];
        }
        $main = self::choose_main($kind, array_column($pairs, 'rel'));
        $zip->close();
        if ($main === null) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        return ['main' => $main, 'pairs' => $pairs];
    }

    /**
     * The file a package should open, or null when the package does not fit the kind.
     *
     * @param string $kind
     * @param string[] $paths
     * @return string|null
     */
    private static function choose_main(string $kind, array $paths): ?string {
        $index = null;
        $model = null;
        foreach ($paths as $path) {
            $ext = self::extension($path);
            if ($index === null && ($ext === 'html' || $ext === 'htm') && strcasecmp(basename($path), 'index.' . $ext) === 0
                    && !str_contains($path, '/')) {
                $index = $path;
            }
            if ($model === null && ($ext === 'glb' || $ext === 'gltf')) {
                $model = $path;
            }
        }
        if ($kind === 'map') {
            return $index;
        }
        if ($index !== null) {
            return $index;
        }
        return $model;
    }

    /**
     * Drop one wrapping directory when the zip has no files at its root.
     *
     * @param string[] $paths
     * @return string[]
     */
    private static function strip_wrapper(array $paths): array {
        $tops = [];
        $rootfile = false;
        foreach ($paths as $path) {
            if (!str_contains($path, '/')) {
                $rootfile = true;
                continue;
            }
            $tops[strstr($path, '/', true)] = true;
        }
        if ($rootfile || count($tops) !== 1) {
            return $paths;
        }
        $prefix = array_key_first($tops) . '/';
        $stripped = [];
        foreach ($paths as $path) {
            $stripped[] = substr($path, strlen($prefix));
        }
        return $stripped;
    }

    /**
     * Refuse a package path that is empty, climbs, or uses a blocked extension.
     *
     * @param string $path
     */
    private static function check_relative(string $path): void {
        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.')) {
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]*$/', $part)) {
                throw new \moodle_exception('refused', 'local_wwlibrary');
            }
        }
        $ext = self::extension($path);
        if (!in_array($ext, self::PACKAGE_EXT, true)) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
    }

    /**
     * Store one local file.
     *
     * @param int $resourceid
     * @param int $userid
     * @param string $path
     * @param string $filepath
     * @param string $filename
     */
    private static function store_one(int $resourceid, int $userid, string $path, string $filepath, string $filename): void {
        $fs = get_file_storage();
        $record = [
            'contextid' => self::context()->id,
            'component' => 'local_wwlibrary',
            'filearea' => 'content',
            'itemid' => $resourceid,
            'filepath' => $filepath,
            'filename' => $filename,
            'userid' => $userid,
            'mimetype' => self::mime($filename),
        ];
        $fs->create_file_from_pathname($record, $path);
    }

    /**
     * Split a relative path into a directory and a filename.
     *
     * @param string $relative
     * @return array{0: string, 1: string}
     */
    private static function split(string $relative): array {
        $relative = str_replace('\\', '/', $relative);
        $filename = basename($relative);
        $dir = trim(str_replace('\\', '/', dirname($relative)), '.');
        if ($dir === '' || $dir === '/') {
            return ['/', $filename];
        }
        return ['/' . trim($dir, '/') . '/', $filename];
    }

    /**
     * A filename with no directory.
     *
     * @param string $filename
     * @return string
     */
    private static function safe_filename(string $filename): string {
        $filename = basename(str_replace('\\', '/', $filename));
        $clean = clean_param($filename, PARAM_FILE);
        if ($clean === '' || $clean !== $filename || str_contains($filename, '..')) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
        return $clean;
    }

    /**
     * Lowercase extension, without the dot.
     *
     * @param string $filename
     * @return string
     */
    private static function extension(string $filename): string {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return strtolower(is_string($ext) ? $ext : '');
    }

    /**
     * Mime type stored for a filename.
     *
     * Script and markup types are stored as plain text. The viewer supplies
     * the type when it builds the framed page.
     *
     * @param string $filename
     * @return string
     */
    private static function mime(string $filename): string {
        $ext = self::extension($filename);
        $types = [
            'glb' => 'model/gltf-binary',
            'gltf' => 'model/gltf+json',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'text/plain',
            'html' => 'text/plain',
            'htm' => 'text/plain',
            'js' => 'text/plain',
            'mjs' => 'text/plain',
            'css' => 'text/plain',
            'json' => 'application/json',
            'bin' => 'application/octet-stream',
            'mcworld' => 'application/octet-stream',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'wasm' => 'application/wasm',
            'ico' => 'image/x-icon',
        ];
        return $types[$ext] ?? 'application/octet-stream';
    }

    /**
     * True when the file starts with a zip signature.
     *
     * @param string $path
     * @return bool
     */
    private static function is_zip(string $path): bool {
        $magic = file_get_contents($path, false, null, 0, 4);
        return $magic === "PK\x03\x04";
    }

    /**
     * True when the file starts with the glTF binary signature.
     *
     * @param string $path
     * @return bool
     */
    private static function is_glb(string $path): bool {
        $magic = file_get_contents($path, false, null, 0, 4);
        return $magic === 'glTF';
    }

    /**
     * True when the file looks like glTF JSON.
     *
     * @param string $path
     * @return bool
     */
    private static function is_gltf(string $path): bool {
        $start = file_get_contents($path, false, null, 0, 4096);
        if (!is_string($start)) {
            return false;
        }
        $start = ltrim($start);
        return $start !== '' && $start[0] === '{' && str_contains($start, '"asset"');
    }

    /**
     * Refuse a file outside the upload limit.
     *
     * @param string $path
     * @param int $bytes
     */
    private static function check_size(string $path, int $bytes): void {
        if (!is_file($path) || $bytes <= 0 || $bytes > self::limit()) {
            throw new \moodle_exception('refused', 'local_wwlibrary');
        }
    }

    /**
     * The smaller of the site upload limit and the library cap.
     *
     * @return int
     */
    private static function limit(): int {
        $php = (int) get_max_upload_file_size();
        if ($php > 0 && $php < self::MAX_BYTES) {
            return $php;
        }
        return self::MAX_BYTES;
    }
}
