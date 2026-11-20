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
 * What the offline cache may store.
 *
 * Each signed-in person has their own cache. Signing out deletes that cache.
 * A sign-in page, or any address that carries a session key, is never stored.
 * A stored page has the session key removed.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function(root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.wwOfflineRules = api;
}(typeof self !== 'undefined' ? self : this, function() {
    var VERSION = 'v1';

    /**
     * @param {string} url
     * @returns {string}
     */
    function pathname(url) {
        try {
            return new URL(url, 'http://ww.invalid').pathname;
        } catch (e) {
            return '';
        }
    }

    /**
     * @param {string} path
     * @returns {boolean}
     */
    function isSignInPath(path) {
        return /\/login\/(?:index|signup|forgot_password|change_password|confirm)\.php$/.test(path) ||
            /\/login\/?$/.test(path) ||
            /\/auth\/wwpicture\/.+\.php$/.test(path);
    }

    /**
     * @param {string} url
     * @returns {boolean}
     */
    function urlHasSesskey(url) {
        try {
            if (new URL(url, 'http://ww.invalid').searchParams.has('sesskey')) {
                return true;
            }
        } catch (e) {
            return false;
        }
        return /[?&](?:amp;)?sesskey=/i.test(String(url));
    }

    /**
     * @param {string} url
     * @param {string} method
     * @returns {string}
     */
    function classify(url, method) {
        var path = pathname(url);
        if (/\/login\/logout\.php$/.test(path)) {
            return 'logout';
        }
        if (method !== 'GET' || urlHasSesskey(url) || isSignInPath(path)) {
            return 'never';
        }
        if (/\/local\/wolfwhale\/index\.php$/.test(path)) {
            return 'home';
        }
        if (/\/local\/wwupdates\/preferences\.php$/.test(path)) {
            return 'settings';
        }
        if (/\/mod\/(?:page|book|resource)\/view\.php$/.test(path) ||
                /\/local\/wwoffline\/lesson\.php$/.test(path)) {
            return 'lesson';
        }
        if (/\/pluginfile\.php\/\d+\/mod_(?:page|book|resource)\//.test(path)) {
            return 'lessonfile';
        }
        if (/\/pluginfile\.php\/\d+\/user\/icon\//.test(path)) {
            return 'userfile';
        }
        if (/\/theme\/(?:styles|font|image)\.php/.test(path) ||
                /\/lib\/(?:javascript|requirejs)\.php\//.test(path) ||
                /\/lib\/(?:requirejs|javascript|yuilib)\//.test(path) ||
                /\/theme\/yui_combo\.php/.test(path) ||
                /\/local\/wwoffline\/js\/.+\.js$/.test(path) ||
                /\/pix\/.+\.(?:png|svg|gif|ico|woff2?)$/.test(path) ||
                /\/favicon\.ico$/.test(path)) {
            return 'shell';
        }
        return 'pass';
    }

    /**
     * @param {string} kind
     * @param {string|number|null} userId
     * @returns {string|null}
     */
    function cacheNameFor(kind, userId) {
        if (kind === 'shell') {
            return 'ww-shell-' + VERSION;
        }
        if (kind === 'home' || kind === 'settings' || kind === 'lesson' || kind === 'lessonfile' || kind === 'userfile') {
            if (!userId || String(userId) === '0') {
                return null;
            }
            return 'ww-user-' + VERSION + '-' + String(userId);
        }
        return null;
    }

    /**
     * @param {string} html
     * @returns {string}
     */
    function stripSesskey(html) {
        return String(html)
            .replace(/([?&](?:amp;)?sesskey=)[^&"'#\s<]*/gi, '$1')
            .replace(/(name\s*=\s*["']sesskey["'][^>]*value\s*=\s*["'])[^"']*(["'])/gi, '$1$2')
            .replace(/(value\s*=\s*["'][^"']*["'])([^>]*name\s*=\s*["']sesskey["'])/gi, function(match, start, rest) {
                return start.replace(/["'][^"']*["']$/, '""') + rest;
            })
            .replace(/(["']sesskey["']\s*:\s*["'])[^"']*(["'])/gi, '$1$2');
    }

    /**
     * A session key that still has a value.
     *
     * @param {string} html
     * @returns {boolean}
     */
    function hasLiveSesskey(html) {
        var text = String(html || '');
        if (/[?&](?:amp;)?sesskey=[^&"'#\s<]+/i.test(text)) {
            return true;
        }
        if (/name\s*=\s*["']sesskey["'][^>]*value\s*=\s*["'][^"']+["']/i.test(text)) {
            return true;
        }
        if (/value\s*=\s*["'][^"']+["'][^>]*name\s*=\s*["']sesskey["']/i.test(text)) {
            return true;
        }
        if (/["']sesskey["']\s*:\s*["'][^"']+["']/i.test(text)) {
            return true;
        }
        return false;
    }

    /**
     * @param {string} html
     * @returns {boolean}
     */
    function isLoginDocument(html) {
        var text = String(html || '');
        return /name\s*=\s*["']username["']/i.test(text) && /name\s*=\s*["']password["']/i.test(text);
    }

    /**
     * A form that still contains a session key.
     *
     * @param {string} html
     * @returns {boolean}
     */
    function formHasSesskey(html) {
        var forms = String(html || '').match(/<form\b[^>]*>[\s\S]*?<\/form>/gi) || [];
        var i;
        for (i = 0; i < forms.length; i++) {
            if (hasLiveSesskey(forms[i])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param {object} input
     * @returns {{store: boolean, cacheName: string|null, body: string|null}}
     */
    function planStore(input) {
        var method = input.method || 'GET';
        var finalUrl = input.finalUrl || input.url;
        var requestKind = classify(input.url, method);
        var finalKind = classify(finalUrl, 'GET');
        var empty = {store: false, cacheName: null, body: null};
        if (requestKind === 'logout' || requestKind === 'never' || finalKind === 'logout' || finalKind === 'never') {
            return empty;
        }
        var kind = finalKind === 'pass' ? requestKind : finalKind;
        var cacheName = cacheNameFor(kind, input.userId);
        if (!cacheName || kind === 'pass') {
            return empty;
        }
        if (kind === 'shell') {
            return {store: true, cacheName: cacheName, body: null};
        }
        if (kind === 'lessonfile' || kind === 'userfile') {
            return {store: true, cacheName: cacheName, body: null};
        }
        if (String(input.ownerId) !== String(input.userId)) {
            return empty;
        }
        var body = String(input.body || '');
        if (isLoginDocument(body)) {
            return empty;
        }
        var stripped = stripSesskey(body);
        if (hasLiveSesskey(stripped) || formHasSesskey(stripped) || isLoginDocument(stripped)) {
            return empty;
        }
        return {store: true, cacheName: cacheName, body: stripped};
    }

    /**
     * @param {object} caches
     * @param {object} input
     * @returns {Promise<object>}
     */
    function remember(caches, input) {
        var plan = planStore(input);
        if (!plan.store) {
            return Promise.resolve(plan);
        }
        var payload = plan.body === null ? (input.bytes != null ? input.bytes : '') : plan.body;
        return caches.open(plan.cacheName).then(function(cache) {
            return cache.put(input.url, payload).then(function() {
                return plan;
            });
        });
    }

    /**
     * @param {object} caches
     * @param {string|number} userId
     * @returns {Promise<boolean>}
     */
    function applyLogout(caches, userId) {
        var name = cacheNameFor('home', userId);
        if (!name) {
            return Promise.resolve(false);
        }
        return caches.delete(name);
    }

    return {
        VERSION: VERSION,
        pathname: pathname,
        isSignInPath: isSignInPath,
        classify: classify,
        cacheNameFor: cacheNameFor,
        stripSesskey: stripSesskey,
        hasLiveSesskey: hasLiveSesskey,
        formHasSesskey: formHasSesskey,
        isLoginDocument: isLoginDocument,
        planStore: planStore,
        remember: remember,
        applyLogout: applyLogout,
    };
}));
