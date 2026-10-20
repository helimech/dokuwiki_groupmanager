<?php
/**
 * Membership service for the DokuWiki Group Manager plugin.
 * @license GPL-2.0-or-later
 */

use dokuwiki\Extension\AuthPlugin;
use dokuwiki\Extension\Plugin;

class helper_plugin_groupmanager extends Plugin
{
    /** @return AuthPlugin|null */
    private function backend()
    {
        global $auth;
        return $auth instanceof AuthPlugin ? $auth : null;
    }

    private function directory(): string
    {
        global $conf;
        return rtrim($conf['metadir'], '/\\') . '/groupmanager';
    }

    private function ensureDirectory(): void
    {
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Group Manager data directory is not writable.');
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Group Manager data directory is not writable.');
        }
    }

    private function lock()
    {
        $this->ensureDirectory();
        $handle = @fopen($this->directory() . '/write.lock', 'c');
        if (!$handle || !flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            throw new RuntimeException('Could not lock Group Manager for editing.');
        }
        return $handle;
    }

    private function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /** @return string[] */
    private function registry(): array
    {
        $path = $this->directory() . '/groups.json';
        if (!file_exists($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false) throw new RuntimeException('Unable to read the group registry.');
        $obj = json_decode($raw, true);
        if (!is_array($obj) || !isset($obj['groups']) || !is_array($obj['groups'])) {
            throw new RuntimeException('Invalid group registry format; no changes were made.');
        }
        foreach ($obj['groups'] as $group) {
            if (!is_string($group) || $group === '') throw new RuntimeException('Invalid group registry entry.');
        }
        return $this->sortNames(array_values(array_unique($obj['groups'])));
    }

    /**
     * Preserve any groups currently seen in the backend on every directory refresh.
     * Once captured, names remain registered when membership drops to zero.
     */
    private function syncRegistry(array $observed): array
    {
        $lock = $this->lock();
        try {
            $current = $this->registry();
            $merged = $this->sortNames(array_merge($current, $observed));
            if ($merged !== $current) {
                $added = array_values(array_diff($merged, $current));
                $entry = ['timestamp' => gmdate('c'), 'administrator' => $this->adminId(),
                    'action' => 'syncRegistry', 'added' => $added];
                $this->audit($entry + ['result' => 'attempt']);
                $this->saveRegistry($merged);
                $this->audit($entry + ['result' => 'success']);
            }
            return $merged;
        } finally {
            $this->unlock($lock);
        }
    }

    private function saveRegistry(array $groups): void
    {
        $this->ensureDirectory();
        $path = $this->directory() . '/groups.json';
        $tmp = @tempnam($this->directory(), 'registry-');
        if (!$tmp) throw new RuntimeException('Could not create temporary registry file.');
        try {
            $json = json_encode(['schema' => 1, 'groups' => $this->sortNames($groups)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (@file_put_contents($tmp, $json) !== strlen($json)) {
                throw new RuntimeException('Failed to write registry.');
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $path)) throw new RuntimeException('Failed to replace registry file.');
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function sortNames(array $names): array
    {
        $names = array_values(array_unique($names));
        natcasesort($names);
        return array_values($names);
    }

    /** @return string[] */
    private function normalizeGroups($groups): array
    {
        if (!is_array($groups)) throw new InvalidArgumentException('Membership list must be an array.');
        $out = [];
        foreach ($groups as $group) {
            if (!is_string($group) || $group === '' || strlen($group) > 255) {
                throw new InvalidArgumentException('Invalid group in request.');
            }
            $out[] = $group;
        }
        if (count($out) !== count(array_unique($out)) || count($out) > 500) {
            throw new InvalidArgumentException('Duplicate or excessive group memberships.');
        }
        return $this->sortNames($out);
    }

    /** @return string[] */
    private function configuredEntries(string $key): array
    {
        global $conf;
        $v = $conf[$key] ?? '';
        if (is_array($v)) return array_values(array_filter($v, 'is_string'));
        if (!is_string($v)) return [];
        return preg_split('/[\s,;]+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return string[] */
    private function protectedGroups(): array
    {
        $names = ['admin', 'manager'];
        foreach (['superuser', 'manager'] as $key) {
            foreach ($this->configuredEntries($key) as $entry) {
                if (strpos($entry, '@') === 0 && strlen($entry) > 1) {
                    $names[] = substr($entry, 1);
                }
            }
        }
        return $this->sortNames($names);
    }

    /** @return string[] */
    private function protectedUsers(): array
    {
        $names = [];
        foreach (['superuser', 'manager'] as $key) {
            foreach ($this->configuredEntries($key) as $entry) {
                if ($entry !== '' && $entry[0] !== '@') $names[] = $entry;
            }
        }
        return $this->sortNames($names);
    }

    /** Sanitized view: never return passwords or raw auth records. */
    public function directoryData(bool $sync = false): array
    {
        $backend = $this->backend();
        if (!$backend || !$backend->canDo('getUsers')) {
            return [
                'supported' => false,
                'writable' => false,
                'canCreate' => false, 'templates' => [],
                'reason' => 'The current authentication backend cannot list users.',
                'users' => [], 'groups' => []
            ];
        }
        $rawUsers = $backend->retrieveUsers(0, 0, []);
        if (!is_array($rawUsers)) throw new RuntimeException('Authentication backend failed to return users.');
        $registered = $this->registry();
        $groupNames = $registered;
        $users = [];
        $counts = [];
        foreach ($rawUsers as $id => $record) {
            if (!is_array($record)) continue;
            $id = (string) $id;
            $groups = $this->normalizeGroups($record['grps'] ?? []);
            foreach ($groups as $group) {
                $groupNames[] = $group;
                $counts[$group] = ($counts[$group] ?? 0) + 1;
            }
            $users[] = [
                'id' => $id,
                'name' => (string) ($record['name'] ?? $id),
                'groups' => $groups,
                'protected' => !empty($record['protected']) || in_array($id, $this->protectedUsers(), true),
                'backendProtected' => !empty($record['protected']),
                'privilegedAccount' => in_array($id, $this->protectedUsers(), true),
            ];
        }
        if ($backend->canDo('getGroups')) {
            $fromAuth = $backend->retrieveGroups(0, 0);
            if (is_array($fromAuth)) {
                foreach ($fromAuth as $group) if (is_string($group) && $group !== '') $groupNames[] = $group;
            }
        }
        global $conf;
        if (!empty($conf['defaultgroup']) && is_string($conf['defaultgroup'])) {
            $groupNames[] = $conf['defaultgroup'];
        }
        // Also preserve configured privileged groups, including those with zero members.
        $groupNames = $this->sortNames(array_merge($groupNames, $this->protectedGroups()));
        if ($sync) {
            $registered = $this->syncRegistry($groupNames);
        }
        $protected = $this->protectedGroups();
        $groups = [];
        foreach ($groupNames as $name) {
            $groups[] = [
                'name' => $name,
                'count' => $counts[$name] ?? 0,
                'registered' => in_array($name, $registered, true),
                'forgettable' => $name !== ($conf['defaultgroup'] ?? '') && !in_array(strtolower($name), array_map('strtolower', $protected), true),
                'protected' => in_array(strtolower($name), array_map('strtolower', $protected), true),
            ];
        }
        usort($users, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']) ?: strnatcasecmp($a['id'], $b['id']));
        return [
            'supported' => true,
            'writable' => (bool) $backend->canDo('modGroups'),
            'canCreate' => (bool) ($backend->canDo('addUser') && $backend->canDo('modGroups')),
            'defaultGroup' => (string) ($conf['defaultgroup'] ?? ''),
            'self' => $this->adminId(),
            'templates' => $this->templates(),
            'backend' => get_class($backend),
            'users' => $users,
            'groups' => $groups,
        ];
    }

    private function audit(array $record): void
    {
        $this->ensureDirectory();
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $file = @fopen($this->directory() . '/audit.jsonl', 'ab');
        if (!$file) throw new RuntimeException('Audit log unavailable; edit blocked.');
        try {
            if (!flock($file, LOCK_EX)) throw new RuntimeException('Audit log lock failed.');
            $n = fwrite($file, $line);
            if ($n !== strlen($line) || !fflush($file)) throw new RuntimeException('Audit log write failed.');
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private function adminId(): string
    {
        global $INPUT;
        $id = $INPUT->server->str('REMOTE_USER');
        if ($id !== '') return $id;
        return (string) ($_SESSION[DOKU_COOKIE]['auth']['user'] ?? 'unknown');
    }

    private function assertSuperuser(): void
    {
        if (!auth_isadmin()) throw new RuntimeException('Superuser access required.');
    }

    /** Handles one or more user edits. Each entry: {user, before, after}. */
    public function saveMemberships(array $edits, bool $override = false): array
    {
        $this->assertSuperuser();
        $backend = $this->backend();
        if (!$backend || !$backend->canDo('getUsers') || !$backend->canDo('modGroups')) {
            throw new RuntimeException('This backend does not permit membership changes.');
        }
        if (!$edits || count($edits) > 250) throw new InvalidArgumentException('No changes or too many edits.');
        $lock = $this->lock();
        $results = [];
        $changed = 0;
        $warnings = [];
        try {
            // Reload the sanitized directory AFTER acquiring our plugin-level write lock.
            $listing = $this->directoryData();
            $available = array_column($listing['groups'], 'name');
            $protectedGroups = $this->protectedGroups();
            $protectedUsers = $this->protectedUsers();
            $seen = [];
            foreach ($edits as $edit) {
                $id = is_array($edit) ? ($edit['user'] ?? '') : '';
                $id = is_string($id) ? $id : '';
                $entry = ['user' => $id, 'ok' => false, 'message' => 'Not processed.'];
                try {
                    if ($id === '' || isset($seen[$id])) throw new InvalidArgumentException('Invalid or duplicate username.');
                    $seen[$id] = true;
                    if (!in_array($id, array_column($listing['users'], 'id'), true)) {
                        throw new RuntimeException('User is not in the browsable user directory.');
                    }
                    $before = $this->normalizeGroups($edit['before'] ?? null);
                    $after = $this->normalizeGroups($edit['after'] ?? null);
                    $info = $backend->getUserData($id);
                    if (!is_array($info)) throw new RuntimeException('User no longer exists.');
                    if (!empty($info['protected'])) {
                        throw new RuntimeException('This account is backend-protected.');
                    }
                    if (in_array($id, $protectedUsers, true) && (!$override || $id === $this->adminId())) {
                        throw new RuntimeException('This privileged account requires override and cannot be your own.');
                    }
                    $current = $this->normalizeGroups($info['grps'] ?? []);
                    if ($before !== $current) throw new RuntimeException('Conflict: memberships changed since you loaded them. Refresh and review.');
                    if (array_diff($after, $available)) throw new RuntimeException('Unknown group. Refresh the group list.');
                    $privileged = array_map('strtolower', $protectedGroups);
                    $changedGroups = array_merge(array_diff($before, $after), array_diff($after, $before));
                    if (array_intersect($privileged, array_map('strtolower', $changedGroups))) {
                        if (!$override) throw new RuntimeException('Privileged group changes require temporary override.');
                        if ($id === $this->adminId()) throw new RuntimeException('Cannot modify your own privileged memberships.');
                        if ($this->wouldRemoveLastSuperuser($backend, $id, $after)) {
                            throw new RuntimeException('Cannot remove the last effective superuser.');
                        }
                    }
                    if ($before === $after) {
                        $entry = ['user' => $id, 'ok' => true, 'message' => 'No change.'];
                    } else {
                        $added = array_values(array_diff($after, $before));
                        $removed = array_values(array_diff($before, $after));
                        $record = [
                            'timestamp' => gmdate('c'), 'administrator' => $this->adminId(),
                            'user' => $id, 'before' => $before, 'after' => $after,
                            'added' => $added, 'removed' => $removed, 'privilegedOverride' => $override,
                        ];
                        // Persist intent before modifying anything. A failed audit write blocks the edit.
                        $this->audit($record + ['result' => 'attempt']);
                        $ok = $backend->triggerUserMod('modify', [$id, ['grps' => $after]]);
                        $verified = $backend->getUserData($id);
                        $new = is_array($verified) ? $this->normalizeGroups($verified['grps'] ?? []) : [];
                        if (!$ok || $new !== $after) {
                            $this->audit($record + ['result' => 'failed', 'actual' => $new]);
                            throw new RuntimeException('Authentication backend rejected or did not verify the update.');
                        }
                        $changed++;
                        try {
                            $this->audit($record + ['result' => 'success']);
                            $entry = ['user' => $id, 'ok' => true, 'message' => 'Saved.'];
                        } catch (Throwable $auditError) {
                            $warnings[] = 'Audit completion record failed for ' . $id . '; an intent record was written.';
                            $entry = ['user' => $id, 'ok' => true, 'message' => 'Saved, but audit completion record failed.'];
                        }
                    }
                } catch (Throwable $e) {
                    $entry['message'] = $e->getMessage();
                }
                $results[] = $entry;
            }
            if ($changed > 0) {
                global $conf;
                if (!@io_saveFile(rtrim($conf['cachedir'], '/\\') . '/sessionpurge', (string) time())) {
                    $warnings[] = 'Changes saved, but the session invalidation marker could not be updated.';
                }
            }
            return ['results' => $results, 'changed' => $changed, 'warning' => implode(' ', $warnings)];
        } finally {
            $this->unlock($lock);
        }
    }

    /** Stores a new group name in the registry (not as a backend security principal). */
    public function createGroup(string $name): array
    {
        $this->assertSuperuser();
        if (strtolower($name) === 'all') throw new InvalidArgumentException('ALL is reserved by DokuWiki ACL and is not a user-managed group.');
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name)) {
            throw new InvalidArgumentException('Use 1–64 lowercase letters, digits, dots, dashes, or underscores; begin with a letter.');
        }
        $backend = $this->backend();
        if (!$backend || !$backend->canDo('getUsers')) {
            throw new RuntimeException('Cannot create a group without a readable user directory.');
        }
        if ($backend->cleanGroup($name) !== $name) {
            throw new InvalidArgumentException('The authentication backend does not accept this group name.');
        }
        $lock = $this->lock();
        try {
            $groups = $this->registry();
            $existing = array_column($this->directoryData()['groups'], 'name');
            if (in_array($name, $groups, true)) throw new RuntimeException('Group is already in the registry.');
            $this->audit(['timestamp' => gmdate('c'), 'administrator' => $this->adminId(), 'action' => 'createGroup', 'group' => $name, 'result' => 'attempt']);
            $groups[] = $name;
            $this->saveRegistry($groups);
            $this->audit(['timestamp' => gmdate('c'), 'administrator' => $this->adminId(), 'action' => 'createGroup', 'group' => $name, 'result' => 'success']);
            return ['created' => true, 'alreadyInBackend' => in_array($name, $existing, true)];
        } finally {
            $this->unlock($lock);
        }
    }

    /** Forget only registry-only empty, nonprivileged group; does not alter ACL or real user memberships. */
    public function forgetGroup(string $name): array
    {
        $this->assertSuperuser();
        $lock = $this->lock();
        try {
            $registry = $this->registry();
            if (!in_array($name, $registry, true)) throw new RuntimeException('Group is not registered.');
            global $conf;
            if ($name === ($conf['defaultgroup'] ?? '') || in_array(strtolower($name), array_map('strtolower', $this->protectedGroups()), true)) {
                throw new RuntimeException('Cannot forget a required or privileged group.');
            }
            foreach ($this->templates() as $template) {
                if (in_array($name, $template['groups'], true)) {
                    throw new RuntimeException('Group is still used by role template: ' . $template['name']);
                }
            }
            $directory = $this->directoryData();
            foreach ($directory['groups'] as $group) {
                if ($group['name'] === $name && $group['count'] !== 0) throw new RuntimeException('Group still has members.');
            }
            $this->audit(['timestamp' => gmdate('c'), 'administrator' => $this->adminId(), 'action' => 'forgetGroup', 'group' => $name, 'result' => 'attempt']);
            $this->saveRegistry(array_values(array_diff($registry, [$name])));
            $this->audit(['timestamp' => gmdate('c'), 'administrator' => $this->adminId(), 'action' => 'forgetGroup', 'group' => $name, 'result' => 'success']);
            return ['forgotten' => true];
        } finally {
            $this->unlock($lock);
        }
    }


    /** Resolve configured direct superuser accounts and @group memberships. */
    private function wouldRemoveLastSuperuser(AuthPlugin $backend, string $target, array $replacement): bool
    {
        $rules = $this->configuredEntries('superuser');
        $users = $backend->retrieveUsers(0, 0, []);
        if (!is_array($users)) return true;
        $remaining = 0;
        foreach ($users as $id => $info) {
            if (!is_array($info)) continue;
            $id = (string) $id;
            $groups = $id === $target ? $replacement : ($info['grps'] ?? []);
            foreach ($rules as $rule) {
                if (($rule !== '' && $rule[0] !== '@' && $rule === $id) ||
                    ($rule !== '' && $rule[0] === '@' && in_array(substr($rule, 1), $groups, true))) {
                    $remaining++;
                    break;
                }
            }
        }
        // If we cannot demonstrate one remaining superuser, fail closed.
        return $remaining < 1;
    }

    /** Template registry is independent of backend groups, and never grants ACL rights itself. */
    private function templates(): array
    {
        $path = $this->directory() . '/templates.json';
        if (!is_file($path)) return [];
        $json = @file_get_contents($path);
        if ($json === false) throw new RuntimeException('Cannot read role templates.');
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['templates']) || !is_array($decoded['templates'])) {
            throw new RuntimeException('Invalid role-template registry.');
        }
        $items = [];
        $seen = [];
        foreach ($decoded['templates'] as $value) {
            if (!is_array($value) || !isset($value['id'], $value['name'], $value['groups']) ||
                !is_string($value['id']) || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $value['id']) ||
                !is_string($value['name']) || !is_array($value['groups']) || isset($seen[$value['id']])) {
                throw new RuntimeException('Invalid template entry.');
            }
            $seen[$value['id']] = true;
            $items[] = ['id' => $value['id'], 'name' => $value['name'], 'groups' => $this->normalizeGroups($value['groups'])];
        }
        usort($items, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $items;
    }

    private function storeTemplates(array $templates): void
    {
        $this->ensureDirectory();
        $file = $this->directory() . '/templates.json';
        $tmp = @tempnam($this->directory(), 'template-');
        if (!$tmp) throw new RuntimeException('Cannot create temporary template file.');
        try {
            $json = json_encode(['schema' => 1, 'templates' => $templates], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            if (@file_put_contents($tmp, $json) !== strlen($json)) throw new RuntimeException('Unable to write templates.');
            @chmod($tmp, 0640);
            if (!@rename($tmp, $file)) throw new RuntimeException('Unable to replace template registry.');
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    public function saveTemplate(string $id, string $name, array $groups): array
    {
        $this->assertSuperuser();
        if ($id !== '' && !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)) throw new InvalidArgumentException('Invalid role template ID.');
        $name = trim($name);
        if ($name === '' || strlen($name) > 80) throw new InvalidArgumentException('Template name must be 1–80 characters.');
        $groups = $this->normalizeGroups($groups);
        if (!$groups) throw new InvalidArgumentException('Select at least one group for the template.');
        $lock = $this->lock();
        try {
            $available = array_column($this->directoryData()['groups'], 'name');
            if (array_diff($groups, $available)) throw new InvalidArgumentException('Template contains unknown group(s).');
            if (array_intersect(array_map('strtolower', $groups), array_map('strtolower', $this->protectedGroups()))) {
                throw new InvalidArgumentException('Privileged groups cannot be included in reusable templates.');
            }
            $items = $this->templates();
            if ($id === '') $id = 'r_' . bin2hex(random_bytes(8));
            $found = false;
            foreach ($items as &$item) {
                if ($item['id'] === $id) { $item = compact('id', 'name', 'groups'); $found = true; break; }
            }
            unset($item);
            if (!$found) $items[] = compact('id', 'name', 'groups');
            $audit = ['timestamp' => gmdate('c'), 'administrator' => $this->adminId(),
                      'action' => 'saveTemplate', 'template' => $id, 'name' => $name, 'groups' => $groups];
            $this->audit($audit + ['result' => 'attempt']);
            $this->storeTemplates($items);
            $this->audit($audit + ['result' => 'success']);
            return ['id' => $id];
        } finally { $this->unlock($lock); }
    }

    public function deleteTemplate(string $id): array
    {
        $this->assertSuperuser();
        $lock = $this->lock();
        try {
            $items = $this->templates();
            $filtered = array_values(array_filter($items, static fn($item) => $item['id'] !== $id));
            if (count($filtered) === count($items)) throw new InvalidArgumentException('Template no longer exists.');
            $audit = ['timestamp' => gmdate('c'), 'administrator' => $this->adminId(), 'action' => 'deleteTemplate', 'template' => $id];
            $this->audit($audit + ['result' => 'attempt']);
            $this->storeTemplates($filtered);
            $this->audit($audit + ['result' => 'success']);
            return ['deleted' => true];
        } finally { $this->unlock($lock); }
    }

    /** Create local users through the auth backend, never by editing users.auth.php. */
    public function createUser(string $id, string $name, string $email, array $groups): array
    {
        $this->assertSuperuser();
        $backend = $this->backend();
        if (!$backend || !$backend->canDo('getUsers') || !$backend->canDo('addUser') || !$backend->canDo('modGroups')) {
            throw new RuntimeException('Authentication backend does not support local onboarding.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,63}$/D', $id) || $backend->cleanUser($id) !== $id) {
            throw new InvalidArgumentException('Username must be 2–64 lowercase letters, digits, underscore, dot or dash.');
        }
        $name = trim($name);
        $email = trim($email);
        if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new InvalidArgumentException('Provide a full name and valid email address.');
        }
        $groups = $this->normalizeGroups($groups);
        global $conf;
        $default = (string) ($conf['defaultgroup'] ?? '');
        if ($default !== '' && !in_array($default, $groups, true)) $groups[] = $default;
        $groups = $this->sortNames($groups);
        $lock = $this->lock();
        try {
            $available = array_column($this->directoryData()['groups'], 'name');
            if (array_diff($groups, $available)) throw new InvalidArgumentException('Unknown onboarding group. Reload the directory.');
            if (array_intersect(array_map('strtolower', $groups), array_map('strtolower', $this->protectedGroups()))) {
                throw new InvalidArgumentException('Assign privileged groups separately using temporary override after creating the user.');
            }
            if ($backend->getUserData($id) !== false) throw new RuntimeException('Username already exists.');
            // A cryptographically random one-time credential, not persisted in plugin files or audit.
            $password = bin2hex(random_bytes(16));
            $record = ['timestamp' => gmdate('c'), 'administrator' => $this->adminId(),
                       'action' => 'createUser', 'user' => $id, 'name' => $name, 'groups' => $groups];
            $this->audit($record + ['result' => 'attempt']);
            $created = $backend->triggerUserMod('create', [$id, $password, $name, $email, $groups]);
            $new = $backend->getUserData($id);
            $verified = is_array($new) && $this->normalizeGroups($new['grps'] ?? []) === $groups;
            if (!$created || !$verified) {
                $this->audit($record + ['result' => 'failed', 'created' => (bool) $created, 'verified' => $verified]);
                throw new RuntimeException($created ? 'Account may have been created, but group verification failed. Check Classic User Manager before retrying.' : 'Backend rejected account creation.');
            }
            $warning = '';
            try { $this->audit($record + ['result' => 'success']); }
            catch (Throwable $e) { $warning = 'Audit completion entry could not be written; intent entry exists.'; }
            if (!@io_saveFile(rtrim($conf['cachedir'], '/\\') . '/sessionpurge', (string) time())) {
                $warning .= ' Session invalidation marker could not be updated.';
            }
            return ['id' => $id, 'name' => $name, 'temporaryPassword' => $password, 'warning' => trim($warning)];
        } finally { $this->unlock($lock); }
    }
}
