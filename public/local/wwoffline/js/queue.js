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
 * Assignment text saved on this device until the server accepts it.
 *
 * The saved row has no session key. Sending reads a new session key from
 * the real submission form, then posts to that form.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function(root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.wwOfflineQueue = api;
}(typeof self !== 'undefined' ? self : this, function() {
    /**
     * @param {object} record
     * @returns {string}
     */
    function key(record) {
        return String(record.userId) + ':' + String(record.cmid);
    }

    /**
     * @param {string} url
     * @returns {string}
     */
    function stripPage(url) {
        var value = String(url || '');
        if (!value) {
            return '';
        }
        try {
            var parsed = new URL(value, 'http://ww.invalid');
            parsed.searchParams.delete('sesskey');
            if (/^https?:/i.test(value)) {
                return parsed.toString();
            }
            return parsed.pathname + parsed.search;
        } catch (e) {
            return value.replace(/([?&])(?:amp;)?sesskey=[^&]*/gi, '$1').replace(/[?&]$/, '');
        }
    }

    /**
     * @param {string} html
     * @returns {string}
     */
    function decodeAttr(value) {
        return String(value)
            .replace(/&amp;/g, '&')
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'")
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>');
    }

    /**
     * Words a student typed. Tags from the editor are not part of that text.
     *
     * @param {string} value
     * @returns {string}
     */
    function toPlain(value) {
        var text = String(value || '');
        if (text.indexOf('<') === -1) {
            return text.replace(/\r\n/g, '\n').replace(/^\n+|\n+$/g, '');
        }
        text = text.replace(/<br\s*\/?>/gi, '\n');
        text = text.replace(/<\/p>/gi, '\n\n');
        text = text.replace(/<[^>]+>/g, '');
        text = decodeAttr(text);
        return text.replace(/\n{3,}/g, '\n\n').replace(/^\n+|\n+$/g, '');
    }

    /**
     * @param {string} value
     * @returns {string}
     */
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Plain text as simple paragraphs the assignment form accepts.
     *
     * @param {string} value
     * @returns {string}
     */
    function toSubmissionHtml(value) {
        var plain = toPlain(value);
        if (!plain) {
            return '';
        }
        return plain.split(/\n{2,}/).map(function(paragraph) {
            return '<p>' + escapeHtml(paragraph).replace(/\n/g, '<br>') + '</p>';
        }).join('');
    }

    /**
     * @param {string} tag
     * @param {string} name
     * @returns {string}
     */
    function attr(tag, name) {
        var match = tag.match(new RegExp('\\b' + name + '\\s*=\\s*("([^"]*)"|\'([^\']*)\')', 'i'));
        if (!match) {
            return '';
        }
        return decodeAttr(match[2] !== undefined ? match[2] : match[3]);
    }

    /**
     * @param {object} store
     * @param {object} item
     * @returns {Promise<object>}
     */
    function enqueue(store, item) {
        var record = {
            userId: String(item.userId),
            cmid: String(item.cmid),
            text: toPlain(item.text),
            statement: item.statement ? '1' : '',
            state: item.state === 'draft' ? 'draft' : 'waiting',
            page: stripPage(item.page || ''),
        };
        return store.set(key(record), record).then(function() {
            return record;
        });
    }

    /**
     * @param {object} record
     * @returns {boolean}
     */
    function shouldSend(record) {
        if (!record || record.state !== 'waiting') {
            return false;
        }
        if (Object.prototype.hasOwnProperty.call(record, 'sesskey')) {
            return false;
        }
        return true;
    }

    /**
     * @param {object} store
     * @param {string|number} userId
     * @returns {Promise<object[]>}
     */
    function pending(store, userId) {
        var prefix = String(userId) + ':';
        return store.keys().then(function(keys) {
            var mine = (keys || []).filter(function(name) {
                return String(name).indexOf(prefix) === 0;
            });
            return Promise.all(mine.map(function(name) {
                return store.get(name);
            })).then(function(rows) {
                return rows.filter(shouldSend);
            });
        });
    }

    /**
     * @param {object} store
     * @param {string|number} userId
     * @returns {Promise<void>}
     */
    function clearUser(store, userId) {
        var prefix = String(userId) + ':';
        return store.keys().then(function(keys) {
            var mine = (keys || []).filter(function(name) {
                return String(name).indexOf(prefix) === 0;
            });
            return Promise.all(mine.map(function(name) {
                return store.delete(name);
            }));
        });
    }

    /**
     * The online-text form, including its current session key.
     *
     * @param {string} html
     * @returns {{action: string, fields: object, submit: string}|null}
     */
    function parseForm(html) {
        var forms = String(html || '').match(/<form\b[^>]*>[\s\S]*?<\/form>/gi) || [];
        var form = null;
        var i;
        for (i = 0; i < forms.length; i++) {
            if (/onlinetext_editor\[text\]/.test(forms[i])) {
                form = forms[i];
                break;
            }
        }
        if (!form) {
            return null;
        }
        var action = attr(form.match(/<form\b[^>]*>/i)[0], 'action');
        var fields = {};
        var tags = form.match(/<input\b[^>]*>/gi) || [];
        var submit = '';
        for (i = 0; i < tags.length; i++) {
            var name = attr(tags[i], 'name');
            var type = attr(tags[i], 'type').toLowerCase();
            if (!name) {
                continue;
            }
            if (type === 'submit' || type === 'button' || type === 'image') {
                if (name === 'submitbutton') {
                    submit = attr(tags[i], 'value');
                }
                continue;
            }
            if ((type === 'checkbox' || type === 'radio') && !/\bchecked\b/i.test(tags[i])) {
                continue;
            }
            fields[name] = attr(tags[i], 'value');
        }
        var areas = form.match(/<textarea\b[^>]*>[\s\S]*?<\/textarea>/gi) || [];
        for (i = 0; i < areas.length; i++) {
            var areaName = attr(areas[i], 'name');
            var text = areas[i].match(/>([\s\S]*?)<\/textarea>/i);
            if (areaName) {
                fields[areaName] = decodeAttr(text ? text[1] : '');
            }
        }
        if (!fields.sesskey) {
            return null;
        }
        return {action: action, fields: fields, submit: submit};
    }

    /**
     * Fields for the real save request, with the fresh session key.
     *
     * @param {string} html
     * @param {object} record
     * @returns {{action: string, fields: object}|null}
     */
    function buildReplay(html, record) {
        if (!record || isLoginHtml(html)) {
            return null;
        }
        var parsed = parseForm(html);
        if (!parsed || !parsed.fields.sesskey) {
            return null;
        }
        if (record.userId && parsed.fields.userid && String(parsed.fields.userid) !== String(record.userId)) {
            return null;
        }
        var fields = {};
        var names = Object.keys(parsed.fields);
        var i;
        for (i = 0; i < names.length; i++) {
            fields[names[i]] = parsed.fields[names[i]];
        }
        fields['onlinetext_editor[text]'] = toSubmissionHtml(record.text);
        if (record.statement) {
            fields.submissionstatement = '1';
        }
        fields.submitbutton = parsed.submit || 'Save changes';
        return {
            action: parsed.action || record.page || '',
            fields: fields,
        };
    }

    /**
     * @param {string} html
     * @returns {boolean}
     */
    function isLoginHtml(html) {
        var text = String(html || '');
        return /name\s*=\s*["']username["']/i.test(text) && /name\s*=\s*["']password["']/i.test(text);
    }

    /**
     * The server accepted the text. A sign-in page, a bad session key, or the
     * edit form still on screen is not acceptance.
     *
     * @param {string} finalUrl
     * @param {string} html
     * @returns {boolean}
     */
    function isConfirmed(finalUrl, html) {
        var url = String(finalUrl || '');
        var body = String(html || '');
        var path = '';
        try {
            path = new URL(url, 'http://ww.invalid').pathname;
        } catch (e) {
            return false;
        }
        if (/\/login\/(?:index|signup|forgot_password|logout)\.php$/.test(path) || /\/login\/?$/.test(path)) {
            return false;
        }
        if (isLoginHtml(body) || /invalidsesskey/i.test(body)) {
            return false;
        }
        if (!/\/mod\/assign\/view\.php$/.test(path)) {
            return false;
        }
        if (/[?&]action=(?:editsubmission|savesubmission)(?:&|$)/.test(url)) {
            return false;
        }
        if (/name\s*=\s*["']onlinetext_editor\[text\]["']/i.test(body)) {
            return false;
        }
        return true;
    }

    /**
     * @param {boolean} confirmed
     * @returns {string}
     */
    function outcome(confirmed) {
        return confirmed ? 'sent' : 'waiting';
    }

    return {
        key: key,
        stripPage: stripPage,
        toPlain: toPlain,
        toSubmissionHtml: toSubmissionHtml,
        enqueue: enqueue,
        shouldSend: shouldSend,
        pending: pending,
        clearUser: clearUser,
        parseForm: parseForm,
        buildReplay: buildReplay,
        isConfirmed: isConfirmed,
        outcome: outcome,
    };
}));
