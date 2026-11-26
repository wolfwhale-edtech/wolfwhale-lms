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
 * Open an uploaded HTML package inside a framed page.
 *
 * The frame is not allowed to be this site. Files are fetched here, then
 * handed to the frame as addresses that only the frame can read.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function(root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.wwLibraryViewer = api;
    if (typeof document !== 'undefined') {
        var start = function() {
            api.mount(document);
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    }
}(typeof self !== 'undefined' ? self : this, function() {
    /**
     * Package path for a reference, null when it should be left alone,
     * or false when it must not be used.
     *
     * @param {string} basefile
     * @param {string} relative
     * @returns {string|null|false}
     */
    function resolve(basefile, relative) {
        if (relative == null) {
            return null;
        }
        var value = String(relative).trim();
        if (value === '' || value.charAt(0) === '#') {
            return null;
        }
        var lower = value.toLowerCase();
        if (lower.indexOf('javascript:') === 0 || lower.indexOf('vbscript:') === 0) {
            return false;
        }
        if (lower.indexOf('data:') === 0 || /^[a-z][a-z0-9+.-]*:/i.test(value) || value.indexOf('//') === 0) {
            return null;
        }
        var hash = '';
        var path = value;
        var hashAt = path.indexOf('#');
        if (hashAt >= 0) {
            hash = path.slice(hashAt);
            path = path.slice(0, hashAt);
        }
        var queryAt = path.indexOf('?');
        if (queryAt >= 0) {
            path = path.slice(0, queryAt);
        }
        if (path === '') {
            return null;
        }
        var parts = String(basefile || '').split('/');
        parts.pop();
        if (path.charAt(0) === '/') {
            parts = [];
            path = path.slice(1);
        }
        var bits = path.split('/');
        for (var i = 0; i < bits.length; i++) {
            if (bits[i] === '' || bits[i] === '.') {
                continue;
            }
            if (bits[i] === '..') {
                if (!parts.length) {
                    return false;
                }
                parts.pop();
                continue;
            }
            parts.push(bits[i]);
        }
        return parts.join('/') + hash;
    }

    /**
     * Rewrite src, href, and CSS url() values that point at package files.
     *
     * @param {string} text
     * @param {string} basefile
     * @param {Object.<string, string>} urls
     * @returns {string}
     */
    function rewrite(text, basefile, urls) {
        var lookup = function(raw) {
            var resolved = resolve(basefile, raw);
            if (resolved === null) {
                return raw;
            }
            if (resolved === false) {
                return '';
            }
            var path = String(resolved).split('#')[0];
            if (Object.prototype.hasOwnProperty.call(urls, path)) {
                return urls[path];
            }
            return raw;
        };
        var quoted = text.replace(/\b(src|href)\s*=\s*(['"])(.*?)\2/gi, function(all, attr, quote, raw) {
            var next = lookup(raw);
            if (next === '') {
                return attr + '=' + quote + '#' + quote;
            }
            return attr + '=' + quote + next + quote;
        });
        return quoted.replace(/url\(\s*(['"]?)(.*?)\1\s*\)/gi, function(all, quote, raw) {
            var next = lookup(raw);
            if (next === '') {
                return 'url("")';
            }
            if (next === raw) {
                return all;
            }
            return 'url("' + next + '")';
        });
    }

    /**
     * @param {string} path
     * @returns {boolean}
     */
    function isText(path) {
        return /\.(html?|css|js|mjs|json|txt|map|svg)$/i.test(path);
    }

    /**
     * @param {string} path
     * @returns {string}
     */
    function blobType(path) {
        if (/\.css$/i.test(path)) {
            return 'text/css';
        }
        if (/\.(js|mjs)$/i.test(path)) {
            return 'text/javascript';
        }
        if (/\.svg$/i.test(path)) {
            return 'image/svg+xml';
        }
        if (/\.json$/i.test(path)) {
            return 'application/json';
        }
        return 'text/plain';
    }

    /**
     * Fill the package frame.
     *
     * @param {Document} doc
     */
    function mount(doc) {
        var node = doc.querySelector('[data-ww-package]');
        if (!node || node.getAttribute('data-ww-mounted') === '1') {
            return;
        }
        node.setAttribute('data-ww-mounted', '1');
        var frame = node.querySelector('iframe');
        if (!frame) {
            return;
        }
        var files;
        try {
            files = JSON.parse(node.getAttribute('data-ww-package') || '');
        } catch (e) {
            return;
        }
        if (!files || !files.length) {
            return;
        }
        var index = 'index.html';
        files.forEach(function(file) {
            if (String(file.path).toLowerCase() === 'index.html' || String(file.path).toLowerCase() === 'index.htm') {
                index = file.path;
            }
        });
        Promise.all(files.map(function(file) {
            return fetch(file.url, {credentials: 'same-origin'}).then(function(response) {
                if (!response.ok) {
                    throw new Error('file');
                }
                if (isText(file.path)) {
                    return response.text().then(function(text) {
                        return {path: file.path, text: text};
                    });
                }
                return response.blob().then(function(blob) {
                    return {path: file.path, blob: blob};
                });
            });
        })).then(function(loaded) {
            var urls = {};
            loaded.forEach(function(item) {
                if (item.blob) {
                    urls[item.path] = URL.createObjectURL(item.blob);
                }
            });
            loaded.forEach(function(item) {
                if (item.text == null || !/\.(js|mjs|json|txt|map|svg)$/i.test(item.path)) {
                    return;
                }
                urls[item.path] = URL.createObjectURL(new Blob([item.text], {type: blobType(item.path)}));
            });
            loaded.forEach(function(item) {
                if (item.text == null || !/\.css$/i.test(item.path)) {
                    return;
                }
                var css = rewrite(item.text, item.path, urls);
                urls[item.path] = URL.createObjectURL(new Blob([css], {type: 'text/css'}));
            });
            var html = '';
            loaded.forEach(function(item) {
                if (item.path === index) {
                    html = item.text || '';
                }
            });
            var page = rewrite(html, index, urls);
            frame.src = URL.createObjectURL(new Blob([page], {type: 'text/html'}));
        }).catch(function() {
            frame.removeAttribute('src');
        });
    }

    return {
        resolve: resolve,
        rewrite: rewrite,
        mount: mount,
    };
}));
