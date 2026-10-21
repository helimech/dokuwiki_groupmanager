# Changelog

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
