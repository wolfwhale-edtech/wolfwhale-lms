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
 * Offline cache for one signed-in student.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

importScripts(self.WW_SCRIPT_BASE + 'db.js');
importScripts(self.WW_SCRIPT_BASE + 'rules.js');
importScripts(self.WW_SCRIPT_BASE + 'queue.js');

var userMemo = null;

/**
 * @returns {Promise<string|null>}
 */
function currentUser() {
    if (userMemo) {
        return Promise.resolve(userMemo);
    }
    return wwOfflineDb.open().then(function(db) {
        return wwOfflineDb.meta(db).get('user');
    }).then(function(id) {
        userMemo = id ? String(id) : null;
        return userMemo;
    }).catch(function() {
        return null;
    });
}

/**
 * @param {string|number|null} userId
 * @returns {Promise<void>}
 */
function clearPerson(userId) {
    if (!userId) {
        return Promise.resolve();
    }
    userId = String(userId);
    return wwOfflineDb.open().then(function(db) {
        return wwOfflineQueue.clearUser(wwOfflineDb.submissions(db), userId).then(function() {
            return wwOfflineQueue.clearSettings(wwOfflineDb.meta(db), userId);
        }).then(function() {
            return wwOfflineDb.meta(db).get('user').then(function(current) {
                if (String(current) === userId) {
                    return wwOfflineDb.meta(db).delete('user');
                }
                return null;
            });
        });
    }).then(function() {
        if (userMemo === userId) {
            userMemo = null;
        }
        return wwOfflineRules.applyLogout(caches, userId);
    }).catch(function() {
        return null;
    });
}

/**
 * @param {string|number} userId
 * @returns {Promise<void>}
 */
function setUser(userId) {
    var next = userId ? String(userId) : '';
    return currentUser().then(function(previous) {
        var cleared = previous && previous !== next ? clearPerson(previous) : Promise.resolve();
        return cleared.then(function() {
            userMemo = next || null;
            return wwOfflineDb.open().then(function(db) {
                if (next) {
                    return wwOfflineDb.meta(db).set('user', next);
                }
                return wwOfflineDb.meta(db).delete('user');
            });
        });
    });
}

/**
 * @param {Headers} source
 * @returns {Headers}
 */
function headersForCache(source) {
    var headers = new Headers(source);
    headers.delete('set-cookie');
    headers.delete('content-length');
    var vary = headers.get('vary');
    if (vary && vary.indexOf('*') !== -1) {
        headers.delete('vary');
    }
    return headers;
}

/**
 * @param {string} cacheKey
 * @param {Response} response
 * @param {string|null} userId
 * @returns {Promise<void>}
 */
/**
 * The read page can stand in for the real activity address, which is not fetched.
 *
 * @param {string} cacheKey
 * @param {Response} response
 * @returns {string|null}
 */
function lessonAlias(cacheKey, response) {
    if (!/\/local\/wwoffline\/lesson\.php/.test(cacheKey)) {
        return null;
    }
    var alias = response.headers.get('X-WW-Alias');
    if (!alias) {
        return null;
    }
    try {
        var url = new URL(alias, self.location.origin);
        if (url.origin !== self.location.origin) {
            return null;
        }
        if (!/\/mod\/(?:page|book|resource)\/view\.php$/.test(url.pathname)) {
            return null;
        }
        return url.toString();
    } catch (e) {
        return null;
    }
}

function storeResponse(cacheKey, response, userId) {
    if (!response || !response.ok || response.type !== 'basic') {
        return Promise.resolve();
    }
    var contentType = response.headers.get('content-type') || '';
    var readBody = contentType.indexOf('text/html') !== -1 ? response.clone().text() : Promise.resolve(null);
    return readBody.then(function(body) {
        var plan = wwOfflineRules.planStore({
            url: cacheKey,
            finalUrl: response.url,
            method: 'GET',
            userId: userId,
            ownerId: response.headers.get('X-WW-User'),
            body: body,
        });
        if (!plan.store) {
            return null;
        }
        var storedBody = plan.body === null ? response.clone().blob() : Promise.resolve(plan.body);
        return storedBody.then(function(payload) {
            var stored = new Response(payload, {
                status: response.status,
                statusText: response.statusText,
                headers: headersForCache(response.headers),
            });
            return caches.open(plan.cacheName).then(function(cache) {
                var writes = [cache.put(new Request(cacheKey), stored.clone())];
                if (response.url && response.url !== cacheKey) {
                    writes.push(cache.put(new Request(response.url), stored.clone()));
                }
                var alias = lessonAlias(cacheKey, response);
                if (alias) {
                    writes.push(cache.put(new Request(alias), stored.clone()));
                }
                return Promise.all(writes);
            });
        });
    }).catch(function() {
        return null;
    });
}

