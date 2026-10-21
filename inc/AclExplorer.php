<?php
/**
 * Read-only ACL explorer for Group Manager.
 *
 * @license GPL-2.0-or-later
 */
namespace dokuwiki\plugin\groupmanager;

use dokuwiki\Extension\AuthPlugin;
use InvalidArgumentException;
use RuntimeException;

final class AclExplorer
{
    private AuthPlugin $backend;

    public function __construct(AuthPlugin $backend)
    {
        $this->backend = $backend;
    }

    private function validateSubject(string $kind, string $name): array
    {
        if ($kind !== 'user' && $kind !== 'group') {
            throw new InvalidArgumentException('Choose a user or group.');
        }
        if ($name === '' || strlen($name) > 250) {
            throw new InvalidArgumentException('Select an existing user or group.');
        }
        if ($kind === 'user') {
            $info = $this->backend->getUserData($name);
            if (!is_array($info)) throw new InvalidArgumentException('The selected user no longer exists.');
            return ['kind' => 'user', 'name' => $name, 'user' => $name,
                'groups' => array_values(array_unique(array_map('strval', $info['grps'] ?? [])))];
        }
        // A group may be empty and exist only in Group Manager's preserved registry.
        // Validation of the name against the registry takes place in the AJAX controller.
        return ['kind' => 'group', 'name' => $name, 'user' => '', 'groups' => [$name]];
    }

    private function aclFile(): string
    {
        global $config_cascade;
        $file = $config_cascade['acl']['default'] ?? '';
        if (!is_string($file) || !$file || !is_readable($file)) {
            throw new RuntimeException('DokuWiki ACL configuration is not readable.');
        }
        return $file;
    }

