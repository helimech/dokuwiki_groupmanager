<?php

/** DokuWiki Group Manager administration page. @license GPL-2.0-or-later */

use dokuwiki\Extension\AdminPlugin;

class admin_plugin_groupmanager extends AdminPlugin
{
    public function getMenuSort(): int
    {
        return 3;
    }

    public function forAdminOnly(): bool
    {
        return true;
    }

    public function html()
    {
        if (!auth_isadmin()) {
            echo '<p class="error">Only DokuWiki superusers can use Group Manager.</p>';
            return;
        }
        global $ID;
        $classic = hsc(wl($ID, ['do' => 'admin', 'page' => 'usermanager']));
        $aclManager = hsc(wl($ID, ['do' => 'admin', 'page' => 'acl']));
        $token = hsc(getSecurityToken());
        $ajax = hsc(DOKU_BASE . 'lib/exe/ajax.php');
        echo '<div id="rmsgm" class="rmsgm" data-ajax="' . $ajax . '" data-token="' . $token . '">';
        echo '<div class="rmsgm-header"><div class="rmsgm-heading"><h2>Group Manager</h2><div class="rmsgm-header-actions"><a class="btn btn-default" href="' . $classic . '">Classic User Manager</a><button type="button" class="btn btn-primary" id="rmsgm-new-open">New User</button></div></div><span class="rmsgm-subtitle">Manage user and group memberships, onboarding roles and new accounts.</span></div>';
        echo '<div class="rmsgm-alert" role="status" aria-live="polite" hidden></div>';
        echo '<div class="rmsgm-unsupported" hidden></div>';
        echo '<div class="rmsgm-tabs" role="tablist">';
        echo '<button type="button" class="rmsgm-tab active" data-tab="user" aria-selected="true">By User</button>';
        echo '<button type="button" class="rmsgm-tab" data-tab="group" aria-selected="false">By Group</button>';
        echo '<button type="button" class="rmsgm-tab" data-tab="acl" aria-selected="false">Permissions</button>';
        echo '<button type="button" class="rmsgm-tab" data-tab="audit" aria-selected="false">Access Audit</button>';
        echo '</div>';
        echo '<section class="rmsgm-panel" data-panel="user">';
        echo '<div class="rmsgm-columns"><div class="rmsgm-card">';
        echo '<h3>Find user</h3><label for="rmsgm-user-search">Search full name or username</label>';
        echo '<input id="rmsgm-user-search" class="form-control" type="search" autocomplete="off" placeholder="Search users...">';
        echo '<select id="rmsgm-user" class="form-control rmsgm-list" size="10" aria-label="Choose user"></select>';
        echo '</div><div class="rmsgm-card">';
        echo '<h3 id="rmsgm-user-title">Select a user</h3><div id="rmsgm-user-current" class="rmsgm-chips"></div>';
        echo '<div class="rmsgm-template-apply"><label for="rmsgm-apply-template">Add groups from role template</label>';
        echo '<div class="rmsgm-inline"><select id="rmsgm-apply-template" class="form-control"></select><button type="button" class="btn btn-default" id="rmsgm-apply">Add Groups</button></div>';
        echo '<div id="rmsgm-compare" class="rmsgm-note" aria-live="polite"></div></div>';
        echo '<label for="rmsgm-user-group-search">Filter available groups</label>';
        echo '<input id="rmsgm-user-group-search" class="form-control" type="search" placeholder="Filter groups...">';
        echo '<div id="rmsgm-user-checkboxes" class="rmsgm-checkgrid"></div>';
        echo '<div id="rmsgm-user-preview" class="rmsgm-preview"></div>';
        echo '<label class="rmsgm-override-label"><input type="checkbox" id="rmsgm-user-override"> Temporarily unlock privileged changes for this save</label>';
        echo '<div class="rmsgm-buttons"><button id="rmsgm-user-reset" type="button" class="btn btn-default">Reset</button>';
        echo '<button id="rmsgm-user-save" type="button" class="btn btn-primary">Preview &amp; Save</button></div>';
        echo '</div></div>';
        echo '<details class="rmsgm-template-manager rmsgm-card" id="rmsgm-template-details"><summary><strong>Manage role templates</strong> <small>(reusable group selections; never automatic ACL changes)</small></summary>';
        echo '<div class="rmsgm-template-form"><label for="rmsgm-template-list">Template</label><select id="rmsgm-template-list" class="form-control"></select>';
        echo '<label for="rmsgm-template-name">Template name</label><input type="text" id="rmsgm-template-name" class="form-control" maxlength="80" placeholder="e.g. Stores Clerk">';
        echo '<p class="rmsgm-note">Templates may only contain ordinary groups. Assignments are add-only; existing memberships are not removed.</p>';
        echo '<div id="rmsgm-template-groups" class="rmsgm-checkgrid"></div>';
        echo '<div class="rmsgm-buttons"><button id="rmsgm-template-delete" type="button" class="btn btn-default">Delete template</button>';
        echo '<button id="rmsgm-template-save" type="button" class="btn btn-primary">Save template</button></div></div></details></section>';
        echo '<section class="rmsgm-panel" data-panel="group" hidden>';
        echo '<div class="rmsgm-columns"><div class="rmsgm-card">';
        echo '<h3>Groups <span id="rmsgm-group-count" class="rmsgm-muted"></span></h3>';
        echo '<label for="rmsgm-group-search">Search groups</label>';
        echo '<input id="rmsgm-group-search" class="form-control" type="search" placeholder="Filter groups...">';
        echo '<select id="rmsgm-group" class="form-control rmsgm-list" size="10" aria-label="Choose group"></select>';
        echo '<div class="rmsgm-create"><label for="rmsgm-new-group">Create / preserve group name</label>';
        echo '<div class="rmsgm-inline"><input id="rmsgm-new-group" class="form-control" placeholder="e.g. engineering" autocomplete="off">';
        echo '<button id="rmsgm-create" type="button" class="btn btn-default">Create</button></div></div>';
        echo '<p class="rmsgm-note">Empty groups remain available in this plugin registry. Creating a name does not modify ACL rules.</p>';
        echo '</div><div class="rmsgm-card">';
        echo '<h3 id="rmsgm-group-title">Select a group</h3>';
        echo '<p id="rmsgm-group-detail" class="rmsgm-muted"></p>';
        echo '<label for="rmsgm-member-search">Search users</label>';
        echo '<input id="rmsgm-member-search" class="form-control" type="search" placeholder="Find members or other users...">';
        echo '<div class="rmsgm-inline"><button id="rmsgm-select-visible" class="btn btn-default btn-sm" type="button">Add visible</button>';
        echo '<button id="rmsgm-clear-visible" class="btn btn-default btn-sm" type="button">Remove visible</button></div>';
        echo '<div id="rmsgm-members" class="rmsgm-members"></div>';
        echo '<div id="rmsgm-group-preview" class="rmsgm-preview"></div>';
        echo '<label class="rmsgm-override-label"><input type="checkbox" id="rmsgm-group-override"> Temporarily unlock privileged changes for this save</label>';
        echo '<div class="rmsgm-buttons"><button id="rmsgm-forget" type="button" class="btn btn-default">Forget empty group</button>';
        echo '<span class="rmsgm-fill"></span><button id="rmsgm-group-reset" type="button" class="btn btn-default">Reset</button>';
        echo '<button id="rmsgm-group-save" type="button" class="btn btn-primary">Preview &amp; Save</button></div>';
        echo '</div></div></section>';
        echo '<section class="rmsgm-panel" data-panel="acl" hidden>';
        echo '<div class="rmsgm-acl-toolbar rmsgm-card"><div class="rmsgm-acl-toolbar-group">';
        echo '<label for="rmsgm-acl-type">Inspect permissions for</label><select class="form-control" id="rmsgm-acl-type"><option value="group">Group</option><option value="user">User</option></select>';
        echo '<label for="rmsgm-acl-principal">Select group or user</label><select class="form-control" id="rmsgm-acl-principal"></select>';
        echo '</div><div class="rmsgm-acl-toolbar-end"><label class="rmsgm-acl-filter"><input id="rmsgm-acl-explicit" type="checkbox"> Only show explicit rules and their ancestors</label>';
        echo '<button id="rmsgm-acl-refresh" type="button" class="btn btn-default">Refresh tree</button>';
        echo '<a class="btn btn-default" href="' . $aclManager . '">Classic ACL Manager</a></div></div>';
        echo '<p class="rmsgm-note">Read-only permission inspection. Group view simulates the selected group plus @ALL. User view evaluates current group memberships and user-specific rules. No ACL entries are changed.</p>';
        echo '<div class="rmsgm-acl-columns"><div class="rmsgm-card"><h3>Namespaces and pages</h3>';
        echo '<p class="rmsgm-acl-hint">Expand namespaces to browse. Select any row to see which rules determine its access.</p>';
        echo '<div id="rmsgm-acl-tree" class="rmsgm-acl-tree" aria-label="ACL namespace tree"></div>';
        echo '<div id="rmsgm-acl-tree-status" class="rmsgm-muted" role="status"></div></div>';
        echo '<div class="rmsgm-card"><h3>Why this permission?</h3><div id="rmsgm-acl-detail" class="rmsgm-acl-detail" aria-live="polite">Select a page or namespace in the tree.</div></div></div>';
        echo '</section>';
        echo '<section class="rmsgm-panel" data-panel="audit" hidden>';
        echo '<div class="rmsgm-card rmsgm-audit-header"><div><h3>Access Audit <small>(read-only)</small></h3><p class="rmsgm-note">Review ACL rules and group references. Findings are not deletion recommendations. Workflow roles can be used by plugins without ACL rules.</p></div><button type="button" class="btn btn-default" id="rmsgm-audit-refresh">Refresh audit</button></div>';
        echo '<p id="rmsgm-audit-status" class="rmsgm-muted" role="status"></p>';
        echo '<div id="rmsgm-audit-totals" class="rmsgm-audit-totals"></div>';
        echo '<div class="rmsgm-audit-subtabs" role="tablist" aria-label="Access audit sections"><button type="button" class="btn btn-default active" data-audit-view="groups">Groups</button><button type="button" class="btn btn-default" data-audit-view="roots">Namespaces &amp; pages</button><button type="button" class="btn btn-default" data-audit-view="findings">Diagnostics</button></div>';
        echo '<div class="rmsgm-card rmsgm-audit-section" data-audit-panel="groups"><h3>Group usage</h3><p class="rmsgm-note">An empty ACL group can be intentional (e.g. reserved contractors). No groups are removed.</p><label for="rmsgm-audit-group-filter">Filter groups</label><input type="search" class="form-control" id="rmsgm-audit-group-filter" placeholder="Name or status..."><div id="rmsgm-audit-groups"></div></div>';
        echo '<div class="rmsgm-card rmsgm-audit-section" data-audit-panel="roots" hidden><h3>Root namespaces &amp; pages</h3><p class="rmsgm-note">Rule counts are not effective permissions; ACL-only entries may be virtual, planned or retired.</p><label for="rmsgm-audit-root-filter">Filter entries</label><input type="search" class="form-control" id="rmsgm-audit-root-filter" placeholder="Namespace or page..."><div id="rmsgm-audit-roots"></div></div>';
        echo '<div class="rmsgm-card rmsgm-audit-section" data-audit-panel="findings" hidden><h3>Diagnostics</h3><p class="rmsgm-note">Check business purpose and effective access before changing any rule. No automated cleanup.</p><label for="rmsgm-audit-finding-filter">Filter findings</label><input type="search" class="form-control" id="rmsgm-audit-finding-filter" placeholder="Type, rule or scope..."><div id="rmsgm-audit-findings"></div></div>';
        echo '</section>';
        echo '<section class="rmsgm-panel" data-panel="new" hidden><div class="rmsgm-card rmsgm-new-user">';
        echo '<div class="rmsgm-heading"><h3>New DokuWiki user</h3><button type="button" class="btn btn-default" id="rmsgm-new-close">Return to Group Manager</button></div>';
        echo '<p class="rmsgm-note">Local account creation only. Requires backend addUser + modGroups capabilities. Never creates a Microsoft 365 account.</p>';
        echo '<div class="rmsgm-form-grid"><div><label for="rmsgm-new-name">Full name</label><input class="form-control" id="rmsgm-new-name" maxlength="150" autocomplete="off"></div>';
        echo '<div><label for="rmsgm-new-username">Username</label><input class="form-control" id="rmsgm-new-username" maxlength="64" placeholder="e.g. jane.smith" autocomplete="off"></div>';
        echo '<div><label for="rmsgm-new-email">Email address</label><input class="form-control" type="email" id="rmsgm-new-email" maxlength="254" autocomplete="off"></div>';
        echo '<div><label for="rmsgm-new-template">Role template (optional)</label><select class="form-control" id="rmsgm-new-template"></select></div></div>';
        echo '<div class="rmsgm-password-section"><h4>Initial password</h4>';
        echo '<label for="rmsgm-new-password-mode">Password option</label>';
        echo '<select id="rmsgm-new-password-mode" class="form-control"><option value="manual">Set password manually</option><option value="generate">Generate a random password</option></select>';
        echo '<div id="rmsgm-new-password-fields" class="rmsgm-form-grid">';
        echo '<div><label for="rmsgm-new-password">Password</label><input id="rmsgm-new-password" class="form-control" type="password" minlength="8" maxlength="72" autocomplete="new-password" spellcheck="false"></div>';
        echo '<div><label for="rmsgm-new-password-confirm">Confirm password</label><input id="rmsgm-new-password-confirm" class="form-control" type="password" minlength="8" maxlength="72" autocomplete="new-password" spellcheck="false"></div></div>';
        echo '<button type="button" id="rmsgm-new-password-reveal" class="btn btn-default btn-sm">Show passwords</button>';
        echo '<p id="rmsgm-new-password-help" class="rmsgm-note">Use 8–72 bytes, with no control characters. Passwords are not saved in Group Manager logs.</p></div>';
        echo '<p class="rmsgm-note">Select a template to add its groups, then adjust individual memberships. Privileged groups cannot be assigned during account creation.</p>';
        echo '<div id="rmsgm-new-groups" class="rmsgm-checkgrid"></div><div id="rmsgm-new-preview" class="rmsgm-preview"></div>';
        echo '<div class="rmsgm-buttons"><button type="button" class="btn btn-default" id="rmsgm-new-reset">Reset</button>';
        echo '<button type="button" class="btn btn-primary" id="rmsgm-new-create">Preview &amp; Create User</button></div>';
        echo '<div id="rmsgm-new-credential" class="rmsgm-credential" hidden></div></div></section>';
        echo '<p class="rmsgm-footnote">Temporary override applies to one save only. Membership management does not edit ACL rules. Permissions Explorer and Access Audit are read-only.</p>';
        echo '<script defer src="' . hsc(DOKU_BASE . 'lib/plugins/groupmanager/audit.js?v=0.4.1') . '"></script>';
        echo '</div>';
    }
}
