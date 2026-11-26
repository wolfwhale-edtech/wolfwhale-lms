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
 * Package address rewriting.
 *
 * @copyright  2026 WolfWhale EdTech
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const viewer = require('./viewer.js');

describe('library package addresses', () => {
    it('keeps a script address inside the package and drops a script URL', () => {
        const urls = {
            'app.js': 'blob:app',
            'css/room.css': 'blob:css',
            'images/room.png': 'blob:png',
        };
        const html = '<link href="css/room.css"><script src="app.js"></script><img src="images/room.png"><a href="javascript:alert(1)">x</a>';
        const rewritten = viewer.rewrite(html, 'index.html', urls);
        expect(rewritten).toContain('href="blob:css"');
        expect(rewritten).toContain('src="blob:app"');
        expect(rewritten).toContain('src="blob:png"');
        expect(rewritten).toContain('href="#"');
        expect(rewritten).not.toContain('javascript:');
    });

    it('leaves an https address alone and refuses a path that climbs out', () => {
        expect(viewer.resolve('index.html', 'https://example.com/map')).toBeNull();
        expect(viewer.resolve('index.html', '../secret.html')).toBe(false);
        expect(viewer.resolve('css/room.css', '../images/room.png')).toBe('images/room.png');
    });

    it('rewrites a style url from the file that contains it', () => {
        const css = 'body{background:url("../images/room.png")}';
        const rewritten = viewer.rewrite(css, 'css/room.css', {'images/room.png': 'blob:png'});
        expect(rewritten).toBe('body{background:url("blob:png")}');
    });

    it('lets a script fetch packaged data and import a packaged module', async () => {
        const html = viewer.build([
            {
                path: 'index.html',
                text: '<!doctype html><body><script src="app.js"></script><script type="module" src="mod.js"></script></body>',
            },
            {
                path: 'app.js',
                text: 'fetch("data.json").then(function(response){return response.text();}).then(function(text){document.body.setAttribute("data-got", text);});',
            },
            {path: 'data.json', text: '{"room":1}'},
            {path: 'mod.js', text: 'import {value} from "./lib.js"; document.body.setAttribute("data-mod", String(value));'},
            {path: 'lib.js', text: 'export const value = 7;'},
        ], 'index.html');
        expect(html.indexOf('<script>(function(){')).toBeLessThan(html.indexOf('src="data:text/javascript'));
        expect(html).toContain('data.json');
        expect(html).not.toContain('blob:');

        const decoded = decodeURIComponent(html);
        const imported = decoded.match(/import \{value\} from "(data:text\/javascript[^"]+)"/);
        expect(imported).not.toBeNull();
        const library = decodeURIComponent(imported[1].split(',').slice(1).join(','));
        expect(library).toContain('export const value = 7');

        const util = require('util');
        global.TextEncoder = util.TextEncoder;
        global.TextDecoder = util.TextDecoder;
        const {JSDOM} = require('jsdom');
        const dom = new JSDOM(html, {
            runScripts: 'dangerously',
            resources: 'usable',
            url: 'https://school.example/index.html',
        });
        await new Promise((resolve) => setTimeout(resolve, 300));
        expect(dom.window.document.body.getAttribute('data-got')).toBe('{"room":1}');
    });
});
