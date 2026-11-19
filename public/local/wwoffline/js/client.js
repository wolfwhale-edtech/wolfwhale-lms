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
 * Registers the offline worker and keeps an unsent assignment on this device.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function() {
    var root = document.getElementById('ww-offline');
    if (!root || !window.wwOfflineDb || !window.wwOfflineQueue) {
        return;
    }

    var userId = root.getAttribute('data-user') || '';
    var swUrl = root.getAttribute('data-sw') || '';
    var scope = root.getAttribute('data-scope') || '/';
    var waitingWord = root.getAttribute('data-waiting') || '';
    var sentWord = root.getAttribute('data-sent') || '';
    var draftTimer = 0;

    /**
     * Content images load when they get near the screen.
     */
    function lazyImages() {
        var images = document.querySelectorAll('#region-main img');
        var i;
        for (i = 0; i < images.length; i++) {
            if (!images[i].hasAttribute('loading')) {
                images[i].loading = 'lazy';
                images[i].decoding = 'async';
            }
        }
    }

    /**
     * @param {boolean} offline
     */
    function setOffline(offline) {
        var node = root.querySelector('.ww-net');
        if (node) {
            node.hidden = !offline;
        }
    }

    /**
     * @param {HTMLElement} host
     * @param {string} word
     */
    function showWord(host, word) {
        var node = host.querySelector('.ww-submission-state');
        if (!node) {
            node = document.createElement('p');
            node.className = 'ww-net ww-submission-state';
            var icon = root.querySelector('svg');
            if (icon) {
                node.appendChild(icon.cloneNode(true));
            }
            var label = document.createElement('span');
            label.className = 'ww-net-word';
            node.appendChild(label);
            host.insertBefore(node, host.firstChild);
        }
        var text = node.querySelector('.ww-net-word');
        text.textContent = word;
        node.hidden = !word;
    }

    /**
     * @param {HTMLFormElement|null} form
     * @param {string} state
     */
    function showState(form, state) {
        var host = form || document.getElementById('region-main') || root;
        if (state === 'sent') {
            showWord(host, sentWord);
        } else if (state === 'waiting') {
            showWord(host, waitingWord);
        }
    }

    /**
     * @returns {string}
     */
    function editPage() {
        var url = new URL(window.location.href);
        url.searchParams.delete('sesskey');
        url.searchParams.set('action', 'editsubmission');
        return url.toString();
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {string}
     */
    function cmidFrom(form) {
        var field = form.querySelector('[name="id"]');
        if (field && field.value) {
            return field.value;
        }
        return new URL(window.location.href).searchParams.get('id') || '';
    }

    /**
     * @param {HTMLFormElement} form
     * @returns {string}
     */
    /**
     * @param {HTMLFormElement} form
     * @returns {string}
     */
    function readEditor(form) {
        var area = form.querySelector('[name="onlinetext_editor[text]"]');
        if (!area) {
            return '';
        }
        var editor = window.tinymce && area.id ? window.tinymce.get(area.id) : null;
        var fromEditor = editor ? wwOfflineQueue.toPlain(editor.getContent()) : '';
        var fromArea = wwOfflineQueue.toPlain(area.value);
        return fromEditor || fromArea;
    }

    /**
     * Put the student's words in the box. The stored value is plain text.
     * The rich editor, when it is open, is given paragraphs so it shows those words.
     *
     * @param {HTMLFormElement} form
     * @param {string} text
     */
    function writeEditor(form, text) {
        var area = form.querySelector('[name="onlinetext_editor[text]"]');
        if (!area) {
            return;
        }
        var plain = wwOfflineQueue.toPlain(text);
        area.value = plain;
        var editor = window.tinymce && area.id ? window.tinymce.get(area.id) : null;
        if (editor) {
            editor.setContent(wwOfflineQueue.toSubmissionHtml(plain));
            return;
        }
        area.hidden = false;
        area.style.removeProperty('display');
    }

    /**
     * @param {HTMLFormElement} form
     * @param {string} state
     * @returns {Promise<object>}
     */
    function saveLocal(form, state) {
        var cmid = cmidFrom(form);
        return wwOfflineDb.open().then(function(db) {
            var store = wwOfflineDb.submissions(db);
            return store.get(userId + ':' + cmid).then(function(existing) {
                var next = state;
                if (existing && existing.state === 'waiting' && state === 'draft') {
                    next = 'waiting';
                }
                var statement = form.querySelector('[name="submissionstatement"]');
                return wwOfflineQueue.enqueue(store, {
                    userId: userId,
                    cmid: cmid,
                    text: readEditor(form),
                    statement: statement && statement.checked,
                    page: editPage(),
                    state: next,
                });
            });
        });
    }

    /**
     * @param {object} row
     * @param {object} store
     * @returns {Promise<void>}
     */
    function sendRow(row, store) {
        if (!wwOfflineQueue.shouldSend(row) || !row.page) {
            return Promise.resolve();
        }
        return fetch(row.page, {credentials: 'same-origin'}).then(function(response) {
            return response.text().then(function(html) {
                if (!response.ok && wwOfflineQueue.isConfirmed(response.url, html) === false &&
                        /\/login\//.test(response.url)) {
                    return null;
                }
                var replay = wwOfflineQueue.buildReplay(html, row);
                if (!replay || !replay.action || !replay.fields.sesskey) {
                    return null;
                }
                var body = new URLSearchParams();
                Object.keys(replay.fields).forEach(function(name) {
                    body.append(name, replay.fields[name]);
                });
                return fetch(replay.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: body.toString(),
                    redirect: 'follow',
                });
            });
        }).then(function(posted) {
            if (!posted) {
                return null;
            }
            return posted.text().then(function(html) {
                if (!wwOfflineQueue.isConfirmed(posted.url, html)) {
                    return null;
                }
                return store.delete(wwOfflineQueue.key(row)).then(function() {
                    sessionStorage.setItem('ww-sent', row.cmid);
                    var view = new URL(row.page, window.location.origin);
                    view.searchParams.delete('sesskey');
                    view.searchParams.delete('action');
                    window.location.assign(view.toString());
                });
            });
        }).catch(function() {
            return null;
        });
    }

    /**
     * Send every submission that is still waiting.
     *
     * @returns {Promise<void>}
     */
    function flush() {
        if (!navigator.onLine) {
            return Promise.resolve();
        }
        return wwOfflineDb.open().then(function(db) {
            var store = wwOfflineDb.submissions(db);
            return wwOfflineQueue.pending(store, userId).then(function(rows) {
                var chain = Promise.resolve();
                rows.forEach(function(row) {
                    chain = chain.then(function() {
                        return sendRow(row, store);
                    });
                });
                return chain;
            });
        }).catch(function() {
            return null;
        });
    }

    /**
     * Put saved text back into the editor.
     */
    function restore() {
        var params = new URL(window.location.href).searchParams;
        var cmid = params.get('id') || '';
        if (sessionStorage.getItem('ww-sent')) {
            sessionStorage.removeItem('ww-sent');
            showState(null, 'sent');
        }
        if (!cmid) {
            return;
        }
        wwOfflineDb.open().then(function(db) {
            return wwOfflineDb.submissions(db).get(userId + ':' + cmid);
        }).then(function(row) {
            if (!row) {
                return;
            }
            var area = document.querySelector('[name="onlinetext_editor[text]"]');
            var form = area ? area.form : null;
            if (form && row.text) {
                writeEditor(form, row.text);
            }
            if (row.state === 'waiting') {
                showState(form, 'waiting');
            }
        }).catch(function() {
            return null;
        });
    }

    function prefetch() {
        var node = document.getElementById('ww-offline-prefetch');
        if (!node || !navigator.serviceWorker.controller) {
            return;
        }
        var urls = [];
        try {
            urls = JSON.parse(node.textContent || '[]');
        } catch (e) {
            return;
        }
        if (!urls.length) {
            return;
        }
        navigator.serviceWorker.controller.postMessage({
            type: 'prefetch',
            userId: userId,
            urls: urls,
        });
    }

    function register() {
        if (!('serviceWorker' in navigator) || !window.isSecureContext) {
            return;
        }
        wwOfflineDb.open().then(function(db) {
            return wwOfflineDb.meta(db).set('user', userId);
        }).then(function() {
            return navigator.serviceWorker.register(swUrl, {scope: scope});
        }).then(function() {
            if (navigator.onLine && !navigator.serviceWorker.controller && !sessionStorage.getItem('ww-sw')) {
                sessionStorage.setItem('ww-sw', '1');
                window.location.reload();
            }
        }).catch(function() {
            return null;
        });
    }

    lazyImages();
    setOffline(!navigator.onLine);
    restore();
    register();

    window.addEventListener('offline', function() {
        setOffline(true);
        var areas = document.querySelectorAll('[name="onlinetext_editor[text]"]');
        var i;
        for (i = 0; i < areas.length; i++) {
            if (areas[i].form) {
                writeEditor(areas[i].form, readEditor(areas[i].form));
            }
        }
    });
    window.addEventListener('online', function() {
        setOffline(false);
        flush();
    });
    window.addEventListener('load', function() {
        lazyImages();
        prefetch();
        flush();
    });

    document.addEventListener('click', function(event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href*="logout.php"]') : null;
        if (!link) {
            return;
        }
        wwOfflineDb.open().then(function(db) {
            return wwOfflineQueue.clearUser(wwOfflineDb.submissions(db), userId).then(function() {
                return wwOfflineDb.meta(db).delete('user');
            });
        }).catch(function() {
            return null;
        });
        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({type: 'logout', userId: userId});
        }
    });

    document.addEventListener('input', function(event) {
        var area = event.target;
        if (!area || area.name !== 'onlinetext_editor[text]' || navigator.onLine || !area.form) {
            return;
        }
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(function() {
            saveLocal(area.form, 'draft');
        }, 400);
    });

    document.addEventListener('submit', function(event) {
        var form = event.target;
        if (!form || !form.querySelector || !form.querySelector('[name="onlinetext_editor[text]"]')) {
            return;
        }
        if (navigator.onLine) {
            return;
        }
        event.preventDefault();
        saveLocal(form, 'waiting').then(function() {
            showState(form, 'waiting');
        });
    }, true);
})();
