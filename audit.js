/* Group Manager v0.4.2-alpha: standalone, read-only Access Audit and export UI. */
(function () {
    'use strict';
    function boot() {
        const root = document.getElementById('rmsgm');
        if (!root) return;
        const $ = (selector) => root.querySelector(selector);
        const panel = $('[data-panel="audit"]');
        if (!panel) return;
        const status = $('#rmsgm-audit-status');
        const totals = $('#rmsgm-audit-totals');
        const elements = {
            groups: $('#rmsgm-audit-groups'),
            roots: $('#rmsgm-audit-roots'),
            findings: $('#rmsgm-audit-findings')
        };
        let report = null;
        let loading = false;
        let currentView = 'groups';

        function el(tag, className, value) {
            const result = document.createElement(tag);
            if (className) result.className = className;
            if (value !== undefined) result.textContent = String(value);
            return result;
        }
        function empty(target) { target.replaceChildren(); }
        function table(headers, data) {
            const wrap = el('div', 'rmsgm-audit-table-wrap');
            const tableElement = el('table', 'rmsgm-audit-table');
            const thead = document.createElement('thead'), tr = document.createElement('tr');
            headers.forEach((head) => tr.appendChild(el('th', '', head)));
            thead.appendChild(tr);
            const tbody = document.createElement('tbody');
            data.forEach((row) => {
                const record = document.createElement('tr');
                row.forEach((cell) => {
                    const td = document.createElement('td');
                    if (cell instanceof Node) td.appendChild(cell);
                    else td.textContent = String(cell);
                    record.appendChild(td);
                });
                tbody.appendChild(record);
            });
            tableElement.append(thead, tbody);
            wrap.appendChild(tableElement);
            return wrap;
        }
        function match(value, needle) {
            return String(value).toLocaleLowerCase().includes(needle);
        }
        function inspectGroup(name) {
            const tab = $('[data-tab="acl"]');
            const type = $('#rmsgm-acl-type');
            const select = $('#rmsgm-acl-principal');
            tab.click();
            // Existing navigation can be cancelled if an edit is pending.
            if ($('[data-panel="acl"]').hidden) return;
            type.value = 'group';
            type.dispatchEvent(new Event('change', { bubbles: true }));
            select.value = name;
            if (select.value === name) select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function renderGroups() {
            empty(elements.groups);
            if (!report) return;
            const needle = $('#rmsgm-audit-group-filter').value.trim().toLocaleLowerCase();
            const rows = report.groups.filter((g) =>
                match(g.name + ' ' + g.classification, needle)).map((g) => {
                const name = el('div');
                name.appendChild(el('strong', '', '@' + g.name));
                if (g.references) {
                    const detail = el('details', 'rmsgm-audit-scope-list');
                    detail.appendChild(el('summary', '', 'Show ACL scopes'));
                    const list = el('div', 'rmsgm-audit-scope-rules');
                    (g.scopeRules || []).forEach((rule) => {
                        const row = el('div', 'rmsgm-audit-scope-rule');
                        row.append(
                            el('code', '', rule.scope),
                            el('span', 'rmsgm-audit-permission', rule.permission)
                        );
                        if (rule.line) row.title = 'ACL file line ' + rule.line;
                        list.appendChild(row);
                    });
                    detail.appendChild(list);
                    name.appendChild(detail);
                }
                const action = el('button', 'btn btn-default btn-sm', 'Inspect access');
                action.type = 'button';
                action.addEventListener('click', () => inspectGroup(g.name));
                return [name, g.members, g.references, g.classification, action];
            });
            elements.groups.appendChild(table(['Group', 'Members', 'ACL rules', 'Assessment', ''], rows));
            if (!rows.length) elements.groups.appendChild(el('p', 'rmsgm-muted', 'No matching groups.'));
        }
        function renderRoots() {
            empty(elements.roots);
            if (!report) return;
            const needle = $('#rmsgm-audit-root-filter').value.trim().toLocaleLowerCase();
            const entries = report.entries.filter((entry) => match(entry.name + ' ' + entry.kind, needle));
            elements.roots.appendChild(table(['Root entry', 'Type', 'On disk', 'ACL rules in subtree', 'Direct rules'], entries.map((entry) =>
                [entry.name, entry.kind, entry.onDisk ? 'Yes' : 'ACL reference only', entry.ruleCount, entry.directRuleCount])));
            if (!entries.length) elements.roots.appendChild(el('p', 'rmsgm-muted', 'No matching namespaces or pages.'));
        }
        function renderFindings() {
            empty(elements.findings);
            if (!report) return;
            const needle = $('#rmsgm-audit-finding-filter').value.trim().toLocaleLowerCase();
            const entries = report.findings.filter((f) =>
                match(f.type + ' ' + f.title + ' ' + f.detail, needle));
            entries.forEach((f) => {
                const row = el('div', 'rmsgm-audit-finding');
                const head = el('div', 'rmsgm-audit-finding-head');
                head.append(el('strong', '', f.title),
                    el('span', 'rmsgm-audit-severity ' + f.severity, f.severity === 'review' ? 'Review' : 'Information'));
                row.append(head, el('div', 'rmsgm-muted', f.type + (f.line ? ' • ACL line ' + f.line : '')),
                    el('p', '', f.detail));
                elements.findings.appendChild(row);
            });
            if (!entries.length) elements.findings.appendChild(el('p', 'rmsgm-muted', 'No matching findings.'));
        }
        function render() {
            if (!report) return;
            empty(totals);
            const t = report.totals;
            [['Users', t.users], ['Groups', t.groups], ['ACL rules', t.rules],
                ['Root entries', t.rootEntries], ['Review notes', t.findings]].forEach(([label, value]) => {
                const card = el('div', 'rmsgm-audit-stat');
                card.append(el('strong', '', value), el('span', '', label));
                totals.appendChild(card);
            });
            renderGroups(); renderRoots(); renderFindings();
        }
        async function load(force = false) {
            if (loading || (report && !force)) return;
            loading = true;
            const button = $('#rmsgm-audit-refresh');
            button.disabled = true;
            status.textContent = 'Reading current ACL file and group memberships…';
            try {
                const body = new URLSearchParams({
                    call: 'plugin_groupmanager',
                    gm_action: 'accessAudit',
                    sectok: root.dataset.token
                });
                const response = await fetch(root.dataset.ajax, {
                    method: 'POST', body, cache: 'no-store',
                    credentials: 'same-origin', headers: { Accept: 'application/json' }
                });
                const json = await response.json();
                if (!response.ok || !json.ok) throw new Error(json.error || 'Audit request failed.');
                report = json.data;
                render();
                status.textContent = report.notice;
            } catch (ex) {
                status.textContent = 'Unable to run audit: ' + (ex.message || String(ex));
            } finally {
                loading = false;
                button.disabled = false;
            }
        }
        async function exportSnapshot() {
            const button = $('#rmsgm-audit-export');
            if (button.disabled) return;
            button.disabled = true;
            status.textContent = 'Collecting current wiki users, ACLs, pages and namespaces for export…';
            try {
                const body = new URLSearchParams({
                    call: 'plugin_groupmanager',
                    gm_action: 'accessExport',
                    sectok: root.dataset.token
                });
                const response = await fetch(root.dataset.ajax, {
                    method: 'POST', body, cache: 'no-store',
                    credentials: 'same-origin', headers: { Accept: 'application/json' }
                });
                const json = await response.json();
                if (!response.ok || !json.ok) throw new Error(json.error || 'Export request failed.');
                const snapshot = json.data;
                if (!snapshot || snapshot.schema !== 'groupmanager-access-audit-v1' ||
                    !Array.isArray(snapshot.users) || !Array.isArray(snapshot.aclRules) ||
                    !snapshot.inventory) {
                    throw new Error('The server returned an incomplete audit snapshot.');
                }
                const payload = JSON.stringify(snapshot, null, 2) + '\n';
                const blob = new Blob([payload], { type: 'application/json;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const anchor = document.createElement('a');
                const stamp = (snapshot.generatedAtUtc || new Date().toISOString())
                    .replace(/[^0-9A-Za-z-]/g, '-');
                anchor.download = 'groupmanager-access-audit-' + stamp + '.json';
                anchor.href = url;
                anchor.style.display = 'none';
                document.body.appendChild(anchor);
                try { anchor.click(); }
                finally {
                    anchor.remove();
                    // Allow time for browsers to attach the blob to the download.
                    window.setTimeout(() => URL.revokeObjectURL(url), 5000);
                }
                status.textContent = 'Audit snapshot prepared (' +
                    snapshot.counts.users + ' users, ' +
                    snapshot.counts.aclRules + ' ACL rules, ' +
                    snapshot.counts.pages + ' pages). Check your browser downloads.';
            } catch (ex) {
                status.textContent = 'Unable to export audit: ' + (ex.message || String(ex));
            } finally {
                button.disabled = false;
            }
        }
        function activate(section) {
            currentView = section;
            panel.querySelectorAll('[data-audit-view]').forEach((button) => {
                const active = button.dataset.auditView === section;
                button.classList.toggle('active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panel.querySelectorAll('[data-audit-panel]').forEach((sectionEl) => {
                sectionEl.hidden = sectionEl.dataset.auditPanel !== section;
            });
        }
        panel.querySelectorAll('[data-audit-view]').forEach((button) =>
            button.addEventListener('click', () => activate(button.dataset.auditView)));
        $('[data-tab="audit"]').addEventListener('click', () => {
            if (!panel.hidden) load(false);
        });
        $('#rmsgm-audit-refresh').addEventListener('click', () => load(true));
        $('#rmsgm-audit-export').addEventListener('click', exportSnapshot);
        $('#rmsgm-audit-group-filter').addEventListener('input', renderGroups);
        $('#rmsgm-audit-root-filter').addEventListener('input', renderRoots);
        $('#rmsgm-audit-finding-filter').addEventListener('input', renderFindings);
        activate(currentView);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
