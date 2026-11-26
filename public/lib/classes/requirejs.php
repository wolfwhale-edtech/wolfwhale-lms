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
 * RequireJS helper functions.
 *
 * @package    core
 * @copyright  2015 Damyon Wiese <damyon@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Collection of requirejs related methods.
 *
 * @copyright  2015 Damyon Wiese <damyon@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_requirejs {

    /**
     * Check a single module exists and return the full path to it.
     *
     * The expected location for amd modules is:
     *  <componentdir>/amd/src/modulename.js
     *
     * @param string $component The component determines the folder the js file should be in.
     * @param string $jsfilename The filename for the module (with the js extension).
     * @param bool $unused
     * @return array $files An array of mappings from module names to file paths.
     *                      Empty array if the file does not exist.
     */
    public static function find_one_amd_module($component, $jsfilename, $unused = false) {
        $jsfileroot = core_component::get_component_directory($component);
        if (!$jsfileroot) {
            return array();
        }

        $module = str_replace('.js', '', $jsfilename);

        $srcdir = $jsfileroot . '/amd/build';
        $minpart = '.min';

        $filename = $srcdir . '/' . $module . $minpart . '.js';
        if (!file_exists($filename)) {
            return array();
        }

        $fullmodulename = $component . '/' . $module;
        return array($fullmodulename => $filename);
    }

    /**
     * Scan the source for AMD modules and return them all.
     *
     * The expected location for amd modules is:
     *  <componentdir>/amd/src/modulename.js
     *
     * @param boolean $unused
     * @param boolean $includelazy If true, includes modules with the -lazy suffix.
     * @return array $files An array of mappings from module names to file paths.
     */
    public static function find_all_amd_modules($unused = false, $includelazy = false) {
        global $CFG;

        $jsdirs = array();
        $jsfiles = array();

        $dir = $CFG->libdir . '/amd';
        if (!empty($dir) && is_dir($dir)) {
            $jsdirs['core'] = $dir;
        }
        $subsystems = core_component::get_core_subsystems();
        foreach ($subsystems as $subsystem => $dir) {
            if (!empty($dir) && is_dir($dir . '/amd')) {
                $jsdirs['core_' . $subsystem] = $dir . '/amd';
            }
        }
        $plugintypes = core_component::get_plugin_types();
        foreach ($plugintypes as $type => $dir) {
            $plugins = core_component::get_plugin_list_with_file($type, 'amd', false);
            foreach ($plugins as $plugin => $dir) {
                if (!empty($dir) && is_dir($dir)) {
                    $jsdirs[$type . '_' . $plugin] = $dir;
                }
            }
        }

        foreach ($jsdirs as $component => $dir) {
            $srcdir = $dir . '/build';
            if (!is_dir($srcdir) || !is_readable($srcdir)) {
                // This is probably an empty amd directory without src or build.
                // Skip it - RecursiveDirectoryIterator fatals if the directory is not readable as an iterator.
                continue;
            }
            $srcdir = realpath($srcdir);
            $directory = new RecursiveDirectoryIterator($srcdir);
            $items = new RecursiveIteratorIterator($directory);
            foreach ($items as $item) {
                $extension = $item->getExtension();
                if ($extension === 'js') {
                    $filename = substr($item->getRealpath(), strlen($srcdir) + 1);
                    $filename = preg_replace('/(\.min)?\.js$/', '', $filename);
                    // We skip lazy loaded modules unless specifically requested.
                    if ($includelazy || strpos($filename, '-lazy') === false) {
                        $modulename = $component . '/' . $filename;
                        $jsfiles[$modulename] = $item->getRealPath();
                    }
                }
                unset($item);
            }
            unset($items);
        }

        return $jsfiles;
    }

    /**
     * AMD modules a page actually starts, plus the modules those files list.
     *
     * Production used to answer every request with every module. On a slow link that
     * file is the whole wait. This returns only the requested tree.
     *
     * @param string[] $seeds Module names such as core/page_global.
     * @return array<string, string> Module name => minified file.
     */
    public static function collect_amd_tree(array $seeds): array {
        $all = self::find_all_amd_modules();
        $files = [];
        $seen = [];
        $stack = array_values($seeds);
        while ($stack) {
            $name = array_pop($stack);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            if (!isset($all[$name])) {
                continue;
            }
            $files[$name] = $all[$name];
            foreach (self::read_static_dependencies($all[$name], $name) as $dependency) {
                $stack[] = $dependency;
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Write one cached file for this page's modules and return its id.
     *
     * @param string[] $seeds Module names the page requires.
     * @param int $rev JavaScript revision.
     * @return array{hash: string, modules: string[]}|null
     */
    public static function store_page_bundle(array $seeds, int $rev): ?array {
        global $CFG;

        $files = self::collect_amd_tree($seeds);
        if (!$files) {
            return null;
        }
        $hash = sha1($rev . "\n" . implode("\n", array_keys($files)));
        $path = self::bundle_cache_file($rev, $hash);
        // The module list is shared first, so another node can rebuild the script
        // if it does not have the file yet. $CFG->cachedir is shared by cluster nodes.
        // $CFG->localcachedir is not.
        $list = $path . '.list';
        if (!is_readable($list)) {
            self::write_cache_file($list, implode("\n", array_keys($files)) . "\n");
        }
        if (!is_readable($path)) {
            self::write_cache_file($path, self::bundle_source($files));
        }
        return [
            'hash' => $hash,
            'modules' => array_keys($files),
        ];
    }

    /**
     * Shared path for one page bundle. Cluster nodes all see this directory.
     *
     * @param int $rev JavaScript revision.
     * @param string $hash Bundle id.
     * @return string
     */
    public static function bundle_cache_file(int $rev, string $hash): string {
        global $CFG;
        return $CFG->cachedir . '/requirejs/bundle-' . $rev . '-' . $hash;
    }

    /**
     * Rebuild a bundle from the shared module list when the script file is missing.
     *
     * @param int $rev JavaScript revision.
     * @param string $hash Bundle id.
     * @return bool
     */
    public static function rebuild_page_bundle(int $rev, string $hash): bool {
        $path = self::bundle_cache_file($rev, $hash);
        $list = $path . '.list';
        if (!is_readable($list)) {
            return false;
        }
        $modules = array_values(array_filter(explode("\n", (string) file_get_contents($list)), 'strlen'));
        $files = self::collect_amd_tree($modules);
        if (!$files) {
            return false;
        }
        self::write_cache_file($path, self::bundle_source($files));
        return is_readable($path);
    }

    /**
     * Write a cache file, creating the directory when needed.
     *
     * @param string $path Destination.
     * @param string $content File contents.
     */
    private static function write_cache_file(string $path, string $content): void {
        global $CFG;
        require_once($CFG->libdir . '/jslib.php');
        js_write_cache_file_content($path, $content);
    }

    /**
     * Join minified modules into one script.
     *
     * @param array<string, string> $files Module name => path.
     * @return string
     */
    public static function bundle_source(array $files): string {
        $content = '';
        foreach ($files as $modulename => $jsfile) {
            $js = file_get_contents($jsfile);
            if ($js === false) {
                $content .= "/* Failed to load JavaScript file {$jsfile}. */\n";
                continue;
            }
            $js = preg_replace('~//# sourceMappingURL.*$~s', '', $js);
            $js = rtrim($js) . "\n";
            $content .= self::name_define($modulename, $js);
        }
        return $content;
    }

    /**
     * Dependencies named in a compiled AMD file.
     *
     * @param string $path Minified module.
     * @param string $module Module name, used to resolve ./ imports.
     * @return string[]
     */
    public static function read_static_dependencies(string $path, string $module): array {
        $js = file_get_contents($path);
        if ($js === false || !preg_match('/define\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[(.*?)\]/s', $js, $match)) {
            return [];
        }
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $deps);
        $resolved = [];
        foreach ($deps[1] as $dependency) {
            $name = self::resolve_amd_dependency($module, $dependency);
            if ($name !== null) {
                $resolved[] = $name;
            }
        }
        return $resolved;
    }

    /**
     * Turn a dependency string into a module name. External and ESM names are skipped.
     *
     * @param string $module Module that imported it.
     * @param string $dependency Raw dependency.
     * @return string|null
     */
    public static function resolve_amd_dependency(string $module, string $dependency): ?string {
        $external = ['require', 'exports', 'module', 'jquery', 'jqueryui', 'jqueryprivate'];
        if ($dependency === '' || in_array($dependency, $external, true)) {
            return null;
        }
        if (str_starts_with($dependency, 'core/esm!') || str_starts_with($dependency, 'esm!')) {
            return null;
        }
        if (!str_starts_with($dependency, '.')) {
            return $dependency;
        }
        $base = explode('/', $module);
        array_pop($base);
        foreach (explode('/', $dependency) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($base);
                continue;
            }
            $base[] = $part;
        }
        $name = implode('/', $base);
        return $name === '' ? null : $name;
    }

    /**
     * Add the module name when a compiled file omitted it.
     *
     * @param string $modulename Module name.
     * @param string $js File contents.
     * @return string
     */
    private static function name_define(string $modulename, string $js): string {
        $missingmodule = preg_match('/define\(\s*(\[|function)/', $js);
        $missingmodule = $missingmodule && !preg_match("@define\s*\(\s*['\"]{$modulename}['\"]@", $js);
        if ($missingmodule) {
            $replace = 'define(\'' . $modulename . '\', ';
            return implode($replace, explode('define(', $js, 2));
        }
        return $js;
    }

}
