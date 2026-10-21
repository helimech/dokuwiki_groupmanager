# Changelog

## 0.3.3-alpha — 2026-10-21
- Correct post-creation dirty-state warnings: successful manual-password onboarding never prompts about unsaved edits or a non-existent one-time password.
- Continue to warn before abandoning a displayed generated password; centralize this warning across navigation and Reset.
- Reset completed New User state on navigation; lock the completed account form until another onboarding session begins.
- Add dependency-free Node.js navigation regression tests. No changes to membership, authentication, or ACL calculation APIs.

## 0.3.2-alpha — 2026-10-21
- Fix CSS compilation on DokuWiki by replacing unsupported `width:min(100%, 350px)` with equivalent `width:100%; max-width:350px` on the New User password field.
- No changes to PHP authentication, role templates, JavaScript, or read-only Permissions Explorer behavior.

## 0.3.1-alpha — 2026-10-21
- Add custom initial passwords (with confirmation and server-side validation) to New User, while retaining an optional 16-character random generated password.
- Copy password now provides an HTTP-compatible selection/copy fallback and clear guidance when clipboard access is unavailable.
- Preserve v0.3 read-only Permissions Explorer behavior unchanged.

## 0.3.0-alpha — 2026-10-21
- Add read-only Permissions Explorer tab for existing users and groups.
- Load page and namespace tree lazily, including media namespaces and ACL-only references.
- Evaluate effective rights via DokuWiki ACL API and explain applicable rules and scope precedence.
- Resolve `%USER%` / `%GROUP%` ACL wildcards for the selected principal without persisting changes.
- Add explicit-rule filter and link to Classic ACL Manager.
- Add dedicated ACL test suite; preserve v0.2 functionality unchanged.

## 0.2.0-alpha — 2026-10-20
- Add time-limited, per-save privileged override with typed confirmation and last-superuser protection.
- Add static role template CRUD, add-only role application and comparison.
- Add local account creation via authentication API with one-time temporary password display.
- Add reciprocal links to/from classic DokuWiki User Manager.
- Protect role-referenced group names from registry deletion.
- Extend mock-backed smoke tests.

## 0.1.0-alpha — 2026-10-20
- Initial By User / By Group membership UI, group registry, audit and change validation.
