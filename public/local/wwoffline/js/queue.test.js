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
 * Queued assignment text: no session key stored, fresh key on send, no fake success.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const queue = require('./queue.js');

const FORM = [
    '<form action="https://school.test/mod/assign/view.php" method="post">',
    '<input type="hidden" name="sesskey" value="FRESHKEY" />',
    '<input type="hidden" name="id" value="8" />',
    '<input type="hidden" name="userid" value="4" />',
    '<input type="hidden" name="action" value="savesubmission" />',
    '<input type="hidden" name="_qf__mod_assign_submission_form" value="1" />',
    '<input type="hidden" name="lastmodified" value="20" />',
    '<textarea name="onlinetext_editor[text]">old</textarea>',
    '<input type="hidden" name="onlinetext_editor[format]" value="1" />',
    '<input type="hidden" name="onlinetext_editor[itemid]" value="3" />',
    '<input type="submit" name="submitbutton" value="Save changes" />',
    '</form>',
].join('');

/**
 * @returns {object}
 */
function memoryStore() {
    const rows = {};
    return {
        rows,
        get(key) {
            return Promise.resolve(Object.prototype.hasOwnProperty.call(rows, key) ? rows[key] : null);
        },
        set(key, value) {
            rows[key] = value;
            return Promise.resolve();
        },
        delete(key) {
            delete rows[key];
            return Promise.resolve();
        },
        keys() {
            return Promise.resolve(Object.keys(rows));
        },
    };
}

describe('queued assignment text', () => {
    it('saves the text without a session key', async() => {
        const store = memoryStore();
        const record = await queue.enqueue(store, {
            userId: 4,
            cmid: 8,
            text: '<p>River notes</p>',
            sesskey: 'SECRET',
            page: 'https://school.test/mod/assign/view.php?id=8&action=editsubmission&sesskey=SECRET',
            state: 'waiting',
        });

        expect(record.sesskey).toBeUndefined();
        expect(JSON.stringify(record)).not.toContain('SECRET');
        expect(record.page).not.toContain('sesskey');
        expect(record.text).toBe('River notes');
        expect(record.text).not.toContain('<');
        expect(record.state).toBe('waiting');
        expect(queue.shouldSend(record)).toBe(true);
    });

    it('keeps two people apart and logout clears one of them', async() => {
        const store = memoryStore();
        await queue.enqueue(store, {userId: 4, cmid: 8, text: 'Ada', state: 'waiting'});
        await queue.enqueue(store, {userId: 5, cmid: 8, text: 'Bee', state: 'waiting'});
        await queue.clearUser(store, 4);

        expect(await store.get('4:8')).toBeNull();
        expect((await store.get('5:8')).text).toBe('Bee');
    });

    it('keeps the student words and sends them as paragraphs', () => {
        expect(queue.toPlain('<p>Fake river notes written offline.</p>')).toBe('Fake river notes written offline.');
        expect(queue.toPlain('<p>One</p><p>Two</p>')).toBe('One\n\nTwo');
        expect(queue.toSubmissionHtml('Fake river notes written offline.')).toBe('<p>Fake river notes written offline.</p>');
        expect(queue.toSubmissionHtml('One\n\nTwo')).toBe('<p>One</p><p>Two</p>');
        expect(queue.toSubmissionHtml('1 < 2')).toBe('<p>1 &lt; 2</p>');
    });

    it('does not send a draft or a row that still has a session key', () => {
        expect(queue.shouldSend({userId: '4', cmid: '8', state: 'draft', text: 'Ada'})).toBe(false);
        expect(queue.shouldSend({
            userId: '4',
            cmid: '8',
            state: 'waiting',
            text: 'Ada',
            sesskey: 'SECRET',
        })).toBe(false);
    });

    it('posts through the fresh form and its session key', () => {
        const replay = queue.buildReplay(FORM, {
            userId: 4,
            text: '<p>Offline river</p>',
            statement: '',
            page: 'https://school.test/mod/assign/view.php?id=8&action=editsubmission',
        });
        expect(replay.fields.sesskey).toBe('FRESHKEY');
        expect(replay.fields['onlinetext_editor[text]']).toBe('<p>Offline river</p>');
        expect(replay.fields.action).toBe('savesubmission');
        expect(replay.fields.submitbutton).toBe('Save changes');
        expect(replay.action).toBe('https://school.test/mod/assign/view.php');
    });

    it('does not send one student\'s text with another student\'s form', () => {
        const replay = queue.buildReplay(FORM, {
            userId: 9,
            text: 'River notes',
        });
        expect(replay).toBeNull();
    });

    it('does not treat a picture sign-in page as a finished assignment', () => {
        expect(queue.isConfirmed(
            'https://school.test/auth/wwpicture/login.php',
            '<div>Submitted for grading</div>',
        )).toBe(false);
        expect(queue.isConfirmed(
            'https://school.test/auth/wwpicture/enter.php?id=3',
            '<div>Submitted for grading</div>',
        )).toBe(false);
    });

    it('does not build a replay from a sign-in page', () => {
        const html = '<form><input name="username"><input name="password"><input name="sesskey" value="X"></form>';
        expect(queue.buildReplay(html, {text: 'River'})).toBeNull();
    });

    it('stays waiting until the server leaves the edit form', () => {
        expect(queue.isConfirmed('https://school.test/login/index.php', '<p>Sign in</p>')).toBe(false);
        expect(queue.isConfirmed(
            'https://school.test/mod/assign/view.php?id=8&action=editsubmission',
            FORM,
        )).toBe(false);
        expect(queue.isConfirmed(
            'https://school.test/mod/assign/view.php?id=8',
            FORM,
        )).toBe(false);
        expect(queue.isConfirmed(
            'https://school.test/mod/assign/view.php?id=8&action=view',
            '<div>invalidsesskey</div>',
        )).toBe(false);
        expect(queue.outcome(false)).toBe('waiting');

        expect(queue.isConfirmed(
            'https://school.test/mod/assign/view.php?id=8&action=view',
            '<div>River notes</div>',
        )).toBe(true);
        expect(queue.outcome(true)).toBe('sent');
    });
});