/**
 * @param {Request} request
 * @returns {Promise<Response>}
 */
function shell(request) {
    var name = wwOfflineRules.cacheNameFor('shell', null);
    return caches.open(name).then(function(cache) {
        return cache.match(request).then(function(cached) {
            var update = fetch(request).then(function(response) {
                return storeResponse(request.url, response, null).then(function() {
                    return response;
                });
            }).catch(function() {
                return cached || Promise.reject(new Error('offline'));
            });
            if (cached) {
                update.catch(function() {
                    return null;
                });
                return cached;
            }
            return update;
        });
    });
}

/**
 * @param {Request} request
 * @returns {Promise<Response>}
 */
function person(request) {
    return currentUser().then(function(userId) {
        return fetch(request).then(function(response) {
            return storeResponse(request.url, response, userId).then(function() {
                return response;
            });
        }).catch(function() {
            var name = wwOfflineRules.cacheNameFor('home', userId);
            if (!name) {
                return Promise.reject(new Error('offline'));
            }
            return caches.open(name).then(function(cache) {
                return cache.match(request).then(function(cached) {
                    if (cached) {
                        return cached;
                    }
                    return Promise.reject(new Error('offline'));
                });
            });
        });
    });
}

/**
 * @param {string} html
 * @param {string} base
 * @returns {string[]}
 */
function assetUrls(html, base) {
    var found = [];
    var re = /\b(?:src|href)\s*=\s*["']([^"']+)["']/gi;
    var match;
    while ((match = re.exec(html))) {
        try {
            var absolute = new URL(match[1], base).toString();
            if (absolute.indexOf(self.location.origin) === 0) {
                found.push(absolute);
            }
        } catch (e) {
            // Skip a bad address.
        }
    }
    return found;
}

/**
 * @param {string[]} urls
 * @returns {Promise<void>}
 */
function prefetch(urls) {
    return currentUser().then(function(userId) {
        var list = (urls || []).slice(0, 20);
        var chain = Promise.resolve();
        list.forEach(function(url) {
            chain = chain.then(function() {
                var kind = wwOfflineRules.classify(url, 'GET');
                if (kind !== 'home' && kind !== 'settings' && kind !== 'lesson' && kind !== 'lessonfile') {
                    return null;
                }
                return fetch(url, {credentials: 'include'}).then(function(response) {
                    return storeResponse(url, response, userId).then(function() {
                        if (kind !== 'lesson' && kind !== 'home') {
                            return null;
                        }
                        return response.clone().text().then(function(html) {
                            var extra = assetUrls(html, response.url).slice(0, 20);
                            var files = Promise.resolve();
                            extra.forEach(function(asset) {
                                files = files.then(function() {
                                    var assetKind = wwOfflineRules.classify(asset, 'GET');
                                    if (assetKind !== 'lessonfile' && assetKind !== 'shell') {
                                        return null;
                                    }
                                    return fetch(asset, {credentials: 'include'}).then(function(file) {
                                        return storeResponse(asset, file, userId);
                                    });
                                });
                            });
                            return files;
                        });
                    });
                }).catch(function() {
                    return null;
                });
            });
        });
        return chain;
    });
}

self.addEventListener('install', function() {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(Promise.all([
        self.clients.claim(),
        caches.keys().then(function(names) {
            return Promise.all(names.map(function(name) {
                var ours = name.indexOf('ww-shell-') === 0 || name.indexOf('ww-user-') === 0;
                if (ours && name.indexOf(wwOfflineRules.VERSION) === -1) {
                    return caches.delete(name);
                }
                return null;
            }));
        }),
    ]));
});

self.addEventListener('message', function(event) {
    var data = event.data || {};
    if (data.type === 'user' || data.type === 'prefetch') {
        var task = setUser(data.userId);
        if (data.type === 'prefetch') {
            task = task.then(function() {
                return prefetch(data.urls || []);
            });
        }
        event.waitUntil(task);
    } else if (data.type === 'logout') {
        event.waitUntil(clearPerson(data.userId));
    }
});

self.addEventListener('fetch', function(event) {
    var url;
    try {
        url = new URL(event.request.url);
    } catch (e) {
        return;
    }
    if (url.origin !== self.location.origin) {
        return;
    }
    var kind = wwOfflineRules.classify(event.request.url, event.request.method);
    if (kind === 'logout') {
        event.respondWith(currentUser().then(function(userId) {
            return clearPerson(userId);
        }).then(function() {
            return fetch(event.request);
        }));
        return;
    }
    if (kind === 'shell') {
        event.respondWith(shell(event.request));
        return;
    }
    if (kind === 'home' || kind === 'settings' || kind === 'lesson' || kind === 'lessonfile' || kind === 'userfile') {
        event.respondWith(person(event.request));
    }
});
