# DokuWiki Group Manager

A standalone administration plugin that lets superusers manage DokuWiki account/group memberships through searchable checkboxes, preserve empty group names, apply static onboarding role templates, and create local users through DokuWiki's supported authentication API. Independent of Rotor Maxx's `structdocapproval` plugin.

**Release:** `0.3.2-alpha` — **test environments only; not production validated**.

## What's fixed in 0.3.2-alpha — CSS compatibility

- Fix a DokuWiki CSS compilation error on the New User generated-password field by using `width:100%; max-width:350px` rather than CSS `min()`; preserves responsive appearance.
- No behavior changes to account creation or the read-only Permissions Explorer.

## What's new in 0.3.1-alpha — New User password improvements

- Choose **Set password manually** (default) or **Generate a random password** during local account creation. Manual passwords are validated server-side (8–72 UTF-8 bytes, nonblank, no control characters) and require confirmation. DokuWiki receives the password through `triggerUserMod('create', ...)`.
- Generated passwords are now **16 URL-safe characters** drawn from 96 bits of randomness, rather than 32 hexadecimal characters. Generated passwords are shown once; passwords supplied manually are never returned to the browser in the create response. Neither is written to the audit log.
- Copy uses the browser Clipboard API on secure contexts, with a selectable text input and legacy copy fallback for HTTP installations. If clipboard access still fails, the password is automatically selected for Ctrl+C rather than showing an alarming error.
- **HTTP caution:** Local-only does not mean encrypted. A password submitted over plain HTTP can be read by anyone able to intercept that traffic. Use HTTPS before creating real user accounts, even on a private intranet.

## What's new in 0.3.0-alpha — Permissions Explorer (read-only)

- **Permissions** tab with Group and User selectors; no ACL editing is available in v0.3.
- Browse an expandable namespace/page tree, loaded a namespace at a time. Combine actual page/namespace locations, media namespaces and ACL references (including empty/deleted namespaces and pages that still have ACL entries).
- Show effective **None / Read / Edit / Create / Upload / Delete / Superuser** permission levels from DokuWiki's own `auth_aclcheck()` function. The group view simulates membership in just the selected group plus `@ALL`. The user view reads all current memberships and direct user rules.
- **Why this permission?** details show the scope responsible for access and matching rules from broader scopes, including when the more specific scope wins. Explain same-scope highest-permission precedence.
- Handle DokuWiki's `%USER%` and `%GROUP%` ACL wildcards for the selected user/group rather than accidentally using the logged-in administrator's expansion. Restore the original `$AUTH_ACL` global after each check.
- Optional **Only show explicit rules and their ancestors** filter, read-only Refresh, and link to Classic ACL Manager.
- Superuser-only AJAX, existing POST + CSRF protection, no ACL writes. Tests added for scope inheritance, page-specific exceptions, wildcard expansion and preserving the live ACL global.

**Important limitation:** Dynamic hooks attached to `AUTH_ACL_CHECK` can introduce rules beyond ordinary `acl.auth.php` evaluation. The explorer calls the hook-aware DokuWiki checker for the effective level and flags disagreements with its standard-rule explanation. A third-party plugin may inspect the real administrator's session instead of the simulated identity; verify those cases independently. This is a diagnostic viewer, not a security certification report.

## What's new in 0.2.0-alpha

- **Temporary privileged override** for a single Save operation. Editing `admin`, `manager` or configured superuser/manager groups requires checking the override box and typing `PRIVILEGED` in a confirmation prompt. It never changes your own privileged group memberships, backend-protected accounts, or removes the last demonstrable superuser. Each affected edit is audited.
- **Role templates:** create, update and delete named collections of ordinary groups. Applying a template adds missing memberships to the draft; it never silently removes another group or updates existing users when the template changes. Compare an existing user's draft against a template.
- **New User:** create local DokuWiki users with name, username, email, default group and selected nonprivileged groups. New accounts get a cryptographically generated temporary password shown **once**. It is not written to logs or sent by email. DokuWiki does **not** enforce a mandatory password change at first login.
- **Classic User Manager links:** a reciprocal link in Group Manager, plus a small link injected into the standard User Manager display using `script.js` (without modifying DokuWiki core files).
- **Group safety:** an empty group referenced by a role template cannot be forgotten from the registry.

## Existing features

- Search by user/full name or group; **By User** and **By Group** views use the same backend data.
- Membership counts, checkboxes, current-membership chips, bulk add/remove and previewed changes.
- Preserved group registry: known group names remain selectable even with zero current members. Create and forget empty names without touching ACL configuration.
- Use `AuthPlugin::triggerUserMod('modify', ...)` for group updates, and `triggerUserMod('create', ...)` for new accounts.
- Backend capability checks, superuser-only endpoints, POST and CSRF validation, optimistic stale-edit checking, lock serialization for writes performed by this plugin, confirmation prompts and JSONL audit records.
- No external database, JavaScript dependencies, or modification to ACL rules.

## Installation

