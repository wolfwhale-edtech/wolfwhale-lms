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
 * Cache rules: one cache per person, logout clears it, sign-in and session keys stay out.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const rules = require('./rules.js');

const HOME = 'https://school.test/local/wolfwhale/index.php';
const LESSON = 'https://school.test/mod/page/view.php?id=4';

/**
 * @returns {object}
 */
function memoryCaches() {
    const boxes = {};
    return {
        boxes,
        open(name) {
            if (!boxes[name]) {
                boxes[name] = {};
            }
            return Promise.resolve({
                put(url, body) {
                    boxes[name][url] = body;
                    return Promise.resolve();
                },
            });
        },
        delete(name) {
            const had = Object.prototype.hasOwnProperty.call(boxes, name);
            delete boxes[name];
            return Promise.resolve(had);
        },
    };
}

describe('offline cache rules', () => {
    it('keeps each person in their own cache', async() => {
        const caches = memoryCaches();
        const ada = rules.cacheNameFor('home', 1);
        const bee = rules.cacheNameFor('home', 2);
        expect(ada).not.toBe(bee);

        await rules.remember(caches, {
            url: HOME,
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<html>Ada home</html>',
        });
        await rules.remember(caches, {
            url: HOME,
            method: 'GET',
            userId: 2,
            ownerId: 2,
            body: '<html>Bee home</html>',
        });

        expect(caches.boxes[ada][HOME]).toContain('Ada home');
        expect(caches.boxes[ada][HOME]).not.toContain('Bee');
        expect(caches.boxes[bee][HOME]).toContain('Bee home');
        expect(caches.boxes[bee][HOME]).not.toContain('Ada');
    });

    it('does not store one person in another person\'s cache', async() => {
        const caches = memoryCaches();
        const plan = await rules.remember(caches, {
            url: HOME,
            method: 'GET',
            userId: 1,
            ownerId: 2,
            body: '<html>Bee home</html>',
        });
        expect(plan.store).toBe(false);
        expect(caches.boxes).toEqual({});
    });

    it('clears that person on logout and leaves the other person', async() => {
        const caches = memoryCaches();
        await rules.remember(caches, {
            url: HOME,
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<html>Ada home</html>',
        });
        await rules.remember(caches, {
            url: HOME,
            method: 'GET',
            userId: 2,
            ownerId: 2,
            body: '<html>Bee home</html>',
        });
        await rules.remember(caches, {
            url: 'https://school.test/theme/styles.php/wolfwhale/1/all',
            method: 'GET',
            bytes: 'body{}',
        });

        await rules.applyLogout(caches, 1);

        expect(caches.boxes[rules.cacheNameFor('home', 1)]).toBeUndefined();
        expect(caches.boxes[rules.cacheNameFor('home', 2)][HOME]).toContain('Bee home');
        expect(caches.boxes[rules.cacheNameFor('shell', null)]).toBeDefined();
    });

    it('never stores a picture sign-in page', async() => {
        const caches = memoryCaches();
        const urls = [
            'https://school.test/auth/wwpicture/login.php',
            'https://school.test/auth/wwpicture/enter.php?id=3',
            'https://school.test/auth/wwpicture/class.php?id=3',
            'https://school.test/auth/wwpicture/cards.php?id=3',
        ];
        for (const url of urls) {
            expect(rules.classify(url, 'GET')).toBe('never');
            const plan = await rules.remember(caches, {
                url,
                method: 'GET',
                userId: 1,
                ownerId: 1,
                body: '<html>Picture sign-in</html>',
            });
            expect(plan.store).toBe(false);
        }
        const followed = rules.planStore({
            url: HOME,
            finalUrl: 'https://school.test/auth/wwpicture/login.php',
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<html>Picture sign-in</html>',
        });
        expect(followed.store).toBe(false);
        expect(caches.boxes).toEqual({});
    });

    it('never stores a sign-in page', async() => {
        const caches = memoryCaches();
        const plan = await rules.remember(caches, {
            url: 'https://school.test/login/index.php',
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<form><input name="username"><input name="password"></form>',
        });
        expect(rules.classify('https://school.test/login/index.php', 'GET')).toBe('never');
        expect(plan.store).toBe(false);
        expect(caches.boxes).toEqual({});
    });

    it('stores parent settings without the session key in the form', async() => {
        const caches = memoryCaches();
        const url = 'https://school.test/local/wwupdates/preferences.php';
        const plan = await rules.remember(caches, {
            url,
            method: 'GET',
            userId: 4,
            ownerId: 4,
            body: '<form class="ww-updates" method="post">' +
                '<input type="hidden" name="sesskey" value="SECRET">' +
                '<input type="tel" name="phone" value="+15555550100">' +
                '</form><script>M.cfg={"sesskey":"SECRET"};</script>',
        });
        const stored = caches.boxes[rules.cacheNameFor('settings', 4)][url];
        expect(rules.classify(url, 'GET')).toBe('settings');
        expect(plan.store).toBe(true);
        expect(stored).not.toContain('SECRET');
        expect(stored).toContain('+15555550100');
        expect(rules.formHasSesskey(stored)).toBe(false);
        expect(rules.hasLiveSesskey(stored)).toBe(false);
    });

    it('never stores the settings page when the address carries a session key', async() => {
        const caches = memoryCaches();
        const url = 'https://school.test/local/wwupdates/preferences.php?sesskey=SECRET';
        expect(rules.classify(url, 'GET')).toBe('never');
        const plan = await rules.remember(caches, {
            url,
            method: 'GET',
            userId: 4,
            ownerId: 4,
            body: '<form class="ww-updates"></form>',
        });
        expect(plan.store).toBe(false);
        expect(caches.boxes).toEqual({});
    });

    it('never stores an address that carries a session key', async() => {
        const caches = memoryCaches();
        const url = LESSON + '&sesskey=SECRET';
        const plan = await rules.remember(caches, {
            url,
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<html>Lesson</html>',
        });
        expect(plan.store).toBe(false);
        expect(caches.boxes).toEqual({});
    });

    it('stores a lesson without the session key that was in a form', async() => {
        const caches = memoryCaches();
        const plan = await rules.remember(caches, {
            url: LESSON,
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<a href="/login/logout.php?sesskey=SECRET">Out</a>' +
                '<form action="/mod/page/view.php"><input type="hidden" name="sesskey" value="SECRET"></form>' +
                '<script>M.cfg={"sesskey":"SECRET"};</script>',
        });
        expect(plan.store).toBe(true);
        expect(caches.boxes[rules.cacheNameFor('lesson', 1)][LESSON]).not.toContain('SECRET');
        expect(rules.formHasSesskey(caches.boxes[rules.cacheNameFor('lesson', 1)][LESSON])).toBe(false);
    });

    it('does not store a page that is still a sign-in form', async() => {
        const plan = rules.planStore({
            url: HOME,
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<form><input name="username" value="a"><input name="password" value="b"></form>',
        });
        expect(plan.store).toBe(false);
    });

    it('does not store a response that followed a redirect to sign-in', () => {
        const plan = rules.planStore({
            url: HOME,
            finalUrl: 'https://school.test/login/index.php',
            method: 'GET',
            userId: 1,
            ownerId: 1,
            body: '<html>Sign in</html>',
        });
        expect(plan.store).toBe(false);
    });
});
