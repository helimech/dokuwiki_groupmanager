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
$conf = ['datadir' => $temp . '/pages'];
$config_cascade = ['acl' => ['default' => $acl]];
require_once dirname(__DIR__) . '/inc/AccessAudit.php';
try {
    $audit = new \dokuwiki\plugin\groupmanager\AccessAudit();
    $result = $audit->summary([
        'users' => [['id' => 'forms', 'name' => 'Service', 'groups' => ['admin']]],
        'groups' => [
            ['name' => 'wip', 'count' => 1, 'registered' => false],
            ['name' => 'manager', 'count' => 1, 'registered' => false],
            ['name' => 'newemploy', 'count' => 1, 'registered' => false]
        ]
    ]);
    $groups = array_column($result['groups'], null, 'name');
    checkAudit($result['totals']['rules'] === 9, 'counts actual ACL rules');
    checkAudit($groups['contract']['members'] === 0, 'keeps empty reserved contractor group');
    checkAudit($groups['forms']['members'] === 0 && $groups['forms']['references'] === 1,
        'distinguishes forms service account from @forms group');
    checkAudit($groups['wip']['references'] === 3, 'counts ACL usage for working group');
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
    echo "Access audit smoke tests passed.\n";
} finally {
    unlink($acl);
    unlink($temp . '/pages/start.txt');
    rmdir($temp . '/pages/wip');
    rmdir($temp . '/pages/hr');
    rmdir($temp . '/pages');
    rmdir($temp);
}
