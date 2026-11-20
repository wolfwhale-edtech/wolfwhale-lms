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
 * Browser store for the signed-in person and unsent assignment text.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

(function(root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    root.wwOfflineDb = api;
}(typeof self !== 'undefined' ? self : this, function() {
    var opening = null;

    /**
     * @returns {Promise<IDBDatabase>}
     */
    function open() {
        if (opening) {
            return opening;
        }
        opening = new Promise(function(resolve, reject) {
            var request = indexedDB.open('ww-offline', 1);
            request.onupgradeneeded = function() {
                var db = request.result;
                if (!db.objectStoreNames.contains('submissions')) {
                    db.createObjectStore('submissions');
                }
                if (!db.objectStoreNames.contains('meta')) {
                    db.createObjectStore('meta');
                }
            };
            request.onsuccess = function() {
                resolve(request.result);
            };
            request.onerror = function() {
                opening = null;
                reject(request.error);
            };
        });
        return opening;
    }

    /**
     * @param {IDBDatabase} db
     * @param {string} name
     * @param {string} mode
     * @param {function} fn
     * @returns {Promise<*>}
     */
    function run(db, name, mode, fn) {
        return new Promise(function(resolve, reject) {
            var transaction = db.transaction(name, mode);
            var request = fn(transaction.objectStore(name));
            request.onsuccess = function() {
                resolve(request.result === undefined ? null : request.result);
            };
            request.onerror = function() {
                reject(request.error);
            };
        });
    }

    /**
     * @param {IDBDatabase} db
     * @param {string} name
     * @returns {object}
     */
    function store(db, name) {
        return {
            get: function(key) {
                return run(db, name, 'readonly', function(box) {
                    return box.get(key);
                });
            },
            set: function(key, value) {
                return run(db, name, 'readwrite', function(box) {
                    return box.put(value, key);
                });
            },
            delete: function(key) {
                return run(db, name, 'readwrite', function(box) {
                    return box.delete(key);
                });
            },
            keys: function() {
                return run(db, name, 'readonly', function(box) {
                    return box.getAllKeys();
                });
            },
        };
    }

    return {
        open: open,
        submissions: function(db) {
            return store(db, 'submissions');
        },
        meta: function(db) {
            return store(db, 'meta');
        },
    };
}));
