/* Run: node tests/navigation.js. No npm dependencies. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const src = fs.readFileSync(require('node:path').join(__dirname, '..', 'script.js'), 'utf8');
const start = src.indexOf('        function confirmLeaveNewUserResult() {');
const end = src.indexOf('        function showDiff(', start);
assert(start !== -1 && end > start, 'navigation helpers not found');
const helpers = src.slice(start, end);
function scenario({ created = false, generated = false, active = 'new', dirty = true, approve = true } = {}) {
    const notices = [];
    const env = {
        state: { active, newCreated: created, generatedPasswordVisible: generated },
        userEdits: () => [],
        groupEdits: () => [],
        newHasDraft: () => dirty,
        window: { confirm: (text) => { notices.push(text); return approve; } },
    };
    const result = vm.runInNewContext(helpers + '\nconfirmDiscard();', env);
    return { result, notices };
}
let actual = scenario({ created: true, generated: false });
assert.equal(actual.result, true, 'a created manual-password account should be safe to leave');
assert.equal(actual.notices.length, 0, 'manual-password account should not prompt');
actual = scenario({ created: true, generated: true });
assert.equal(actual.result, true);
assert.equal(actual.notices.length, 1);
assert.match(actual.notices[0], /generated password/i);
assert.doesNotMatch(actual.notices[0], /unsaved changes/i);
actual = scenario({ created: true, generated: true, approve: false });
assert.equal(actual.result, false, 'a generated credential warning must block departure when cancelled');
actual = scenario({ created: false, generated: false, dirty: true });
assert.equal(actual.notices.length, 1, 'unfinished new-user form should warn');
assert.match(actual.notices[0], /unsaved changes/i);
actual = scenario({ created: false, generated: false, dirty: false });
assert.equal(actual.notices.length, 0, 'clean new-user form should not warn');
assert(src.includes("state.generatedPasswordVisible = created.passwordMode === 'generate'"), 'credential warning must depend on actual creation mode');
assert(src.includes("if (state.active === 'new') resetNew();"), 'leaving the New User tab must clear creation state');
assert(src.includes("if (state.newCreated ? confirmLeaveNewUserResult()"), 'Reset should follow the same credential safeguard');
console.log('PASS: manual/generated success navigation, unfinished drafts, credential warning and Reset guards');
