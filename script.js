/* DokuWiki Group Manager v0.3.0-alpha. No external JavaScript dependencies. */
(function () {
    'use strict';

    function linkClassicUserManager() {
        // DokuWiki loads enabled plugin scripts on all pages. Add a link without editing core User Manager.
        const classic = document.getElementById('user__manager');
        if (!classic || document.getElementById('rmsgm-classic-link')) return;
        const bar = document.createElement('p');
        bar.id = 'rmsgm-classic-link';
        const link = document.createElement('a');
        link.className = 'btn btn-default';
        link.textContent = 'Open Group Manager';
        const url = new URL(window.location.href);
        url.searchParams.set('do', 'admin');
        url.searchParams.set('page', 'groupmanager');
        link.href = url.toString();
        bar.appendChild(link);
        classic.parentNode.insertBefore(bar, classic);
    }

    function boot() {
        linkClassicUserManager();
        const root = document.getElementById('rmsgm');
        if (!root) return;
        const $ = (s) => root.querySelector(s);
        const state = {
            directory: null, user: '', group: '', userDraft: [], groupDraft: new Set(),
            templateId: '', templateDraft: [], newDraft: [], active: 'user', busy: false, newCreated: false
        };
        const e = {
            notice: $('.rmsgm-alert'), warning: $('.rmsgm-unsupported'),
            userSearch: $('#rmsgm-user-search'), userList: $('#rmsgm-user'), userTitle: $('#rmsgm-user-title'),
            userCurrent: $('#rmsgm-user-current'), userGroupSearch: $('#rmsgm-user-group-search'),
            userBoxes: $('#rmsgm-user-checkboxes'), userPreview: $('#rmsgm-user-preview'),
            userReset: $('#rmsgm-user-reset'), userSave: $('#rmsgm-user-save'), userOverride: $('#rmsgm-user-override'),
            applyTemplate: $('#rmsgm-apply-template'), apply: $('#rmsgm-apply'), compare: $('#rmsgm-compare'),
            templateList: $('#rmsgm-template-list'), templateName: $('#rmsgm-template-name'),
            templateGroups: $('#rmsgm-template-groups'), templateSave: $('#rmsgm-template-save'),
            templateDelete: $('#rmsgm-template-delete'),
            groupSearch: $('#rmsgm-group-search'), groupList: $('#rmsgm-group'), groupCount: $('#rmsgm-group-count'),
            groupTitle: $('#rmsgm-group-title'), groupDetail: $('#rmsgm-group-detail'), memberSearch: $('#rmsgm-member-search'),
            members: $('#rmsgm-members'), groupPreview: $('#rmsgm-group-preview'),
            groupReset: $('#rmsgm-group-reset'), groupSave: $('#rmsgm-group-save'), groupOverride: $('#rmsgm-group-override'),
            addVisible: $('#rmsgm-select-visible'), clearVisible: $('#rmsgm-clear-visible'),
            newGroup: $('#rmsgm-new-group'), createGroup: $('#rmsgm-create'), forget: $('#rmsgm-forget'),
            newOpen: $('#rmsgm-new-open'), newClose: $('#rmsgm-new-close'),
            newName: $('#rmsgm-new-name'), newUsername: $('#rmsgm-new-username'), newEmail: $('#rmsgm-new-email'),
            newTemplate: $('#rmsgm-new-template'), newGroups: $('#rmsgm-new-groups'), newPreview: $('#rmsgm-new-preview'),
            newReset: $('#rmsgm-new-reset'), newCreate: $('#rmsgm-new-create'), newCredential: $('#rmsgm-new-credential'),
            aclType: $('#rmsgm-acl-type'), aclPrincipal: $('#rmsgm-acl-principal'),
            aclExplicit: $('#rmsgm-acl-explicit'), aclRefresh: $('#rmsgm-acl-refresh'),
            aclTree: $('#rmsgm-acl-tree'), aclDetail: $('#rmsgm-acl-detail'), aclTreeStatus: $('#rmsgm-acl-tree-status')
        };
        const node = (tag, cls, text) => {
            const el = document.createElement(tag);
            if (cls) el.className = cls;
            if (text !== undefined) el.textContent = String(text);
            return el;
        };
        const clear = (el) => el.replaceChildren();
        const same = (a, b) => a.length === b.length && a.every((x) => b.includes(x));
        const unique = (arr) => [...new Set(arr)].sort((a, b) => a.localeCompare(b));
        const user = (id) => state.directory?.users.find((u) => u.id === id);
        const group = (name) => state.directory?.groups.find((g) => g.name === name);
        const template = (id) => state.directory?.templates.find((t) => t.id === id);
        const privilegedEditAllowed = (u, g, override) => {
            if (!state.directory.writable || u.backendProtected) return false;
            if (!override && (g.protected || u.privilegedAccount)) return false;
            if (override && u.id === state.directory.self && (g.protected || u.privilegedAccount)) return false;
            return true;
        };

        function message(text, kind) {
            e.notice.hidden = !text;
            e.notice.className = 'rmsgm-alert' + (kind ? ' ' + kind : '');
            e.notice.textContent = text || '';
        }
        function error(exc) { message(exc.message || String(exc), 'error'); }
        async function request(operation, params = {}) {
            const body = new URLSearchParams({ call: 'plugin_groupmanager', sectok: root.dataset.token, gm_action: operation });
            Object.entries(params).forEach(([key, val]) => body.set(key, String(val)));
            const response = await fetch(root.dataset.ajax, {
                method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store'
            });
            let json;
            try { json = await response.json(); }
            catch (exc) { throw new Error('Unexpected server response; check PHP/DokuWiki logs.'); }
            if (!response.ok || !json.ok) throw new Error(json.error || 'Server rejected the request.');
            return json.data;
        }
        function busy(value) {
            state.busy = value;
            for (const button of root.querySelectorAll('button')) button.disabled = value;
            if (!value) renderControls();
        }
        async function run(operation) {
            if (state.busy) return;
            busy(true);
            try { await operation(); } catch (exc) { error(exc); } finally { busy(false); }
        }
        function selectedTemplateId(select) { return select.value && template(select.value) ? select.value : ''; }
        function renderTemplateSelectors() {
            for (const select of [e.applyTemplate, e.newTemplate]) {
                const previous = select.value;
                clear(select);
                const empty = node('option', '', 'Choose a role template…');
                empty.value = '';
                select.appendChild(empty);
                state.directory.templates.forEach((t) => {
                    const option = node('option', '', t.name + ' (' + t.groups.length + ' groups)');
                    option.value = t.id;
                    select.appendChild(option);
                });
                select.value = template(previous) ? previous : '';
            }
            const previous = state.templateId;
            clear(e.templateList);
            const option = node('option', '', '— New template —');
            option.value = '';
            e.templateList.appendChild(option);
            state.directory.templates.forEach((t) => {
                const opt = node('option', '', t.name);
                opt.value = t.id;
                e.templateList.appendChild(opt);
            });
            state.templateId = template(previous) ? previous : '';
            e.templateList.value = state.templateId;
        }
        function loadTemplate(id) {
            state.templateId = id;
            const t = template(id);
            e.templateList.value = t ? id : '';
            e.templateName.value = t ? t.name : '';
            state.templateDraft = t ? t.groups.slice() : [];
            renderTemplateEditor();
        }
        function renderTemplateEditor() {
            clear(e.templateGroups);
            state.directory.groups.filter((g) => !g.protected).forEach((g) => {
                const label = node('label');
                const input = node('input');
                input.type = 'checkbox';
                input.checked = state.templateDraft.includes(g.name);
                input.addEventListener('change', () => {
                    state.templateDraft = input.checked ? unique([...state.templateDraft, g.name]) :
                        state.templateDraft.filter((name) => name !== g.name);
                });
                label.appendChild(input);
                label.appendChild(node('span', '', g.name));
                e.templateGroups.appendChild(label);
            });
            renderControls();
        }
        function templateCompare() {
            const selected = template(selectedTemplateId(e.applyTemplate));
            const u = user(state.user);
            if (!u || !selected) { e.compare.textContent = ''; return; }
            const missing = selected.groups.filter((g) => !state.userDraft.includes(g));
            const extra = state.userDraft.filter((g) => !selected.groups.includes(g));
            e.compare.textContent = 'Compared with ' + selected.name + ': ' +
                (missing.length ? 'Not assigned: ' + missing.join(', ') : 'All template groups selected') +
                (extra.length ? ' • Other memberships: ' + extra.join(', ') : '');
        }
        function userEdits() {
            const u = user(state.user);
            if (!u || same(u.groups, state.userDraft)) return [];
            return [{ user: u.id, before: u.groups.slice(), after: unique(state.userDraft) }];
        }
        function groupEdits() {
            if (!state.group) return [];
            return state.directory.users.map((u) => {
                const desired = u.groups.filter((g) => g !== state.group);
                if (state.groupDraft.has(u.id)) desired.push(state.group);
                if (same(desired, u.groups)) return null;
                return { user: u.id, before: u.groups.slice(), after: unique(desired) };
            }).filter(Boolean);
        }
        function newHasDraft() {
            return Boolean(e.newName.value || e.newUsername.value || e.newEmail.value ||
                state.newDraft.some((g) => g !== state.directory.defaultGroup));
        }
        function confirmDiscard() {
            if (!userEdits().length && !groupEdits().length && (state.active !== 'new' || !newHasDraft())) return true;
            return window.confirm('Discard unsaved changes?');
        }
        function showDiff(target, edits) {
            clear(target);
            if (!edits.length) { target.textContent = 'No proposed changes.'; return; }
            target.appendChild(node('strong', '', 'Proposed changes: ' + edits.length + ' user(s)'));
            edits.forEach((change) => {
                const plus = change.after.filter((g) => !change.before.includes(g));
                const minus = change.before.filter((g) => !change.after.includes(g));
                const item = node('div');
                item.appendChild(node('strong', '', change.user + ': '));
                if (plus.length) item.appendChild(node('span', 'rmsgm-added', '+ ' + plus.join(', ') + ' '));
                if (minus.length) item.appendChild(node('span', 'rmsgm-removed', '− ' + minus.join(', ')));
                target.appendChild(item);
            });
        }
        function renderUserList() {
            const q = e.userSearch.value.trim().toLocaleLowerCase();
            clear(e.userList);
            state.directory.users.filter((u) => (u.name + ' ' + u.id).toLocaleLowerCase().includes(q)).forEach((u) => {
                const option = node('option', '', u.name + ' (' + u.id + ')' + (u.protected ? ' [privileged]' : ''));
                option.value = u.id;
                option.selected = state.user === u.id;
                e.userList.appendChild(option);
            });
            if (!Array.from(e.userList.options).some((o) => o.selected)) e.userList.selectedIndex = -1;
        }
        function renderUser() {
            const u = user(state.user);
            e.userTitle.textContent = u ? u.name + ' (' + u.id + ')' : 'Select a user';
            clear(e.userCurrent);
            clear(e.userBoxes);
            if (!u) {
                e.userCurrent.textContent = 'Select a user to display memberships.';
                clear(e.userPreview);
                e.compare.textContent = '';
                renderControls(); return;
            }
            if (!u.groups.length) e.userCurrent.textContent = 'No current groups';
            u.groups.forEach((g) => e.userCurrent.appendChild(node('span', 'rmsgm-chip', g)));
            const q = e.userGroupSearch.value.trim().toLocaleLowerCase();
            state.directory.groups.filter((g) => g.name.toLocaleLowerCase().includes(q)).forEach((g) => {
                const label = node('label', g.protected || u.protected ? 'rmsgm-protected' : '');
                const check = node('input');
                check.type = 'checkbox';
                check.checked = state.userDraft.includes(g.name);
                check.disabled = !privilegedEditAllowed(u, g, e.userOverride.checked);
                check.addEventListener('change', () => {
                    state.userDraft = check.checked ? unique([...state.userDraft, g.name]) : state.userDraft.filter((x) => x !== g.name);
                    showDiff(e.userPreview, userEdits()); templateCompare(); renderControls();
                });
                label.appendChild(check);
                label.appendChild(node('span', '', g.name + (g.protected ? ' 🔒' : '') + (!g.count ? ' (empty)' : '')));
                e.userBoxes.appendChild(label);
            });
            showDiff(e.userPreview, userEdits()); templateCompare(); renderControls();
        }
        function renderGroupList() {
            const q = e.groupSearch.value.trim().toLocaleLowerCase();
            clear(e.groupList);
            state.directory.groups.filter((g) => g.name.toLocaleLowerCase().includes(q)).forEach((g) => {
                const opt = node('option', '', g.name + ' (' + g.count + ')' + (g.protected ? ' 🔒' : ''));
                opt.value = g.name; opt.selected = state.group === g.name; e.groupList.appendChild(opt);
            });
            if (!Array.from(e.groupList.options).some((o) => o.selected)) e.groupList.selectedIndex = -1;
            e.groupCount.textContent = '(' + state.directory.groups.length + ')';
        }
        function visibleMembers() {
            const q = e.memberSearch.value.trim().toLocaleLowerCase();
            return state.directory.users.filter((u) => (u.name + ' ' + u.id).toLocaleLowerCase().includes(q));
        }
        function renderGroup() {
            const g = group(state.group);
            e.groupTitle.textContent = g ? g.name : 'Select a group';
            clear(e.members);
            if (!g) { e.groupDetail.textContent = ''; clear(e.groupPreview); renderControls(); return; }
            e.groupDetail.textContent = state.groupDraft.size + ' proposed • ' + g.count + ' current member(s)' + (g.protected ? ' • privileged' : '');
            visibleMembers().forEach((u) => {
                const label = node('label', g.protected || u.protected ? 'rmsgm-protected' : '');
                const check = node('input');
                check.type = 'checkbox'; check.checked = state.groupDraft.has(u.id);
                check.disabled = !privilegedEditAllowed(u, g, e.groupOverride.checked);
                check.addEventListener('change', () => {
                    if (check.checked) state.groupDraft.add(u.id); else state.groupDraft.delete(u.id);
                    renderGroup();
                });
                label.appendChild(check);
                label.appendChild(node('span', '', u.name + ' (' + u.id + ')' + (u.protected ? ' [privileged]' : '')));
                e.members.appendChild(label);
            });
            showDiff(e.groupPreview, groupEdits()); renderControls();
        }
        function renderNewUser() {
            if (!state.directory) return;
            clear(e.newGroups);
            state.directory.groups.filter((g) => !g.protected).forEach((g) => {
                const label = node('label'); const check = node('input');
                check.type = 'checkbox'; check.checked = state.newDraft.includes(g.name);
                check.disabled = g.name === state.directory.defaultGroup || !state.directory.canCreate;
                check.addEventListener('change', () => {
                    state.newDraft = check.checked ? unique([...state.newDraft, g.name]) : state.newDraft.filter((v) => v !== g.name);
                    renderNewPreview();
                });
                label.appendChild(check); label.appendChild(node('span', '', g.name + (g.name === state.directory.defaultGroup ? ' (default)' : '')));
                e.newGroups.appendChild(label);
            });
            renderNewPreview();
        }
        function renderNewPreview() {
            e.newPreview.textContent = 'Account: ' + (e.newName.value.trim() || '(enter name)') +
                ' (' + (e.newUsername.value.trim() || 'username') + ') • ' + (e.newEmail.value.trim() || '(email)') +
                '\nInitial groups: ' + (state.newDraft.length ? unique(state.newDraft).join(', ') : '(none)') +
                '\nThe temporary password is generated on the server and shown once after creation.';
            renderControls();
        }
        function renderControls() {
            if (!state.directory || state.busy) return;
            const writable = state.directory.writable;
            e.userReset.disabled = !userEdits().length;
            e.userSave.disabled = !writable || !userEdits().length;
            e.groupReset.disabled = !groupEdits().length;
            e.groupSave.disabled = !writable || !groupEdits().length;
            const g = group(state.group);
            const locked = !g || !writable || (g.protected && !e.groupOverride.checked);
            e.addVisible.disabled = locked; e.clearVisible.disabled = locked;
            e.forget.disabled = !g || !g.registered || !g.forgettable || g.count !== 0;
            e.createGroup.disabled = !state.directory.supported;
            e.newOpen.disabled = !state.directory.canCreate;
            e.newCreate.disabled = !state.directory.canCreate || state.newCreated;
            e.apply.disabled = !writable || !user(state.user) || !selectedTemplateId(e.applyTemplate);
            e.templateDelete.disabled = !state.templateId;
        }
        function resetDrafts() {
            state.userDraft = user(state.user)?.groups.slice() || [];
            state.groupDraft = new Set(state.group ? state.directory.users.filter((u) => u.groups.includes(state.group)).map((u) => u.id) : []);
            e.userOverride.checked = false;
            e.groupOverride.checked = false;
        }
        function rerender() { renderTemplateSelectors(); renderUserList(); renderUser(); renderGroupList(); renderGroup(); renderNewUser(); }
        async function reload(keepSelection) {
            const data = await request('directory');
            state.directory = data;
            if (!keepSelection) { state.user = data.users[0]?.id || ''; state.group = data.groups[0]?.name || ''; }
            else { if (!user(state.user)) state.user = data.users[0]?.id || ''; if (!group(state.group)) state.group = data.groups[0]?.name || ''; }
            resetDrafts();
            rerender();
            loadTemplate(state.templateId);
            e.warning.hidden = data.supported && data.writable;
            e.warning.textContent = !data.supported ? data.reason : !data.writable ?
                'Read-only backend: membership updates and local onboarding unavailable. Group registry and role templates remain manageable.' : '';
        }
        function switchPanel(panel) {
            state.active = panel;
            root.querySelectorAll('[data-panel]').forEach((el) => { el.hidden = el.dataset.panel !== panel; });
            root.querySelectorAll('[data-tab]').forEach((el) => {
                const selected = el.dataset.tab === panel;
                el.classList.toggle('active', selected);
                el.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            if (panel === 'acl') aclRefresh();
        }
        async function save(edits, override) {
            if (!edits.length) return;
            const rows = edits.map((change) => {
                const added = change.after.filter((g) => !change.before.includes(g));
                const removed = change.before.filter((g) => !change.after.includes(g));
                return change.user + ': ' + (added.length ? '+' + added.join(', ') : '') +
                    (removed.length ? '  −' + removed.join(', ') : '');
            });
            let params = { edits: JSON.stringify(edits) };
            if (override) {
                const answer = window.prompt('PRIVILEGED MEMBERSHIP CHANGE\nReview:\n' + rows.join('\n') +
                    '\n\nType PRIVILEGED to authorize this one save.');
                if (answer !== 'PRIVILEGED') return;
                params = { ...params, privileged_override: '1', override_confirmation: answer };
            } else if (!window.confirm('Review membership changes:\n\n' + rows.join('\n') + '\n\nSave these changes?')) return;
            await run(async () => {
                const output = await request('save', params);
                const messages = output.results.map((r) => (r.ok ? '✓ ' : '✗ ') + r.user + ': ' + r.message);
                if (output.warning) messages.push('WARNING: ' + output.warning);
                const errors = output.results.some((r) => !r.ok);
                await reload(true);
                message(messages.join('\n'), errors || output.warning ? 'error' : 'success');
            });
        }
        function resetNew() {
            e.newName.value = ''; e.newUsername.value = ''; e.newEmail.value = ''; e.newTemplate.value = '';
            state.newDraft = state.directory.defaultGroup ? [state.directory.defaultGroup] : [];
            state.newCreated = false;
            e.newCredential.hidden = true; clear(e.newCredential);
            renderNewUser();
        }
        // Read-only ACL explorer. Every view is retrieved from DokuWiki, never saved here.
        const acl = { epoch: 0, loaded: false, type: 'group', principal: '' };
        function aclPrincipalOptions() {
            if (!state.directory) return;
            const previous = e.aclPrincipal.value || acl.principal;
            const kind = e.aclType.value;
            const entries = kind === 'user' ? state.directory.users : state.directory.groups;
            clear(e.aclPrincipal);
            entries.forEach((item) => {
                const id = kind === 'user' ? item.id : item.name;
                const label = kind === 'user' ? item.name + ' (' + item.id + ')' : item.name + ' (' + item.count + ')';
                const opt = node('option', '', label); opt.value = id;
                e.aclPrincipal.appendChild(opt);
            });
            const preferred = kind === 'user' ? state.user :
                (state.directory.groups.find((g) => g.name === 'staff')?.name ||
                    state.directory.groups.find((g) => !g.protected)?.name || state.group);
            const chosen = entries.some((item) => (kind === 'user' ? item.id : item.name) === previous) ? previous : preferred;
            if (entries.some((item) => (kind === 'user' ? item.id : item.name) === chosen)) e.aclPrincipal.value = chosen;
            acl.type = kind; acl.principal = e.aclPrincipal.value;
        }
        function aclParams() {
            return { principalType: e.aclType.value, principal: e.aclPrincipal.value };
        }
        function aclMessage(content) {
            clear(e.aclDetail);
            e.aclDetail.appendChild(node('p', 'rmsgm-note', content));
        }
        function aclExplain(id, type, epoch) {
            if (epoch !== acl.epoch) return;
            aclMessage('Loading matching ACL rules…');
            request('aclExplain', { ...aclParams(), node: id, nodeType: type }).then((data) => {
                if (epoch !== acl.epoch) return;
                clear(e.aclDetail);
                e.aclDetail.appendChild(node('h4', '', data.scope));
                const summary = node('p', 'rmsgm-acl-summary');
                summary.appendChild(node('strong', '', data.permissionLabel));
                summary.appendChild(document.createTextNode(' — ' +
                    (data.sourceType === 'superuser' ? 'DokuWiki superuser' :
                        data.sourceType === 'adjusted' ? 'ACL plugin adjustment' :
                        data.sourceType === 'none' ? 'No matching ACL rule' :
                        data.sourceType === 'explicit' ? 'Explicit matching rule' : 'Inherited rule')));
                e.aclDetail.appendChild(summary);
                if (data.sourceScope) e.aclDetail.appendChild(node('p', 'rmsgm-muted', 'Determining source: ' + data.sourceScope));
                if (data.subject.kind === 'user') {
                    e.aclDetail.appendChild(node('p', 'rmsgm-muted',
                        'Groups of ' + data.subject.user + ': ' + (data.subject.groups.join(', ') || '(none)')));
                } else {
                    e.aclDetail.appendChild(node('p', 'rmsgm-muted',
                        'Simulation includes @' + data.subject.name + ' and @ALL only; actual users may belong to additional groups.'));
                }
                if (data.note) e.aclDetail.appendChild(node('p', 'rmsgm-acl-caution', data.note));
                if (data.sourceType === 'superuser') {
                    e.aclDetail.appendChild(node('p', '',
                        'Superusers bypass ordinary ACL rules. Membership changes in a privileged group may affect this status.'));
                }
                if (!data.matchingRules.length) {
                    e.aclDetail.appendChild(node('p', 'rmsgm-muted', 'No matching explicit ACL entries at this page or its ancestor scopes.'));
                    return;
                }
                e.aclDetail.appendChild(node('h5', '', 'Matching ACL entries (most specific first)'));
                data.matchingRules.forEach((scope) => {
                    const block = node('div', 'rmsgm-acl-source');
                    block.appendChild(node('strong', '', scope.scope + (scope.selected ? ' — governing scope' : ' — overridden by a more specific scope')));
                    scope.rules.forEach((rule) => {
                        const ruleRow = node('div', 'rmsgm-acl-source-row' + (scope.selected && rule.winning ? ' rmsgm-acl-winner' : ''));
                        ruleRow.appendChild(node('code', '', decodeURIComponentSafe(rule.principal)));
                        ruleRow.appendChild(node('span', '', ' → ' + aclLevelName(rule.level)));
                        if (scope.selected && rule.winning) ruleRow.appendChild(node('strong', '', ' Governing permission'));
                        block.appendChild(ruleRow);
                    });
                    e.aclDetail.appendChild(block);
                });
                e.aclDetail.appendChild(node('p', 'rmsgm-note',
                    'DokuWiki uses the most specific matching scope, then the highest permission among matching rules at that scope.'));
            }).catch((ex) => { if (epoch === acl.epoch) aclMessage('Unable to explain permissions: ' + ex.message); });
        }
        function decodeURIComponentSafe(text) {
            try { return decodeURIComponent(text); } catch (e) { return text; }
        }
        function aclLevelName(n) {
            return ({ 0: 'None', 1: 'Read', 2: 'Edit', 4: 'Create', 8: 'Upload', 16: 'Delete', 255: 'Superuser' })[n] || 'Level ' + n;
        }
        async function aclLoadChildren(parent, container, epoch) {
            const response = await request('aclTree', { ...aclParams(), parent });
            if (epoch !== acl.epoch) return;
            clear(container);
            const filtered = response.nodes.filter((entry) => !e.aclExplicit.checked ||
                entry.explicitRule || entry.hasExplicitBelow);
            if (!filtered.length) {
                container.appendChild(node('p', 'rmsgm-acl-empty', e.aclExplicit.checked ?
                    'No directly matching ACL rules below this namespace.' : 'No pages or subnamespaces here.'));
            }
            filtered.forEach((entry) => {
                const wrap = node('div', 'rmsgm-acl-item');
                const row = node('div', 'rmsgm-acl-row');
                const expand = node('button', 'rmsgm-acl-expand', entry.hasChildren ? '▸' : '');
                expand.type = 'button'; expand.disabled = !entry.hasChildren;
                expand.setAttribute('aria-label', 'Expand ' + entry.name);
                expand.setAttribute('aria-expanded', 'false');
                const select = node('button', 'rmsgm-acl-select', (entry.hasChildren ? '📁 ' : '📄 ') + entry.name);
                select.type = 'button'; select.title = entry.id + (entry.onDisk ? '' : ' (ACL reference; no current page/directory)');
                const value = node('span', 'rmsgm-acl-level', entry.permissionLabel);
                const source = node('span', 'rmsgm-acl-origin', entry.sourceType === 'explicit' ? 'Explicit' :
                    entry.sourceType === 'inherited' ? 'Inherited' :
                    entry.sourceType === 'superuser' ? 'Superuser' :
                    entry.sourceType === 'adjusted' ? 'Hook' : 'None');
                row.append(expand, select, value, source);
                const children = node('div', 'rmsgm-acl-children'); children.hidden = true;
                select.addEventListener('click', () => {
                    e.aclTree.querySelectorAll('.rmsgm-acl-row').forEach((r) => r.classList.remove('rmsgm-acl-selected'));
                    row.classList.add('rmsgm-acl-selected');
                    aclExplain(entry.id, entry.type, epoch);
                });
                expand.addEventListener('click', async () => {
                    if (!entry.hasChildren) return;
                    if (!children.hidden) { children.hidden = true; expand.textContent = '▸'; expand.setAttribute('aria-expanded', 'false'); return; }
                    children.hidden = false; expand.textContent = '▾'; expand.setAttribute('aria-expanded', 'true');
                    if (children.dataset.loaded === '1') return;
                    expand.disabled = true; children.appendChild(node('p', 'rmsgm-acl-empty', 'Loading…'));
                    try { await aclLoadChildren(entry.id, children, epoch); if (epoch === acl.epoch) children.dataset.loaded = '1'; }
                    catch (ex) { if (epoch === acl.epoch) { clear(children); children.appendChild(node('p', 'rmsgm-acl-caution', ex.message)); } }
                    finally { expand.disabled = false; }
                });
                wrap.append(row, children); container.appendChild(wrap);
            });
            if (parent === '') e.aclTreeStatus.textContent = 'Showing ' + filtered.length +
                ' root entries.' + (response.dynamicRuleCount ? ' ' + response.dynamicRuleCount + ' dynamic ACL rules resolved for this selection.' : '');
        }
        function aclRefresh() {
            if (!state.directory || state.active !== 'acl') return;
            aclPrincipalOptions();
            acl.epoch += 1;
            const epoch = acl.epoch;
            clear(e.aclTree);
            e.aclTreeStatus.textContent = '';
            if (!e.aclPrincipal.value) { aclMessage('No user or group is available.'); return; }
            aclMessage('Select a page or namespace to inspect its effective permissions.');
            const rootRow = node('div', 'rmsgm-acl-row rmsgm-acl-root');
            const rootButton = node('button', 'rmsgm-acl-select', '⌂ Wiki root (*)');
            rootButton.type = 'button';
            rootButton.addEventListener('click', () => {
                e.aclTree.querySelectorAll('.rmsgm-acl-row').forEach((r) => r.classList.remove('rmsgm-acl-selected'));
                rootRow.classList.add('rmsgm-acl-selected');
                aclExplain('', 'root', epoch);
            });
            rootRow.appendChild(rootButton);
            e.aclTree.appendChild(rootRow);
            const children = node('div', 'rmsgm-acl-children'); e.aclTree.appendChild(children);
            children.appendChild(node('p', 'rmsgm-acl-empty', 'Loading namespaces…'));
            aclLoadChildren('', children, epoch).catch((ex) => {
                if (epoch !== acl.epoch) return;
                clear(children); e.aclTreeStatus.textContent = 'Unable to load ACL tree: ' + ex.message;
            });
        }
        e.aclType.addEventListener('change', () => { acl.principal = ''; aclRefresh(); });
        e.aclPrincipal.addEventListener('change', () => { acl.principal = e.aclPrincipal.value; aclRefresh(); });
        e.aclExplicit.addEventListener('change', aclRefresh);
        e.aclRefresh.addEventListener('click', aclRefresh);

        root.querySelectorAll('[data-tab]').forEach((tab) => tab.addEventListener('click', () => {
            if (state.active !== tab.dataset.tab && !confirmDiscard()) return;
            resetDrafts(); switchPanel(tab.dataset.tab); renderUser(); renderGroup();
        }));
        e.newOpen.addEventListener('click', () => {
            if (!confirmDiscard()) return;
            resetDrafts(); resetNew(); switchPanel('new');
        });
        e.newClose.addEventListener('click', () => { if (state.newCreated ? window.confirm('Leave the one-time password display? Be sure you have saved the password securely.') : confirmDiscard()) { resetNew(); switchPanel('user'); } });
        e.userSearch.addEventListener('input', renderUserList);
        e.userGroupSearch.addEventListener('input', renderUser);
        e.userList.addEventListener('change', () => {
            if (!confirmDiscard()) { renderUserList(); return; }
            state.user = e.userList.value; resetDrafts(); renderUser();
        });
        e.userOverride.addEventListener('change', () => {
            if (!e.userOverride.checked && userEdits().some((edit) => {
                const before = edit.before, after = edit.after;
                return unique([...before, ...after]).some((name) => group(name)?.protected && before.includes(name) !== after.includes(name));
            })) { e.userOverride.checked = true; message('Reset your pending privileged changes before relocking.', 'error'); return; }
            renderUser();
        });
        e.userReset.addEventListener('click', () => { state.userDraft = user(state.user)?.groups.slice() || []; e.userOverride.checked = false; renderUser(); });
        e.userSave.addEventListener('click', () => save(userEdits(), e.userOverride.checked));
        e.applyTemplate.addEventListener('change', () => { templateCompare(); renderControls(); });
        e.apply.addEventListener('click', () => {
            const selected = template(selectedTemplateId(e.applyTemplate));
            const u = user(state.user);
            if (!selected || !u || !state.directory.writable) return;
            const newGroups = selected.groups.filter((name) => {
                const g = group(name); return g && privilegedEditAllowed(u, g, e.userOverride.checked);
            });
            state.userDraft = unique([...state.userDraft, ...newGroups]);
            renderUser();
        });
        e.templateList.addEventListener('change', () => loadTemplate(e.templateList.value));
        e.templateSave.addEventListener('click', () => {
            const name = e.templateName.value.trim(), groups = unique(state.templateDraft);
            if (!name || !groups.length) { message('Give the role template a name and at least one group.', 'error'); return; }
            if (!window.confirm('Save role template "' + name + '" with groups:\n' + groups.join(', ') +
                '\n\nThis does not alter any existing user memberships.')) return;
            run(async () => {
                const saved = await request('saveTemplate', { id: state.templateId, name, groups: JSON.stringify(groups) });
                state.templateId = saved.id;
                await reload(true);
                message('Role template saved. No employee memberships were changed.', 'success');
            });
        });
        e.templateDelete.addEventListener('click', () => {
            const t = template(state.templateId);
            if (!t || !window.confirm('Delete role template "' + t.name + '"? Existing user groups stay unchanged.')) return;
            run(async () => {
                await request('deleteTemplate', { id: t.id });
                state.templateId = '';
                await reload(true);
                message('Role template deleted. User groups were not changed.', 'success');
            });
        });
        e.groupSearch.addEventListener('input', renderGroupList);
        e.memberSearch.addEventListener('input', renderGroup);
        e.groupList.addEventListener('change', () => {
            if (!confirmDiscard()) { renderGroupList(); return; }
            state.group = e.groupList.value; resetDrafts(); renderGroup();
        });
        e.groupOverride.addEventListener('change', () => {
            if (!e.groupOverride.checked && group(state.group)?.protected && groupEdits().length) {
                e.groupOverride.checked = true; message('Reset pending privileged changes before relocking.', 'error'); return;
            }
            renderGroup();
        });
        e.groupReset.addEventListener('click', () => { resetDrafts(); renderGroup(); });
        e.groupSave.addEventListener('click', () => save(groupEdits(), e.groupOverride.checked));
        function selectVisible(add) {
            const g = group(state.group);
            if (!g || (g.protected && !e.groupOverride.checked)) return;
            visibleMembers().forEach((u) => {
                if (!privilegedEditAllowed(u, g, e.groupOverride.checked)) return;
                if (add) state.groupDraft.add(u.id); else state.groupDraft.delete(u.id);
            });
            renderGroup();
        }
        e.addVisible.addEventListener('click', () => selectVisible(true));
        e.clearVisible.addEventListener('click', () => selectVisible(false));
        e.createGroup.addEventListener('click', () => {
            const name = e.newGroup.value.trim();
            if (!name || !window.confirm('Preserve group name "' + name + '"? No ACL rules or user memberships will change.')) return;
            if (!confirmDiscard()) return;
            run(async () => {
                await request('createGroup', { name }); await reload(true);
                state.group = name; resetDrafts(); renderGroupList(); renderGroup(); e.newGroup.value = '';
                message('Group "' + name + '" registered.', 'success');
            });
        });
        e.forget.addEventListener('click', () => {
            const g = group(state.group);
            if (!g || !g.registered || !g.forgettable || g.count || !window.confirm('Forget empty group "' + g.name + '"? ACL rules remain.')) return;
            if (!confirmDiscard()) return;
            run(async () => {
                await request('forgetGroup', { name: g.name }); state.group = '';
                await reload(true); message('Group forgotten from registry; ACL rules unchanged.', 'success');
            });
        });
        e.newTemplate.addEventListener('change', () => {
            const t = template(e.newTemplate.value);
            if (t) state.newDraft = unique([...state.newDraft, ...t.groups]);
            renderNewUser();
        });
        for (const input of [e.newName, e.newUsername, e.newEmail]) input.addEventListener('input', renderNewPreview);
        e.newReset.addEventListener('click', () => { if (state.newCreated ? window.confirm('Clear the displayed one-time password?') : (!newHasDraft() || window.confirm('Discard the new user form?'))) resetNew(); });
        e.newCreate.addEventListener('click', () => {
            const username = e.newUsername.value.trim(), name = e.newName.value.trim(), email = e.newEmail.value.trim();
            const groups = unique(state.newDraft);
            if (!name || !username || !email) { message('Enter full name, username and email before creating the account.', 'error'); return; }
            if (!window.confirm('CREATE USER\n\n' + name + ' (' + username + ')\n' + email +
                '\nGroups: ' + groups.join(', ') +
                '\n\nThe system will generate a temporary password, displayed ONCE. Continue?')) return;
            run(async () => {
                const created = await request('createUser', { username, name, email, groups: JSON.stringify(groups) });
                // Never persist the one-time password in local storage or audit logs.
                state.newCreated = true;
                // Show the one-time password BEFORE refresh: a directory refresh failure must not lose it.
                clear(e.newCredential); e.newCredential.hidden = false;
                e.newCredential.appendChild(node('strong', '', 'Account created: ' + created.id));
                e.newCredential.appendChild(node('p', '', 'One-time display of the generated password. Copy it now and send via an approved secure channel. DokuWiki does not force a password change at first login.'));
                const code = node('code', 'rmsgm-temp-pass', created.temporaryPassword);
                e.newCredential.appendChild(code);
                const copy = node('button', 'btn btn-default', 'Copy password'); copy.type = 'button';
                copy.addEventListener('click', async () => {
                    try { await navigator.clipboard.writeText(code.textContent); message('Temporary password copied to clipboard.', 'success'); }
                    catch (ex) { message('Clipboard access unavailable. Select and copy the displayed password manually.', 'error'); }
                });
                e.newCredential.appendChild(copy);
                e.newCredential.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                e.newCreate.disabled = true;
                try { await reload(true); } catch (refreshError) {
                    message('User created, but directory refresh failed: ' + refreshError.message + '. Copy the password now.', 'error');
                    return;
                }
                if (created.warning) message('Created, with warning: ' + created.warning, 'error');
                else message('User "' + created.id + '" created. Copy the temporary password before leaving.', 'success');
            });
        });
        run(async () => { await reload(false); resetNew(); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
