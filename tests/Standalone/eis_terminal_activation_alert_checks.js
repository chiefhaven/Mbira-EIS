// Executes the shared activation UI without a browser, server or EIS requests.
const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '..', '..', 'Modules', 'EIS', 'Resources',
    'views', 'terminals', 'script.blade.php'), 'utf8');
const script = source.slice(source.indexOf('<script>') + 8, source.lastIndexOf('</script>'))
    .replace(/@json\(route\([^\n]+\)\)/g, '"fixture-url"');

function setup() {
    const elements = new Map();
    const requests = [];
    const alerts = [];
    let reloads = 0;
    function $(selector) {
        if (typeof selector === 'function') return selector();
        if (!elements.has(selector)) {
            const element = {
                value: '', attributes: {}, properties: {},
                attr(name, value) {
                    if (value === undefined) return this.attributes[name];
                    this.attributes[name] = value;
                    return this;
                },
                prop(name, value) { this.properties[name] = value; return this; },
                text(value) { this.message = value; return this; },
                val(value) {
                    if (value === undefined) return this.value;
                    this.value = value;
                    return this;
                },
                empty() { return this; },
                append() { return this; },
                appendTo() { return this; },
                click(handler) { this.handler = handler; return this; },
                on() { return this; },
            };
            elements.set(selector, element);
        }
        return elements.get(selector);
    }
    $.each = (items, callback) => Object.keys(items).forEach(key => callback(key, items[key]));
    $.ajax = options => {
        const request = {
            options,
            done(callback) { this.success = callback; return this; },
            fail(callback) { this.failure = callback; return this; },
            always(callback) { this.complete = callback; return this; },
            resolve(result) { this.success(result); this.complete(); },
            reject(result) { this.failure(result, 'error', ''); this.complete(); },
        };
        requests.push(request);
        return request;
    };
    vm.runInNewContext(script, {$, window: {location: {reload() { reloads++; }}}, swal: options => alerts.push(options)});
    requests[0].resolve({success: true, data: {terminals: []}});
    return {$, requests, alerts, reloadCount: () => reloads};
}

let checks = 0;
for (const failure of [
    {responseJSON: {message: 'This terminal belongs to a different trading name.'}},
    {responseJSON: {msg: 'This terminal belongs to a different taxpayer TIN.'}},
    {responseJSON: {message: 'Validation failed.', errors: {terminal_activation_code: ['Invalid activation code.']}}},
    {},
    {success: false, message: '<b>Different business</b>'},
]) {
    const {$, requests, alerts, reloadCount} = setup();
    $('#eis-activation-code').val('FOREIGN');
    $('#eis-activate-terminal').handler();
    const expected = failure.success === false ? failure.message
        : failure.responseJSON?.errors?.terminal_activation_code[0]
            || failure.responseJSON?.msg || failure.responseJSON?.message
            || 'Unable to complete terminal action.';
    if (failure.success === false) requests[1].resolve(failure);
    else requests[1].reject(failure);
    assert.equal(alerts.length, 1);
    assert.equal(alerts[0].icon, 'error');
    assert.equal(alerts[0].title, 'Terminal activation failed');
    assert.equal(alerts[0].text, expected);
    assert.equal($('#eis-management-message').message, expected);
    assert.equal($('#eis-activation-code').val(), 'FOREIGN');
    assert.equal($('#eis-terminal-management button').properties.disabled, false);
    assert.equal(requests.length, 2, 'Rejected activation must not reload the terminal list');
    assert.equal(reloadCount(), 0, 'Rejected activation must not reload the page');
    checks++;
}

{
    const {$, requests, alerts} = setup();
    $('#eis-activation-code').val('   ');
    $('#eis-activate-terminal').handler();
    assert.equal(alerts[0].text, 'Enter an activation code.');
    assert.equal(alerts[0].icon, 'error');
    assert.equal(requests.length, 1, 'Empty activation code must not send a request');
    checks++;
}
{
    const {$, requests, alerts, reloadCount} = setup();
    $('#eis-activation-code').val('VALID');
    $('#eis-activate-terminal').handler();
    requests[1].resolve({success: true, message: 'Activated'});
    assert.equal(alerts.length, 0);
    assert.equal($('#eis-activation-code').val(), '');
    assert.equal(requests.length, 2, 'Successful activation does not fetch only the terminal list');
    assert.equal(reloadCount(), 1, 'Successful activation reloads the whole page');
    assert.equal($('#eis-terminal-management button').properties.disabled, true);
    checks++;
}
{
    const {$, requests, alerts} = setup();
    $('#eis-refresh-terminals').handler();
    requests[1].reject({responseJSON: {message: 'Unable to list terminals.'}});
    assert.equal(alerts.length, 0, 'Non-activation actions retain their existing inline error behavior');
    assert.equal($('#eis-management-message').message, 'Unable to list terminals.');
    checks++;
}
console.log(`PASS: ${checks} terminal activation SweetAlert checks (mocked AJAX, DOM and dialogs).`);