    /** Read the authoritative ACL file, not a request-global expanded for the logged-in admin. */
    private function rawRules(): array
    {
        $lines = file($this->aclFile(), FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) throw new RuntimeException('Unable to load DokuWiki ACL rules.');
        $rules = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $parts = preg_split('/[ \t]+/', $line);
            if (count($parts) < 3 || !preg_match('/^\d+$/', $parts[2])) continue;
            $rules[] = ['scope' => $parts[0], 'principal' => $parts[1], 'level' => (int)$parts[2]];
        }
        return $rules;
    }

    /** Equivalent expansion to auth_loadACL(), but with the SELECTED principal's identity. */
    private function expandRules(array $raw, array $subject): array
    {
        $out = [];
        foreach ($raw as $rule) {
            $scope = $rule['scope'];
            $who = $rule['principal'];
            if (str_contains($scope, '%USER%') || str_contains($who, '%USER%')) {
                if ($subject['user'] === '') continue;
                $scope = str_replace('%USER%', cleanID($subject['user']), $scope);
                $who = str_replace('%USER%', auth_nameencode($subject['user']), $who);
            }
            if (str_contains($scope, '%GROUP%') || str_contains($who, '%GROUP%')) {
                foreach ($subject['groups'] as $group) {
                    $out[] = ['scope' => str_replace('%GROUP%', cleanID($group), $scope),
                        'principal' => str_replace('%GROUP%', '@' . auth_nameencode($group), $who),
                        'level' => $rule['level']];
                }
            } else {
                $out[] = ['scope' => $scope, 'principal' => $who, 'level' => $rule['level']];
            }
        }
        return $out;
    }

    private function aclLine(array $rule): string
    {
        return $rule['scope'] . "\t" . $rule['principal'] . "\t" . $rule['level'] . "\n";
    }

    private function idParents(string $id): array
    {
        if ($id === '') return [];
        $out = [];
        $parts = explode(':', $id);
        while (count($parts) > 1) {
            array_pop($parts);
            $out[] = implode(':', $parts);
        }
        return $out;
    }

    private function ruleScopes(string $id, string $type): array
    {
        if ($type === 'root') return ['*'];
        if ($type === 'namespace') {
            return array_merge([$id . ':*'], array_map(static fn($p) => $p . ':*', $this->idParents($id)), ['*']);
        }
        if ($type === 'page') {
            return array_merge([$id], array_map(static fn($p) => $p . ':*', $this->idParents($id)), ['*']);
        }
        throw new InvalidArgumentException('Invalid tree node type.');
    }

    private function matchesSubject(string $principal, array $subject): bool
    {
        $principal = rawurldecode($principal);
        $case = $this->backend->isCaseSensitive();
        $normalize = static fn($v) => $case ? $v : (function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v));
        if ($principal === '@ALL') return true;
        if (str_starts_with($principal, '@')) {
            return in_array($normalize(substr($principal, 1)), array_map($normalize, $subject['groups']), true);
        }
        return $subject['user'] !== '' && $normalize($principal) === $normalize($subject['user']);
    }

    /** Return the applicable rule at the nearest scope, highest level within that scope. */
    private function provenance(array $rules, array $subject, string $id, string $type): array
    {
        $scopes = $this->ruleScopes($id, $type);
        $applicable = [];
        foreach ($scopes as $order => $scope) {
            $matched = [];
            foreach ($rules as $rule) {
                if ($rule['scope'] === $scope && $this->matchesSubject($rule['principal'], $subject)) {
                    $matched[] = $rule;
                }
            }
            if ($matched) {
                $highest = max(array_column($matched, 'level'));
                foreach ($matched as &$rule) $rule['winning'] = ($rule['level'] === $highest);
                unset($rule);
                $applicable[] = ['scope' => $scope, 'rules' => $matched, 'winningLevel' => $highest,
                    'selected' => true, 'isExact' => $order === 0];
                // Collect broader matching entries for explaining overrides, without changing selection.
                for ($k = $order + 1; $k < count($scopes); $k++) {
                    $other = [];
                    foreach ($rules as $rule) {
                        if ($rule['scope'] === $scopes[$k] && $this->matchesSubject($rule['principal'], $subject)) {
                            $rule['winning'] = false;
                            $other[] = $rule;
                        }
                    }
                    if ($other) $applicable[] = ['scope' => $scopes[$k], 'rules' => $other,
                        'winningLevel' => max(array_column($other, 'level')), 'selected' => false, 'isExact' => false];
                }
                return ['sourceScope' => $scope, 'sourceType' => $order === 0 ? 'explicit' : 'inherited',
                    'computed' => $highest, 'applicable' => $applicable, 'explicit' => $order === 0];
            }
        }
        return ['sourceScope' => '', 'sourceType' => 'none', 'computed' => 0, 'applicable' => [], 'explicit' => false];
    }

    private function levelName(int $value): string
    {
        return match ($value) {
            0 => 'None', 1 => 'Read', 2 => 'Edit', 4 => 'Create', 8 => 'Upload', 16 => 'Delete', 255 => 'Superuser',
            default => 'Level ' . $value,
        };
    }

    /** Native auth_aclcheck includes DokuWiki's event hooks. Always restore its global ACL. */
    private function withSelectedAcl(array $rules, callable $callback)
    {
        global $AUTH_ACL;
        $previous = $AUTH_ACL ?? null;
        $AUTH_ACL = array_map($this->aclLine(...), $rules);
        try {
            return $callback();
        } finally {
            $AUTH_ACL = $previous;
        }
    }

    private function resolve(array $rules, array $subject, string $id, string $type): array
    {
        $scope = $type === 'root' ? '*' : ($type === 'namespace' ? $id . ':*' : $id);
        $source = $this->provenance($rules, $subject, $id, $type);
        $native = (int) auth_aclcheck($scope, $subject['user'], $subject['groups']);
        if ($native === 255 && auth_isadmin($subject['user'], $subject['groups'])) {
            $source['sourceType'] = 'superuser';
            $source['sourceScope'] = 'DokuWiki superuser configuration';
        } elseif ($source['computed'] !== $native) {
            $source['sourceType'] = 'adjusted';
            $source['sourceScope'] = 'ACL hook or backend override';
        }
        return [
            'permission' => $native,
            'permissionLabel' => $this->levelName($native),
            'sourceType' => $source['sourceType'],
            'sourceScope' => $source['sourceScope'],
            'explicitRule' => $source['explicit'],
            'matchingRules' => $source['applicable'],
            'note' => $source['sourceType'] === 'adjusted'
                ? 'A DokuWiki ACL hook or backend produced a result different from standard ACL rule evaluation.'
                : '',
        ];
    }

    private function addNode(array &$nodes, string $id, string $type, bool $onDisk): void
    {
        if ($id === '') return;
        $key = $type . '|' . $id;
        $nodes[$key] = [
            'id' => $id,
            'type' => $type,
            'onDisk' => $onDisk || ($nodes[$key]['onDisk'] ?? false),
        ];
    }

    private function aclPathNodes(array $rules): array
    {
        $nodes = [];
        foreach ($rules as $rule) {
            $scope = $rule['scope'];
            if ($scope === '*' || str_contains($scope, '%')) continue;
            if (str_ends_with($scope, ':*')) {
                $id = substr($scope, 0, -2);
                if ($id !== '') $this->addNode($nodes, $id, 'namespace', false);
                foreach ($this->idParents($id) as $parent) $this->addNode($nodes, $parent, 'namespace', false);
            } elseif (!str_contains($scope, '*')) {
                $this->addNode($nodes, $scope, 'page', false);
                foreach ($this->idParents($scope) as $parent) $this->addNode($nodes, $parent, 'namespace', false);
            }
        }
        return $nodes;
    }

    private function fromDisk(string $parent): array
    {
        global $conf;
        $out = [];
        $folder = $parent === '' ? '' : utf8_encodeFN(str_replace(':', '/', $parent));
        $level = $parent === '' ? 1 : count(explode(':', $parent)) + 1;
        foreach ([['datadir', true], ['mediadir', false]] as [$setting, $pages]) {
            $base = $conf[$setting] ?? '';
            if (!is_string($base) || $base === '' || !is_dir($base . ($folder === '' ? '' : '/' . $folder))) continue;
            $found = [];
            search($found, $base, 'search_universal', [
                'listdirs' => true, 'listfiles' => $pages, 'pagesonly' => true,
                'skipacl' => true, 'depth' => $level,
            ], $folder, $level);
            foreach ($found as $item) {
                if (($item['type'] ?? '') === 'd') {
                    $this->addNode($out, $item['id'], 'namespace', true);
                } elseif ($pages && ($item['type'] ?? '') === 'f') {
                    $this->addNode($out, $item['id'], 'page', true);
                }
            }
        }
        return $out;
    }

    private function parentOf(string $id): string
    {
        $pos = strrpos($id, ':');
        return $pos === false ? '' : substr($id, 0, $pos);
    }

    private function hasRelevantExplicitBelow(array $rules, array $subject, string $id, string $type): bool
    {
        $prefix = $type === 'root' ? '' : $id . ':';
        foreach ($rules as $rule) {
            if (!$this->matchesSubject($rule['principal'], $subject)) continue;
            if ($type === 'root' || str_starts_with($rule['scope'], $prefix)) return true;
        }
        return false;
    }

    /** One namespace level at a time: no massive whole-wiki client payload. */
    public function tree(string $kind, string $name, string $parent): array
    {
        if (!auth_isadmin()) throw new RuntimeException('Superuser access required.');
        $subject = $this->validateSubject($kind, $name);
        if (strlen($parent) > 500 || ($parent !== '' && cleanID($parent) !== $parent)) {
            throw new InvalidArgumentException('Invalid namespace identifier.');
        }
        $rules = $this->expandRules($this->rawRules(), $subject);
        $all = $this->aclPathNodes($rules);
        $children = [];
        foreach ($all as $node) {
            if ($this->parentOf($node['id']) === $parent) $this->addNode($children, $node['id'], $node['type'], false);
        }
        foreach ($this->fromDisk($parent) as $node) {
            if ($this->parentOf($node['id']) === $parent) $this->addNode($children, $node['id'], $node['type'], true);
        }
        $visible = [];
        $this->withSelectedAcl($rules, function () use (&$visible, $children, $subject, $rules) {
            foreach ($children as $child) {
                $id = $child['id'];
                $type = $child['type'];
                $permission = $this->resolve($rules, $subject, $id, $type);
                unset($permission['matchingRules'], $permission['note']);
                $visible[] = array_merge($child, $permission, [
                    'name' => substr($id, (strrpos($id, ':') === false ? -1 : strrpos($id, ':')) + 1),
                    'hasChildren' => $type === 'namespace',
                    'hasExplicitBelow' => $type === 'namespace' && $this->hasRelevantExplicitBelow($rules, $subject, $id, $type),
                ]);
            }
        });
        usort($visible, static function ($a, $b) {
            if ($a['type'] !== $b['type']) return $a['type'] === 'namespace' ? -1 : 1;
            return strnatcasecmp($a['name'], $b['name']);
        });
        return ['nodes' => $visible, 'parent' => $parent, 'subject' => ['kind' => $kind, 'name' => $name],
            'dynamicRuleCount' => count(array_filter($this->rawRules(), static fn($r) => str_contains($r['scope'] . $r['principal'], '%USER%') || str_contains($r['scope'] . $r['principal'], '%GROUP%')))];
    }

    public function explain(string $kind, string $name, string $id, string $type): array
    {
        if (!auth_isadmin()) throw new RuntimeException('Superuser access required.');
        $subject = $this->validateSubject($kind, $name);
        if ($type !== 'root' && $type !== 'namespace' && $type !== 'page') throw new InvalidArgumentException('Invalid node type.');
        if (($id === '' && $type !== 'root') || strlen($id) > 500 || ($id !== '' && cleanID($id) !== $id)) {
            throw new InvalidArgumentException('Invalid page or namespace ID.');
        }
        $rules = $this->expandRules($this->rawRules(), $subject);
        $result = $this->withSelectedAcl($rules, fn() => $this->resolve($rules, $subject, $id, $type));
        return array_merge(['id' => $id, 'type' => $type, 'subject' => $subject,
            'scope' => $type === 'root' ? '*' : ($type === 'namespace' ? $id . ':*' : $id)], $result);
    }
}
