const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const view = fs.readFileSync(path.join(__dirname, '..', '..', 'resources', 'views', 'layouts', 'partials', 'eis-terminal-block.blade.php'), 'utf8');
const initialTerminal = {device_id: 'T1', message: '<img src=x onerror=alert(1)> Contact MRA.'};
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace("@json(route('eis.terminal-block-status'))", "'/eis/terminal-block-status'")
    .replace('@json($blockedTerminals)', JSON.stringify([initialTerminal]));
let poll;
let response;
let warnings = [];
let stopped = false;
const toasts = [];
const context = {
    toastr: {error(message, title, options) { toasts.push({message, title, options}); }},
    window: {
        addEventListener(event, callback) { callback(); },
        setInterval(callback) { poll = callback; return 1; },
        clearInterval() { stopped = true; },
    },
    document: {
        hidden: false,
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
    assert.strictEqual(toasts.length, 1, 'Already blocked terminals show a toast on page load');
    assert.strictEqual(toasts[0].message, 'EIS terminal T1 blocked. <img src=x onerror=alert(1)> Contact MRA.');
    assert.strictEqual(toasts[0].title, 'EIS terminal blocked');
    assert.strictEqual(toasts[0].options.escapeHtml, true, 'MRA messages must be escaped as text');
    response = {ok: true, json: async () => ({terminals: [initialTerminal]})};
    poll();
    await tick();
    assert.strictEqual(toasts.length, 1, 'Unchanged polling must not repeat the toast');
    response = {ok: true, json: async () => ({terminals: [{device_id: 'T1', message: 'Updated MRA reason.'}]})};
    poll();
    await tick();
    assert.strictEqual(toasts.length, 2);
    assert(toasts[1].message.includes('Updated MRA reason.'));
    response = {ok: false, status: 503};
    poll();
    await tick();
    assert.strictEqual(toasts.length, 2);
    assert(warnings.some(message => message.includes('503')));
    response = {ok: true, json: async () => ({terminals: []})};
    poll();
    await tick();
    assert.strictEqual(toasts.length, 2, 'Unblocking does not display a block toast');
    response = {ok: true, json: async () => ({terminals: [initialTerminal, {device_id: 'T2', message: 'Second terminal blocked.'}]})};
    poll();
    await tick();
    assert.strictEqual(toasts.length, 3, 'Blocking again displays a new toast');
    assert(toasts[2].message.includes('T1 blocked.') && toasts[2].message.includes('T2 blocked.'));
    response = {ok: false, status: 401};
    poll();
    await tick();
    assert.strictEqual(stopped, true);
    console.log('PASS: terminal-block toasts show on load/changes, escape reasons, avoid repeat polling notifications, and reset after unblock.');
})().catch(error => { console.error(error); process.exitCode = 1; });
