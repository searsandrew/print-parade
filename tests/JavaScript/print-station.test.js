import assert from 'node:assert/strict';
import test from 'node:test';

globalThis.document = { addEventListener() {} };

const { createPrintStation } = await import('../../resources/js/print-station.js');

test('a successful submission clears job inputs but preserves reusable selections', async () => {
    const station = createPrintStation();
    station.catalog = {
        templates: [{
            id: 12,
            stock: { id: 4 },
            fields: {
                part_number: { type: 'text', default: null },
                replacement: { type: 'boolean', default: null },
            },
        }],
        printers: [],
        operators: [],
        authorization: { requires_operator_pin: true },
    };
    station.templateId = 12;
    station.printerId = 8;
    station.employeeId = 5;
    station.quantity = 24;
    station.pin = '2468';
    station.values = { part_number: 'CMM023', replacement: true };

    globalThis.document = {
        querySelector() {
            return { content: 'csrf-token' };
        },
    };
    globalThis.fetch = async () => ({
        ok: true,
        async json() {
            return { job_identifier: 'ABC123', quantity: 24 };
        },
    });

    await station.submit();

    assert.deepEqual(station.values, { part_number: '', replacement: false });
    assert.equal(station.quantity, 1);
    assert.equal(station.pin, '');
    assert.equal(station.templateId, 12);
    assert.equal(station.printerId, 8);
    assert.equal(station.employeeId, 5);
    assert.deepEqual(station.confirmation, { job_identifier: 'ABC123', quantity: 24 });
});
