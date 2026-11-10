const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'js', 'pos.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    const end = source.indexOf('\n}', start) + 2;
    assert(start >= 0 && end > start);
    return source.slice(start, end);
}
const context = {
    __read_number: element => Number(element.value),
    __write_number: (element, value) => { element.value = value; },
    __calculate_amount: (type, rate, base) => rate * base / 100,
    __substract_percent: (base, rate) => base * (1 - rate / 100),
    __currency_trans_from_en: value => String(value),
};
vm.createContext(context);
vm.runInContext(extract('calculate_discounted_unit_price') + '\n' + extract('pos_each_row'), context);
let checks = 0;
for (const type of ['percentage', 'fixed']) {
    for (const rate of [0, 17.5]) {
        for (const discount of [0, 10, 100]) {
            const fields = {
                'input.pos_unit_price': { value: 100 },
                'input.pos_quantity': { value: 2 },
                'select.row_discount_type': { val: () => type },
                'input.row_discount_amount': { value: discount },
                'select.tax_id :selected': { data: () => rate },
                'input.base_unit_multiplier': { val: () => 3 },
                'input.item_tax': { value: 999 },
                'input.hidden_base_unit_item_tax': {},
                'input.pos_unit_price_inc_tax': {},
                'input.pos_line_total': {},
                'span.pos_line_total_text': { text(value) { this.value = value; } },
            };
            const row = { find: selector => {
                assert(fields[selector], 'Unknown field ' + selector);
                return fields[selector];
            } };
            context.pos_each_row(row);
            const net = 100 - discount;
            const tax = net * rate / 100;
            assert.strictEqual(fields['input.item_tax'].value, tax);
            assert.strictEqual(fields['input.pos_unit_price_inc_tax'].value, net + tax);
            assert.strictEqual(fields['input.pos_line_total'].value, 2 * (net + tax));
            assert.strictEqual(fields['input.hidden_base_unit_item_tax'].value, tax / 3);
            assert.strictEqual(fields['span.pos_line_total_text'].value, String(2 * (net + tax)));
            fields['input.row_discount_amount'].value = 0;
            context.pos_each_row(row);
            assert.strictEqual(fields['input.pos_line_total'].value, 200 * (1 + rate / 100));
            checks += 6;
        }
    }
}
console.log(`PASS: ${checks} POS fixed/percentage discount checks`);