1. Back up your **test** DokuWiki configuration, `conf/users.auth.php` and `data/meta` before installing or upgrading.
2. In DokuWiki **Extension Manager**, use **Manual Install** with `dokuwiki-plugin-groupmanager-v0.3.2-alpha.zip`. The ZIP contains a top-level `groupmanager/` directory.
3. If upgrading from v0.1 or v0.2, overwrite/upgrade the existing `groupmanager` extension using the Extension Manager workflow. Runtime `data/meta/groupmanager` data is preserved.
4. Log in as a DokuWiki superuser and open **Admin → Group Manager**. Check backend support and user/group counts before making any edits.
5. Begin testing the read-only **Permissions** tab against the existing ACL Manager. Inspect a normal group, a user in several groups, a superuser, page-specific exceptions and orphaned namespace ACL entries. No permission edits are possible from this tab.
6. Regression-test v0.2 membership editing, protected groups, templates and new-user creation with dummy accounts before real use.

Designed for DokuWiki with Bootstrap3 and PHP 8.2. Files are installed as `/lib/plugins/groupmanager/`, regardless of the GitHub repository name.

## Authentication and onboarding limitations

- `authplain` with readable/writable `users.auth.php` supports user lists, local user creation, and group modification. Other authentication backends may be read-only or support only some operations; UI disables unsupported account operations.
- In `authplain`, empty groups have no backend principal of their own. Group Manager's JSON registry preserves the name for future assignment. Creating a registry name **does not** create new ACL rights.
- Groups referenced *only* in old ACL rules (and never seen assigned to a user) may not appear until an administrator creates them in the registry manually.
- Role templates are **static conveniences**, not nested DokuWiki security groups. Template changes never retroactively modify employees.
- New-user onboarding **requires** `getUsers`, `addUser` and `modGroups`. The plugin lets an administrator supply a confirmed password or generate a 16-character random temporary password, which is displayed once after successful creation. Deliver it only via an approved secure channel. New accounts are immediately usable; changing their password later uses Classic User Manager or DokuWiki password change functionality.
- **No automatic welcome mail** is sent. Do not use the generated-password workflow over unsecured HTTP if credentials could be intercepted on your network; enable HTTPS on the test/production site before real onboarding.
- New-user creation never assigns privileged groups directly. If required, use the temporary override in By User after creating the account.
- This plugin cannot provision Microsoft Entra/M365 accounts, and cannot update externally managed group memberships when the auth backend prohibits it.

## Protected-account and concurrency model

- `admin`, `manager` and groups in DokuWiki `superuser` or `manager` configuration are marked privileged. Backend-protected accounts cannot be modified here. Named privileged accounts require override, and an admin cannot modify their own protected memberships.
- When overriding privileged group changes, the plugin rejects attempts leaving no **demonstrable** superuser according to direct usernames and `@group` entries in `superuser`. Confirm complex custom/wildcard setups separately before testing overrides.
- A prior membership snapshot is included in every save. The server checks for differences before committing and verifies each resulting membership list. **Bulk updates are per-user operations, not atomic transactions**; if one fails, others might already have succeeded.
- Concurrent saves made through this plugin are serialized with a file lock. DokuWiki's general authentication API has no atomic compare-and-swap transaction with edits made through *other* tools; a narrow race is still possible. Do not perform competing admin edits during regression testing.
- Privileged override is never persisted as a stored preference and applies only to the explicitly confirmed Save request.

## Persistent data

Under DokuWiki's configured `metadir`, usually `data/meta/groupmanager/`:

- `groups.json`: preserved group names
- `templates.json`: static role template definitions (v0.2+)
- `audit.jsonl`: append-only timestamped action records with administrator, changes and result
- `write.lock`: plugin write-lock file

These files are deliberately **not removed** by uninstalling the plugin; preserve the audit trail and backups according to your records policy. The plugin ZIP contains no live credentials or user data.

## Source files and testing

- `admin.php`: admin UI (including reciprocal Classic User Manager link)
- `action.php`: authenticated/CSRF-protected AJAX controller
- `helper.php`: backend adapter, registry, templates, onboarding and audit
- `inc/AclExplorer.php`: read-only ACL scope explorer, wildcard expansion, DokuWiki evaluation and matching-rule explanation
- `script.js`: interactive UI and Classic User Manager link
- `style.css`: scoped responsive Bootstrap3 styling
- `lang/en/lang.php`, `admin.svg`, `plugin.info.txt`: plugin metadata
- `tests/smoke.php`: CLI-only mock-backend tests (source ZIP/repo only)
- `tests/acl_explorer.php`: CLI-only ACL-specific diagnostics tests (source ZIP/repo only)

For local checks:

```bash
php -l admin.php && php -l action.php && php -l helper.php && php -l inc/AclExplorer.php
node --check script.js
php tests/smoke.php
php tests/acl_explorer.php
```

The mock tests do **not** replace browser tests against your real DokuWiki release, permission configuration, ACL event extensions, email policy or PHP-FPM environment.

## License

GPL-2.0-or-later. No third-party dependencies bundled.
