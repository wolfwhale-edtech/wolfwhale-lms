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
        var styled = quoted.replace(/url\(\s*(['"]?)(.*?)\1\s*\)/gi, function(all, quote, raw) {
            var next = lookup(raw);
            if (next === '') {
                return 'url("")';
            }
            if (next === raw) {
                return all;
            }
            return 'url("' + next + '")';
        });
        return styled.replace(/@import\s+(['"])(.*?)\1/gi, function(all, quote, raw) {
            var next = lookup(raw);
            if (next === '' || next === raw) {
                return all;
            }
            return '@import ' + quote + next + quote;
        });
    }

    /**
     * Point module imports at packaged files.
     *
     * @param {string} text
     * @param {string} basefile
     * @param {Object.<string, string>} urls
     * @returns {string}
     */
    function rewriteModules(text, basefile, urls) {
        var mapped = function(spec) {
            var resolved = resolve(basefile, spec);
            if (resolved === null || resolved === false) {
                return spec;
            }
            var path = String(resolved).split('#')[0];
            if (Object.prototype.hasOwnProperty.call(urls, path)) {
                return urls[path];
            }
            return spec;
        };
        var fromSpec = text.replace(/\b((?:import|export)\b[\s\S]*?\bfrom\s*)(['"])([^'"]+)\2/g, function(all, lead, quote, spec) {
            return lead + quote + mapped(spec) + quote;
        });
        var bare = fromSpec.replace(/\bimport\s*(['"])([^'"]+)\1/g, function(all, quote, spec) {
            return 'import ' + quote + mapped(spec) + quote;
        });
        return bare.replace(/\bimport\s*\(\s*(['"])([^'"]+)\1\s*\)/g, function(all, quote, spec) {
            return 'import(' + quote + mapped(spec) + quote + ')';
        });
    }

    /**
     * @param {string} text
     * @param {string} type
     * @returns {string}
     */
    function textUrl(text, type) {
        return 'data:' + type + ';charset=utf-8,' + encodeURIComponent(text);
    }

    /**
     * @param {Uint8Array} bytes
     * @param {string} type
     * @returns {string}
     */
    function bytesUrl(bytes, type) {
        var binary = '';
        var size = 0x8000;
        for (var i = 0; i < bytes.length; i += size) {
            var slice = bytes.subarray(i, i + size);
            binary += String.fromCharCode.apply(null, slice);
        }
        return 'data:' + (type || 'application/octet-stream') + ';base64,' + btoa(binary);
    }

    /**
     * Turn package files into addresses the framed page can read.
     *
     * The frame is not this site, so it cannot read addresses made here.
     * Every file becomes a data address. A script at the top of the page
     * sends fetch and XHR for a packaged path to that file.
     *
     * @param {Array<{path: string, text?: string, bytes?: Uint8Array, type?: string}>} items
     * @param {string} index
     * @returns {string}
     */
    function build(items, index) {
        var sources = {};
        var urls = {};
        var bytes = {};
        var types = {};
        items.forEach(function(item) {
            sources[item.path] = item.text;
            if (item.bytes) {
                bytes[item.path] = item.bytes;
                types[item.path] = item.type || 'application/octet-stream';
            }
        });
        Object.keys(bytes).forEach(function(path) {
            urls[path] = bytesUrl(bytes[path], types[path]);
        });
        Object.keys(sources).forEach(function(path) {
            if (sources[path] == null || !/\.css$/i.test(path)) {
                return;
            }
            urls[path] = textUrl(rewrite(sources[path], path, urls), 'text/css');
        });
        Object.keys(sources).forEach(function(path) {
            if (sources[path] == null || /\.(html?|css|js|mjs)$/i.test(path)) {
                return;
            }
            if (urls[path]) {
                return;
            }
            urls[path] = textUrl(sources[path], blobType(path));
        });
        var seen = {};
        var encodeScript = function(path) {
            if (urls[path] || seen[path] || sources[path] == null) {
                return;
            }
            seen[path] = true;
            rewriteModules(sources[path], path, {}).replace(/(['"])([^'"]+)\1/g, function(all, quote, spec) {
                if (!/\.(js|mjs)$/i.test(spec.split('?')[0].split('#')[0])) {
                    return all;
                }
                var resolved = resolve(path, spec);
                if (resolved && sources[String(resolved).split('#')[0]] != null) {
                    encodeScript(String(resolved).split('#')[0]);
                }
                return all;
            });
            urls[path] = textUrl(rewriteModules(sources[path], path, urls), blobType(path));
        };
        Object.keys(sources).forEach(function(path) {
            if (/\.(js|mjs)$/i.test(path)) {
                encodeScript(path);
            }
        });
        var html = sources[index] || '';
        html = rewrite(html, index, urls);
        var shim = '<script>' + shimSource(urls, index) + '</script>';
        if (/<head[^>]*>/i.test(html)) {
            html = html.replace(/<head[^>]*>/i, function(tag) {
                return tag + shim;
            });
        } else if (/<body[^>]*>/i.test(html)) {
            html = html.replace(/<body[^>]*>/i, function(tag) {
                return tag + shim;
            });
        } else {
            html = shim + html;
        }
        return html;
    }

    /**
     * Script that sends packaged fetch and XHR addresses to the file.
     *
     * @param {Object.<string, string>} urls
     * @param {string} index
     * @returns {string}
     */
    function shimSource(urls, index) {
        return '(function(){' +
            'var files=' + JSON.stringify(urls) + ';' +
            'var base=' + JSON.stringify(index) + ';' +
            'function resolve(basefile, relative){' +
            'if(relative==null)return null;' +
            'var value=String(relative).trim();' +
            'if(value===""||value.charAt(0)==="#")return null;' +
            'var lower=value.toLowerCase();' +
            'if(lower.indexOf("javascript:")===0||lower.indexOf("vbscript:")===0)return false;' +
            'if(lower.indexOf("data:")===0||/^[a-z][a-z0-9+.-]*:/i.test(value)||value.indexOf("//")===0)return null;' +
            'var hash="";var path=value;var hashAt=path.indexOf("#");' +
            'if(hashAt>=0){hash=path.slice(hashAt);path=path.slice(0,hashAt);}' +
            'var queryAt=path.indexOf("?");if(queryAt>=0){path=path.slice(0,queryAt);}' +
            'if(path==="")return null;' +
            'var parts=String(basefile||"").split("/");parts.pop();' +
            'if(path.charAt(0)==="/"){parts=[];path=path.slice(1);}' +
            'var bits=path.split("/");' +
            'for(var i=0;i<bits.length;i++){' +
            'if(bits[i]===""||bits[i]===".")continue;' +
            'if(bits[i]===".."){if(!parts.length)return false;parts.pop();continue;}' +
            'parts.push(bits[i]);}' +
            'return parts.join("/")+hash;}' +
            'function map(raw){' +
            'var resolved=resolve(base, raw);' +
            'if(resolved===null||resolved===false)return raw;' +
            'var path=String(resolved).split("#")[0];' +
            'var hash=String(resolved).indexOf("#")>=0?String(resolved).slice(String(resolved).indexOf("#")):"";' +
            'if(Object.prototype.hasOwnProperty.call(files, path))return files[path]+hash;' +
            'return raw;}' +
            'function readData(url){return new Promise(function(resolve, reject){' +
            'var value=String(url);if(value.indexOf("data:")!==0){reject(new Error("file"));return;}' +
            'var comma=value.indexOf(",");var meta=value.slice(5, comma);var body=value.slice(comma+1);' +
            'var text=meta.indexOf("base64")>=0?atob(body):decodeURIComponent(body);' +
            'resolve({ok:true,text:function(){return Promise.resolve(text);},json:function(){return Promise.resolve(JSON.parse(text));}});});}' +
            'var orig=window.fetch?window.fetch.bind(window):null;' +
            'window.fetch=function(input, init){var url=typeof input==="string"?map(input):input;' +
            'if(orig)return orig(url, init);return readData(url);};' +
            'var open=XMLHttpRequest.prototype.open;' +
            'XMLHttpRequest.prototype.open=function(){var args=Array.prototype.slice.call(arguments);' +
            'if(typeof args[1]==="string")args[1]=map(args[1]);return open.apply(this, args);};' +
            '})();';
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
                return response.arrayBuffer().then(function(buffer) {
                    return {
                        path: file.path,
                        bytes: new Uint8Array(buffer),
                        type: response.headers.get('content-type') || 'application/octet-stream',
                    };
                });
            });
        })).then(function(loaded) {
            var page = build(loaded, index);
            frame.src = URL.createObjectURL(new Blob([page], {type: 'text/html'}));
        }).catch(function() {
            frame.removeAttribute('src');
        });
    }

    return {
        resolve: resolve,
        rewrite: rewrite,
        rewriteModules: rewriteModules,
        build: build,
        mount: mount,
    };
}));
