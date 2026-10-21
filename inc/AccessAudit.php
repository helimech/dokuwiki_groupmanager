<?php
/**
 * Read-only inventory and diagnostics for the DokuWiki ACL file.
 *
 * Flags are leads for human review, not instructions to remove permissions.
 * It does not modify ACL files, membership or Group Manager's registry.
 *
 * @license GPL-2.0-or-later
 */
namespace dokuwiki\plugin\groupmanager;

use RuntimeException;

final class AccessAudit
{
    private function rules(): array
    {
        global $config_cascade;
        $path = $config_cascade['acl']['default'] ?? '';
        if (!is_string($path) || !$path || !is_readable($path)) {
            throw new RuntimeException('The DokuWiki ACL file is not readable.');
        }
        $rows = file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($rows)) throw new RuntimeException('Unable to read ACL rules.');
        $out = [];
        foreach ($rows as $index => $row) {
            $row = trim($row);
            if ($row === '' || $row[0] === '#') continue;
            $parts = preg_split('/\s+/', $row);
            if (count($parts) < 3 || !ctype_digit($parts[2])) continue;
            $out[] = [
                'scope' => $parts[0],
                'principal' => $parts[1],
                'level' => (int)$parts[2],
                'line' => $index + 1,
            ];
        }
        return $out;
    }

    /** DokuWiki's ordered, cumulative ACL levels in words. */
    private function permissionName(int $level): string
    {
        return match ($level) {
            0 => 'None',
            1 => 'Read',
            2 => 'Edit',
            4 => 'Create',
            8 => 'Upload',
            16 => 'Delete',
            255 => 'Superuser',
            default => 'Level ' . $level,
        };
    }

    private function rootOnDisk(string $name, string $kind): bool
    {
        global $conf;
        $base = $conf['datadir'] ?? '';
        if (!is_string($base) || !$base || str_contains($name, '/') ||
            str_contains($name, '\\') || str_contains($name, '..') || str_contains($name, '%')) {
            return false;
        }
        $encoded = function_exists('utf8_encodeFN') ? utf8_encodeFN($name) : $name;
        $path = rtrim($base, '/\\') . '/' . $encoded;
        return $kind === 'namespace' ? is_dir($path) : is_file($path . '.txt');
    }

    private function rootInventory(): array
    {
        global $conf;
        $base = $conf['datadir'] ?? '';
        $roots = [];
        if (!is_string($base) || !is_dir($base) || !is_readable($base)) return $roots;
        foreach (new \DirectoryIterator($base) as $item) {
            if ($item->isDot()) continue;
            $name = $item->getFilename();
            $decoded = function_exists('utf8_decodeFN') ? utf8_decodeFN($name) : $name;
            if ($item->isDir()) {
                $roots['namespace|' . $decoded] = ['name' => $decoded, 'kind' => 'namespace', 'onDisk' => true];
            } elseif ($item->isFile() && str_ends_with($decoded, '.txt')) {
                $id = substr($decoded, 0, -4);
                $roots['page|' . $id] = ['name' => $id, 'kind' => 'page', 'onDisk' => true];
            }
        }
        return $roots;
    }

    private function rootScope(string $scope): ?array
    {
        if ($scope === '*' || str_contains($scope, '%') || str_contains($scope, '*') && !str_ends_with($scope, ':*')) {
            return null;
        }
        if (str_contains($scope, ':')) {
            $root = explode(':', $scope, 2)[0];
            return $root !== '' ? ['name' => $root, 'kind' => 'namespace'] : null;
        }
        return ['name' => $scope, 'kind' => 'page'];
    }

    public function summary(array $directory, ?array $rules = null): array
    {
        if (!auth_isadmin()) throw new RuntimeException('Superuser access required.');
        $rules = $rules ?? $this->rules();
        $users = [];
        foreach ($directory['users'] ?? [] as $user) $users[$user['id']] = $user;
        $groups = [];
        foreach ($directory['groups'] ?? [] as $group) {
            $groups[$group['name']] = [
                'name' => $group['name'], 'members' => (int)$group['count'],
                'registered' => (bool)($group['registered'] ?? false),
                'protected' => (bool)($group['protected'] ?? false),
                'references' => 0, 'scopes' => [], 'maximum' => 0,
            ];
        }
        $entries = $this->rootInventory();
        $unknownUsers = [];
        $groupRules = [];
        $duplicateMap = [];
        $rootGrants = [];
        foreach ($rules as $rule) {
            $principal = $rule['principal'];
            $scope = $rule['scope'];
            $key = $scope . "\x00" . $principal;
            $duplicateMap[$key][] = $rule;
            $root = $this->rootScope($scope);
            if ($root !== null) {
                $key = $root['kind'] . '|' . $root['name'];
                if (!isset($entries[$key])) {
                    $entries[$key] = $root + [
                        'onDisk' => $this->rootOnDisk($root['name'], $root['kind'])
                    ];
                }
                $entries[$key]['ruleCount'] = ($entries[$key]['ruleCount'] ?? 0) + 1;
                if ($scope === $root['name'] ||
                    ($root['kind'] === 'namespace' && $scope === $root['name'] . ':*')) {
                    $entries[$key]['directRuleCount'] = ($entries[$key]['directRuleCount'] ?? 0) + 1;
                }
            }
            if ($scope === '*' && $rule['level'] >= 2) $rootGrants[] = $rule;
            if ($principal === '@ALL' || str_contains($principal, '%')) continue;
            if (str_starts_with($principal, '@')) {
                $name = rawurldecode(substr($principal, 1));
                if ($name === '') continue;
                if (!isset($groups[$name])) {
                    $groups[$name] = ['name' => $name, 'members' => 0, 'registered' => false,
                        'protected' => false, 'references' => 0, 'scopes' => [], 'maximum' => 0];
                }
                ++$groups[$name]['references'];
                $groups[$name]['maximum'] = max($groups[$name]['maximum'], $rule['level']);
                if (count($groups[$name]['scopes']) < 12) $groups[$name]['scopes'][] = $scope;
                $groupRules[$name][] = $rule;
            } else {
                $id = rawurldecode($principal);
                if (!isset($users[$id])) $unknownUsers[$id][] = $rule;
            }
        }
        foreach ($groups as &$group) {
            $group['scopes'] = array_values(array_unique($group['scopes']));
            // Preserve each written rule and its permission. It is not an effective-access result.
            $group['scopeRules'] = array_map(fn(array $rule): array => [
                'scope' => $rule['scope'],
                'permission' => $this->permissionName($rule['level']),
                'line' => $rule['line'],
            ], $groupRules[$group['name']] ?? []);
            $group['classification'] = $group['members'] === 0 ?
                ($group['references'] ? 'Empty; ACL rules exist' : 'Empty; no ACL references') :
                ($group['references'] ? 'In use' : 'Membership only / plugin role');
        }
        unset($group);
        uasort($groups, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        foreach ($entries as &$entry) {
            $entry['ruleCount'] = $entry['ruleCount'] ?? 0;
            $entry['directRuleCount'] = $entry['directRuleCount'] ?? 0;
        }
        unset($entry);
        uasort($entries, static function ($a, $b) {
            if ($a['kind'] !== $b['kind']) return $a['kind'] === 'namespace' ? -1 : 1;
            return strnatcasecmp($a['name'], $b['name']);
        });
        $findings = [];
        foreach ($rootGrants as $rule) {
            $findings[] = ['type' => 'Root-level edit grant', 'severity' => 'review',
                'title' => $rule['principal'] . ' receives ' . $this->permissionName($rule['level']) . ' at wiki root',
                'detail' => 'Broad default permission; review whether more specific namespaces should carry this access.',
                'line' => $rule['line']];
        }
        foreach ($groups as $group) {
            if ($group['members'] === 0 && $group['references'] > 0) {
                $findings[] = ['type' => 'Empty ACL group', 'severity' => 'info',
                    'title' => '@' . $group['name'] . ' has no current members',
                    'detail' => 'May be an intentionally reserved or temporarily unused role. Keep until its purpose is confirmed.',
                    'line' => ($groupRules[$group['name']][0]['line'] ?? 0)];
            }
        }
        foreach ($unknownUsers as $id => $matched) {
            $findings[] = ['type' => 'Unmatched user ACL', 'severity' => 'review',
                'title' => 'ACL references user ' . $id . ' (not found)',
                'detail' => 'Check for a renamed, removed or intentionally reserved account before removing its ACL.',
                'line' => $matched[0]['line']];
        }
        // Same spelling does not make a user account a member of its namesake group.
        foreach ($groups as $group) {
            if (!isset($users[$group['name']]) || $group['members'] > 0) continue;
            $findings[] = ['type' => 'Account/group name collision', 'severity' => 'info',
                'title' => $group['name'] . ' (account) and @' . $group['name'] . ' (group) are separate',
                'detail' => 'An account with this login exists but is not a member of the matching group. This may be intentional for service accounts.',
                'line' => 0];
        }
        $groupNames = array_keys($groups);
        for ($i = 0; $i < count($groupNames); $i++) {
            for ($j = $i + 1; $j < count($groupNames); $j++) {
                $a = $groupNames[$i];
                $b = $groupNames[$j];
                if (strlen($a) < 5 || strlen($b) < 5 ||
                    strtolower($a) === strtolower($b) ||
                    levenshtein(strtolower($a), strtolower($b)) > 2) continue;
                $findings[] = ['type' => 'Similar group names', 'severity' => 'info',
                    'title' => '@' . $a . ' and @' . $b,
                    'detail' => 'Check whether these intentionally represent different roles before consolidating or renaming either group.',
                    'line' => 0];
            }
        }
        foreach ($duplicateMap as $same) {
            if (count($same) < 2) continue;
            $first = $same[0];
            $findings[] = ['type' => 'Duplicate ACL scope/principal', 'severity' => 'review',
                'title' => $first['scope'] . ' / ' . $first['principal'] . ' occurs ' . count($same) . ' times',
                'detail' => 'Multiple rules for the same scope and principal; check for redundant or conflicting levels.',
                'line' => $first['line']];
        }
        foreach ($entries as $entry) {
            if ($entry['onDisk'] || $entry['ruleCount'] === 0) continue;
            $findings[] = ['type' => 'ACL-only root entry', 'severity' => 'info',
                'title' => $entry['name'] . ' (' . $entry['kind'] . ')',
                'detail' => 'Referenced in ACL, but no matching root page/folder on disk. May be virtual, planned, moved or obsolete.',
                'line' => 0];
        }
        return [
            'totals' => ['users' => count($users), 'groups' => count($groups),
                'rules' => count($rules), 'rootEntries' => count($entries),
                'findings' => count($findings)],
            'groups' => array_values($groups),
            'entries' => array_values($entries),
            'findings' => $findings,
            'notice' => 'Read-only ACL inventory. Permission names are cumulative (Upload includes Create, Edit and Read). Rules shown for a group are written ACL entries, not necessarily the effective access of its members. Use Permissions to inspect users.',
        ];
    }
    /**
     * Export actual filesystem identifiers, without page contents or absolute paths.
     * An unreadable tree or a limit hit fails the export instead of producing a
     * misleading partial backup.
     */
    private function diskInventory(string $configKey, bool $pages): array
    {
        global $conf;
        $root = $conf[$configKey] ?? '';
        if (!is_string($root) || $root === '' || !is_dir($root) || !is_readable($root)) {
            throw new RuntimeException('The wiki ' . $configKey . ' directory is not accessible for audit export.');
        }
        $root = rtrim($root, '/\\');
        $namespaces = [];
        $pageIDs = [];
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $entry) {
                if ($entry->isLink()) continue; // Do not export content from symlinked external trees.
                $isDir = $entry->isDir();
                if (!$isDir && (!$pages || !str_ends_with($entry->getFilename(), '.txt'))) continue;
                if (++$count > 50000) {
                    throw new RuntimeException('Audit export stopped: ' . $configKey . ' has over 50,000 entries. No partial snapshot was downloaded.');
                }
                $relative = substr($entry->getPathname(), strlen($root) + 1);
                $segments = explode('/', str_replace('\\', '/', $relative));
                if (!$isDir) {
                    $last = count($segments) - 1;
                    $segments[$last] = substr($segments[$last], 0, -4);
                }
                $segments = array_map(static fn($segment) =>
                    function_exists('utf8_decodeFN') ? utf8_decodeFN($segment) : $segment, $segments);
                $id = implode(':', $segments);
                if ($id === '') continue;
                if ($isDir) $namespaces[] = $id;
                else $pageIDs[] = $id;
            }
        } catch (\UnexpectedValueException $e) {
            throw new RuntimeException('Unable to read a wiki directory during export: ' . $configKey, 0, $e);
        }
        natcasesort($namespaces);
        natcasesort($pageIDs);
        return [
            'namespaces' => array_values($namespaces),
            'pages' => array_values($pageIDs),
        ];
    }

    /**
     * Build a sanitized portable snapshot of the CURRENT wiki.
     * Never read or serialize password hashes, password fields, email addresses,
     * page content, absolute paths, or full auth configuration.
     */
    public function exportSnapshot(array $directory): array
    {
        if (!auth_isadmin()) throw new RuntimeException('Superuser access required.');
        if (empty($directory['supported'])) throw new RuntimeException('The user directory is unavailable.');
        $rules = $this->rules();
        $audit = $this->summary($directory, $rules);
        $users = [];
        $members = [];
        foreach ($directory['users'] ?? [] as $record) {
            $id = (string) ($record['id'] ?? '');
            if ($id === '') continue;
            $groups = array_values(array_map('strval', $record['groups'] ?? []));
            $users[] = [
                'username' => $id,
                'name' => (string) ($record['name'] ?? ''),
                'groups' => $groups,
            ];
            foreach ($groups as $group) $members[$group][] = $id;
        }
        usort($users, static fn($a, $b) => strnatcasecmp($a['username'], $b['username']));
        $groups = [];
        foreach ($audit['groups'] as $group) {
            $names = $members[$group['name']] ?? [];
            natcasesort($names);
            $groups[] = [
                'name' => $group['name'],
                'members' => array_values($names),
                'memberCount' => count($names),
                'aclRuleCount' => $group['references'],
                'classification' => $group['classification'],
                'aclRules' => $group['scopeRules'] ?? [],
            ];
        }
        $writtenRules = array_map(fn($rule) => [
            'line' => $rule['line'],
            'scope' => $rule['scope'],
            'principal' => $rule['principal'],
            'level' => $rule['level'],
            'permission' => $this->permissionName($rule['level']),
        ], $rules);
        $pages = $this->diskInventory('datadir', true);
        $media = $this->diskInventory('mediadir', false);
        return [
            'schema' => 'groupmanager-access-audit-v1',
            'generatedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
            'dataSource' => 'current DokuWiki installation',
            'privacy' => [
                'contains' => ['usernames', 'real names', 'group memberships', 'ACL rules', 'page and namespace IDs'],
                'excluded' => ['passwords', 'password hashes', 'email addresses', 'wiki page contents', 'absolute server paths'],
            ],
            'counts' => [
                'users' => count($users),
                'groups' => count($groups),
                'aclRules' => count($writtenRules),
                'namespaces' => count($pages['namespaces']),
                'pages' => count($pages['pages']),
                'mediaNamespaces' => count($media['namespaces']),
                'diagnostics' => count($audit['findings']),
            ],
            'users' => $users,
            'groups' => $groups,
            'aclRules' => $writtenRules,
            'inventory' => [
                'namespaces' => $pages['namespaces'],
                'pages' => $pages['pages'],
                'mediaNamespaces' => $media['namespaces'],
                'rootSummary' => $audit['entries'],
            ],
            'diagnostics' => $audit['findings'],
            'limitations' => [
                'Snapshot contains written ACL rules, not a precomputed per-user effective-access matrix.',
                'DokuWiki ACL precedence and plugin hooks must be checked against the running wiki before making changes.',
                'Workflow or plugin-specific permissions outside acl.auth.php are not captured.',
                'This is a read-only best-effort snapshot, not an atomic backup of concurrent administrative changes.',
            ],
        ];
    }

}
