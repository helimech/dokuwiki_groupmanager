<?php
/** Offline read-only audit smoke tests. Run: php tests/access_audit.php */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
function auth_isadmin() { return true; }
function utf8_encodeFN($s) { return $s; }
function utf8_decodeFN($s) { return $s; }
function checkAudit($condition, $label) {
    if (!$condition) throw new RuntimeException('Access Audit: ' . $label);
}
$temp = sys_get_temp_dir() . '/groupmanager-audit-' . bin2hex(random_bytes(5));
mkdir($temp);
mkdir($temp . '/pages');
mkdir($temp . '/pages/wip');
mkdir($temp . '/pages/hr');
mkdir($temp . '/media');
mkdir($temp . '/media/photos');
file_put_contents($temp . '/pages/wip/status.txt', "WORK IN PROGRESS PRIVATE TEXT");
file_put_contents($temp . '/pages/hr/employee.txt', "EMPLOYEE CONFIDENTIAL PAGE");
file_put_contents($temp . '/pages/start.txt', "start");
$acl = $temp . '/acl.auth.php';
file_put_contents($acl, "# <?php exit()?>\n"
    . "*\t@ALL\t0\n"
    . "*\t@manager\t8\n"
    . "*\t@wip\t2\n"
    . "wip:*\t@wip\t8\n"
    . "hr:employees:*\t@newemploy\t8\n"
    . "policy:*\t@contract\t1\n"
    . "forms:*\t@forms\t8\n"
    . "staff:calendar\tolduser\t1\n"
    . "wip:*\t@wip\t8\n");
$conf = ['datadir' => $temp . '/pages', 'mediadir' => $temp . '/media'];
$config_cascade = ['acl' => ['default' => $acl]];
require_once dirname(__DIR__) . '/inc/AccessAudit.php';
try {
    $audit = new \dokuwiki\plugin\groupmanager\AccessAudit();
    $directory = [
        'supported' => true,
        'users' => [
            ['id' => 'forms', 'name' => 'Virtual Forms', 'groups' => ['admin'],
                'mail' => 'test@example.com', 'pass' => 'NEVER_EXPORT_PASSWORD'],
            ['id' => 'worker', 'name' => 'Test Worker', 'groups' => ['staff', 'wip']]
        ],
        'groups' => [
            ['name' => 'wip', 'count' => 1, 'registered' => false],
            ['name' => 'manager', 'count' => 1, 'registered' => false],
            ['name' => 'newemploy', 'count' => 1, 'registered' => false]
        ]
    ];
    $result = $audit->summary($directory);
    $groups = array_column($result['groups'], null, 'name');
    checkAudit($result['totals']['rules'] === 9, 'counts actual ACL rules');
    checkAudit($groups['contract']['members'] === 0, 'keeps empty reserved contractor group');
    checkAudit($groups['forms']['members'] === 0 && $groups['forms']['references'] === 1,
        'distinguishes forms service account from @forms group');
    checkAudit($groups['wip']['references'] === 3, 'counts ACL usage for working group');
    checkAudit(count($groups['wip']['scopeRules']) === 3, 'lists each ACL rule with its permission');
    checkAudit($groups['wip']['scopeRules'][0]['permission'] === 'Edit', 'labels level 2 Edit');
    checkAudit($groups['wip']['scopeRules'][1]['scope'] === 'wip:*'
        && $groups['wip']['scopeRules'][1]['permission'] === 'Upload', 'labels level 8 Upload');
    checkAudit($groups['contract']['scopeRules'][0]['permission'] === 'Read', 'labels level 1 Read');
    $broadRoot = array_values(array_filter($result['findings'],
        static fn($finding) => $finding['type'] === 'Root-level edit grant'));
    checkAudit(count($broadRoot) === 2, 'identifies broad root rules');
    checkAudit(str_contains($broadRoot[0]['title'], 'receives Upload at wiki root'),
        'diagnostics use the permission name instead of numeric 8');
    $roots = [];
    foreach ($result['entries'] as $entry) $roots[$entry['kind'] . '|' . $entry['name']] = $entry;
    checkAudit($roots['namespace|wip']['onDisk'] === true, 'recognizes live namespace');
    checkAudit($roots['namespace|policy']['onDisk'] === false, 'flags ACL-only legacy namespace');
    checkAudit($roots['page|start']['onDisk'] === true, 'includes root pages');
    $types = array_column($result['findings'], 'type');
    checkAudit(in_array('Root-level edit grant', $types, true), 'flags broad root grants');
    checkAudit(in_array('Unmatched user ACL', $types, true), 'flags ACL for unknown user');
    checkAudit(in_array('Duplicate ACL scope/principal', $types, true), 'flags duplicated ACL entries');
    checkAudit(in_array('Empty ACL group', $types, true), 'reviews but does not delete empty groups');
    $snapshot = $audit->exportSnapshot($directory);
    checkAudit($snapshot['schema'] === 'groupmanager-access-audit-v1', 'portable schema marker');
    checkAudit(count($snapshot['users']) === 2, 'exports all current user accounts');
    checkAudit($snapshot['counts']['aclRules'] === 9, 'retains every written ACL rule');
    checkAudit($snapshot['aclRules'][1]['permission'] === 'Upload', 'provides readable ACL level');
    checkAudit(in_array('hr', $snapshot['inventory']['namespaces'], true), 'exports namespace structure');
    checkAudit(in_array('wip:status', $snapshot['inventory']['pages'], true), 'exports nested page IDs');
    checkAudit(in_array('hr:employee', $snapshot['inventory']['pages'], true), 'exports confidential page ID, not page content');
    checkAudit(in_array('photos', $snapshot['inventory']['mediaNamespaces'], true), 'exports media namespace structure');
    $exportedGroups = array_column($snapshot['groups'], null, 'name');
    checkAudit($exportedGroups['wip']['members'] === ['worker'], 'exports members of functional groups');
    checkAudit($exportedGroups['forms']['members'] === [], 'keeps namesake service account separate from ACL group');
    checkAudit($exportedGroups['contract']['members'] === [], 'preserves reserved empty groups');
    $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
    checkAudit(!str_contains($json, 'NEVER_EXPORT_PASSWORD'), 'excludes password data');
    checkAudit(!str_contains($json, 'test@example.com'), 'excludes email data');
    checkAudit(!str_contains($json, 'EMPLOYEE CONFIDENTIAL PAGE'), 'excludes page content');
    checkAudit(!str_contains($json, $temp), 'excludes absolute filesystem paths');
    echo "Access audit smoke tests passed.\n";
} finally {
    unlink($acl);
    unlink($temp . '/pages/start.txt');
    unlink($temp . '/pages/wip/status.txt');
    unlink($temp . '/pages/hr/employee.txt');
    rmdir($temp . '/pages/wip');
    rmdir($temp . '/pages/hr');
    rmdir($temp . '/pages');
    rmdir($temp . '/media/photos');
    rmdir($temp . '/media');
    rmdir($temp);
}
