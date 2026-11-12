const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const view = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'views', 'layouts', 'partials', 'eis-terminal-block.blade.php'), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace("@json(route('eis.terminal-block-status'))", "'/eis/terminal-block-status'");
let poll;
let response;
let warnings = [];
let stopped = false;
const notices = {children: [], replaceChildren(...children) { this.children = children; }};
const context = {
    window: {
        addEventListener(event, callback) { callback(); },
        setInterval(callback) { poll = callback; return 1; },
        clearInterval() { stopped = true; },
    },
    document: {
        hidden: false,
        getElementById() { return notices; },
        createElement() { return {setAttribute() {}}; },
    },
    fetch: async (url, options) => {
        assert.strictEqual(url, '/eis/terminal-block-status');
        assert.strictEqual(options.credentials, 'same-origin');
        return response;
    },
    console: {warn(message) { warnings.push(message); }},
};
vm.runInNewContext(script, context);
const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
    response = {ok: true, json: async () => ({terminals: [{device_id: 'T1', message: '<img src=x onerror=alert(1)> Contact MRA.'}]})};
    poll();
    await tick();
    assert.strictEqual(notices.children.length, 1);
    assert.strictEqual(notices.children[0].textContent, 'EIS terminal T1 blocked. <img src=x onerror=alert(1)> Contact MRA.');
    assert.strictEqual(notices.children[0].innerHTML, undefined);
    const saved = notices.children[0];
    response = {ok: false, status: 503};
    poll();
    await tick();
    assert.strictEqual(notices.children[0], saved);
    assert(warnings.some(message => message.includes('503')));
    response = {ok: true, json: async () => ({terminals: []})};
    poll();
    await tick();
    assert.strictEqual(notices.children.length, 0);
    response = {ok: false, status: 401};
    poll();
    await tick();
    assert.strictEqual(stopped, true);
    console.log('PASS: terminal block notices refresh, safely display messages, preserve warnings on failure, and clear after unblock.');
})().catch(error => { console.error(error); process.exitCode = 1; });
