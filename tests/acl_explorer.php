<?php

/** Offline ACL explorer smoke tests: php tests/acl_explorer.php */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/stubs/AuthPlugin.php';

    function checkAcl(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException('ACL test: ' . $message);
    }
    function cleanID($s)
    {
        return preg_replace('/[^a-zA-Z0-9:_-]/', '', trim((string)$s, ':'));
    }
    function auth_nameencode($s)
    {
        return $s;
    }
    function utf8_encodeFN($s)
    {
        return $s;
    }
    function auth_isadmin($user = null, $groups = [])
    {
        return $user === null || $user === 'rootadmin' || in_array('admin', $groups, true);
    }
    function auth_aclcheck($id, $user, $groups)
    {
        global $AUTH_ACL;
        if (auth_isadmin($user, $groups)) return 255;
        $scopeList = [$id];
        $parts = explode(':', $id);
        array_pop($parts);
        while ($parts) {
            $scopeList[] = implode(':', $parts) . ':*';
            array_pop($parts);
        }
        $scopeList[] = '*';
        $scopeList = array_unique($scopeList);
        foreach ($scopeList as $scope) {
            $levels = [];
            foreach ($AUTH_ACL as $line) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 3 || $parts[0] !== $scope) continue;
                if (
                    $parts[1] === '@ALL' || ($parts[1] === $user && $user) ||
                    (str_starts_with($parts[1], '@') && in_array(substr($parts[1], 1), $groups, true))
                ) {
                    $levels[] = (int) $parts[2];
                }
            }
            if ($levels) return max($levels);
        }
        return 0;
    }
    function search(&$found, $base, $callback, $options, $folder = '', $level = 1)
    {
        // Stub filesystem-only immediate children, just like a real namespace expansion.
        $abs = $base . ($folder ? '/' . $folder : '');
        foreach (scandir($abs) as $f) {
            if ($f[0] === '.') continue;
            $id = str_replace('/', ':', ltrim($folder . '/' . $f, '/'));
            if (is_dir($abs . '/' . $f)) {
                $found[] = ['id' => $id, 'type' => 'd'];
            } elseif ($options['listfiles'] && str_ends_with($f, '.txt')) {
                $found[] = ['id' => substr($id, 0, -4), 'type' => 'f'];
            }
        }
    }
    $dir = sys_get_temp_dir() . '/gm-acl-test-' . bin2hex(random_bytes(4));
    mkdir($dir);
    mkdir($dir . '/pages', 0700);
    mkdir($dir . '/media', 0700);
    mkdir($dir . '/pages/engineering', 0700);
    mkdir($dir . '/pages/engineering/restricted', 0700);
    mkdir($dir . '/media/engineering', 0700);
    file_put_contents($dir . '/pages/engineering/restricted/manual.txt', 'test');
    file_put_contents($dir . '/pages/home.txt', 'test');
    $aclFile = $dir . '/acl.auth.php';
    file_put_contents($aclFile, implode("\n", [
       '* @ALL 1',
       'engineering:* @staff 2',
       'engineering:restricted:* @qa 1',
       'engineering:restricted:manual @qa 2',
       'engineering:restricted:manual @staff 1',
       'engineering:ghost:* @qa 1',
       'engineering:docs:lost @staff 1',
       'home:%USER%:* %USER% 2',
       'teams:%GROUP%:* %GROUP% 1',
       'engineering:restricted:manual jsmith 0',
    ]) . "\n");
    $config_cascade = ['acl' => ['default' => $aclFile]];
    $conf = ['useacl' => true,'datadir' => $dir . '/pages','mediadir' => $dir . '/media'];
    $AUTH_ACL = ['ORIGINAL GLOBAL'];
    $backend = new \dokuwiki\Extension\AuthPlugin();
    $backend->users = ['jsmith' => ['name' => 'Jane Smith','grps' => ['staff','qa']], 'mary' => ['name' => 'Mary','grps' => ['staff','qa']], 'rootadmin' => ['name' => 'Root','grps' => ['admin']]];
    require dirname(__DIR__) . '/inc/AclExplorer.php';
    $service = new \dokuwiki\plugin\groupmanager\AclExplorer($backend);
    $root = $service->tree('user', 'mary', '');
    checkAcl(in_array('engineering', array_column($root['nodes'], 'id'), true), 'missing on-disk namespace');
    checkAcl(in_array('teams', array_column($root['nodes'], 'id'), true), 'dynamic group scope missing');
    checkAcl(in_array('home', array_column($root['nodes'], 'id'), true), 'dynamic user scope missing');
    checkAcl($AUTH_ACL === ['ORIGINAL GLOBAL'], 'auth global not restored');
    $engineering = $service->tree('user', 'mary', 'engineering');
    checkAcl(in_array('engineering:restricted', array_column($engineering['nodes'], 'id'), true), 'folder missing');
    checkAcl(in_array('engineering:ghost', array_column($engineering['nodes'], 'id'), true), 'orphaned ACL namespace missing');
    checkAcl(in_array('engineering:docs', array_column($engineering['nodes'], 'id'), true), 'ACL-only parent missing');
    $engineeringDetail = $service->explain('user', 'mary', 'engineering', 'namespace');
    checkAcl($engineeringDetail['permission'] === 2 && $engineeringDetail['sourceType'] === 'explicit', 'expected explicit edit');
    $restricted = $service->explain('user', 'mary', 'engineering:restricted', 'namespace');
    checkAcl($restricted['permission'] === 1 && $restricted['sourceType'] === 'explicit', 'more specific namespace should override broader staff edit');
    $manual = $service->explain('user', 'mary', 'engineering:restricted:manual', 'page');
    checkAcl($manual['permission'] === 2 && $manual['sourceType'] === 'explicit', 'highest rule in exact scope should win');
    checkAcl(count($manual['matchingRules'][0]['rules']) === 2, 'missing matching same-scope rules');
    $deny = $service->explain('user', 'jsmith', 'engineering:restricted:manual', 'page');
    checkAcl($deny['permission'] === 2, 'user None should not override a group Edit at same scope');
    $group = $service->explain('group', 'qa', 'engineering:restricted:manual', 'page');
    checkAcl($group['permission'] === 2 && count($group['subject']['groups']) === 1, 'group simulation incorrect');
    $groupRoot = $service->explain('group', 'qa', 'engineering', 'namespace');
    checkAcl($groupRoot['permission'] === 1 && $groupRoot['sourceType'] === 'inherited', 'group-only simulation includes @ALL');
    $personal = $service->explain('user', 'mary', 'home:mary', 'namespace');
    checkAcl($personal['permission'] === 2 && $personal['sourceScope'] === 'home:mary:*', '%USER% substitution not applied');
    $team = $service->explain('user', 'mary', 'teams:staff', 'namespace');
    checkAcl($team['permission'] === 1 && $team['sourceType'] === 'explicit', '%GROUP% substitution not applied');
    $admin = $service->explain('user', 'rootadmin', 'engineering', 'namespace');
    checkAcl($admin['permission'] === 255 && $admin['sourceType'] === 'superuser', 'superuser override missing');
    foreach ([['type' => 'foo','id' => 'engineering'],['type' => 'page','id' => '../bad']] as $bad) {
        try {
            $service->explain('user', 'mary', $bad['id'], $bad['type']);
            throw new RuntimeException('Invalid node accepted');
        } catch (InvalidArgumentException) {
        }
    }
    try {
        $service->tree('user', 'doesnotexist', '');
        throw new RuntimeException('Unknown user allowed');
    } catch (InvalidArgumentException) {
    }
    checkAcl($AUTH_ACL === ['ORIGINAL GLOBAL'], 'global ACL not restored after explanations');
    echo "PASS: ACL tree, empty scopes, native resolution, provenance, precedence, wildcard expansion, superuser, validation, global restoration\n";
