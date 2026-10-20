<?php
/** Run: php tests/smoke.php. Offline mock of the DokuWiki auth API. */
namespace { if (PHP_SAPI !== 'cli') { http_response_code(403); exit; } }
namespace dokuwiki\Extension {
    class Plugin {}
    class AuthPlugin {
        public $users = [];
        public $write = true;
        public function canDo($key) { return $key === 'getUsers' || $key === 'getGroups' || (($key === 'modGroups' || $key === 'addUser') && $this->write); }
        public function retrieveUsers($start=0,$limit=0,$filter=[]) { return $this->users; }
        public function retrieveGroups($start=0,$limit=0) { $groups = [];foreach ($this->users as $rec) $groups = array_merge($groups, $rec['grps']);return array_unique($groups); }
        public function getUserData($name) { return $this->users[$name] ?? false; }
        public function triggerUserMod($type,$params) {
            if ($type === 'create') {
                if (isset($this->users[$params[0]])) return false;
                $this->users[$params[0]] = ['name' => $params[2], 'mail' => $params[3], 'grps' => $params[4], 'pass' => 'hash:' . hash('sha256', $params[1])];
                return true;
            }
            $this->users[$params[0]] = array_merge($this->users[$params[0]], $params[1]);return true;
        }
        public function cleanGroup($name) { return $name; }
        public function cleanUser($name) { return $name; }
    }
}
namespace {
    define('DOKU_COOKIE', 'DW');
    function auth_isadmin() { return true; }
    function io_saveFile($file,$value) { return file_put_contents($file,$value) !== false; }
    class FakeServer { function str($name,$default='') {return $name === 'REMOTE_USER' ? 'test-admin' : $default;} }
    class FakeInput { public $server; function __construct() {$this->server = new FakeServer();} }
    $INPUT = new FakeInput();
    $_SESSION = [];
    $folder = sys_get_temp_dir() . '/groupmanager-test-' . bin2hex(random_bytes(5));
    mkdir($folder);
    mkdir($folder . '/meta');mkdir($folder . '/cache');
    $conf = ['metadir' => $folder . '/meta', 'cachedir' => $folder . '/cache', 'defaultgroup' => 'staff', 'superuser' => '@admin', 'manager' => '@manager'];
    $auth = new \dokuwiki\Extension\AuthPlugin();
    $auth->users = [
        'john' => ['name'=>'John Smith','grps'=>['staff','wip']],
        'mary' => ['name'=>'Mary Doe','grps'=>['staff','qa']],
        'boss' => ['name'=>'Director','grps'=>['staff','admin']]
    ];
    require dirname(__DIR__) . '/helper.php';
    $h = new \helper_plugin_groupmanager();
    function check($condition,$msg) {if (!$condition) throw new \RuntimeException($msg);}
    $data = $h->directoryData(true);
    check(count($data['users']) === 3, 'users not loaded');
    check(in_array('wip', array_column($data['groups'],'name'),true),'wip not listed');
    $result = $h->saveMemberships([['user'=>'john','before'=>['staff','wip'],'after'=>['staff']]]);
    check($result['changed'] === 1 && $result['results'][0]['ok'],'save failed');
    $data = $h->directoryData(true);
    $wip = array_values(array_filter($data['groups'], fn($g)=>$g['name']==='wip'))[0];
    check($wip['count'] === 0 && $wip['registered'],'empty group not preserved');
    $result = $h->saveMemberships([['user'=>'mary','before'=>['staff'],'after'=>['staff','wip']]]);
    check(!$result['results'][0]['ok'] && str_contains($result['results'][0]['message'],'Conflict'),'stale save permitted');
    $result = $h->saveMemberships([['user'=>'john','before'=>['staff'],'after'=>['admin','staff']]]);
    check(!$result['results'][0]['ok'],'privileged group allowed');
    $result = $h->saveMemberships([['user'=>'boss','before'=>['admin','staff'],'after'=>['staff']]]);
    check(!$result['results'][0]['ok'],'admin removal allowed');
    $result = $h->createGroup('engineering');
    check($result['created'],'group creation failed');
    $data = $h->directoryData(true);
    check(in_array('engineering',array_column($data['groups'],'name'),true),'new group missing');
    $result = $h->forgetGroup('engineering');
    check($result['forgotten'],'forget failed');
    check(!in_array('engineering',array_column($h->directoryData(true)['groups'],'name'),true),'group remained');
    $auth->write = false;
    $result = $h->directoryData(true);
    check(!$result['writable'],'read-only backend not detected');
    check(file_exists($folder . '/meta/groupmanager/audit.jsonl'),'audit file missing');
    $auth->write = true;
    $t = $h->saveTemplate('', 'Stores Clerk', ['staff','wip']);
    check(!empty($t['id']), 'template id missing');
    $templates = $h->directoryData()['templates'];
    check(count($templates) === 1 && $templates[0]['name'] === 'Stores Clerk', 'template not listed');
    try { $h->forgetGroup('wip'); throw new \RuntimeException('template group could be forgotten'); }
    catch (\RuntimeException $expected) {check(str_contains($expected->getMessage(), 'role template'), 'forget group error not template-specific');}
    try { $h->saveTemplate('', 'Too privileged', ['staff', 'admin']); throw new \RuntimeException('privileged template accepted'); }
    catch (\InvalidArgumentException $expected) {}
    $changed = $h->saveMemberships([['user'=>'mary','before'=>['qa','staff'],'after'=>['admin','qa','staff']]], true);
    check($changed['changed'] === 1, 'override did not permit non-self user');
    $last = $h->saveMemberships([['user'=>'boss','before'=>['admin','staff'],'after'=>['staff']]], true);
    check($last['changed'] === 1, 'override rejected when another superuser still exists');
    $blocked = $h->saveMemberships([['user'=>'mary','before'=>['admin','qa','staff'],'after'=>['qa','staff']]], true);
    check($blocked['changed'] === 0 && str_contains($blocked['results'][0]['message'], 'last effective'), 'last superuser removal allowed');
    $created = $h->createUser('newperson', 'New Person', 'new@example.com', ['wip']);
    check(strlen($created['temporaryPassword']) === 32 && in_array('staff',$auth->users['newperson']['grps'],true), 'create user password or default group missing');
    check($h->directoryData()['canCreate'] === true, 'canCreate not advertised');
    $registryContents = file_get_contents($folder . '/meta/groupmanager/audit.jsonl');
    check(!str_contains($registryContents, $created['temporaryPassword']), 'password leaked to audit');
    try { $h->createUser('newperson', 'New Person', 'new@example.com', ['staff']); throw new \RuntimeException('duplicate user allowed'); }
    catch (\RuntimeException $expected) {check(str_contains($expected->getMessage(), 'exists'), 'wrong duplicate error');}
    try { $h->createUser('newadmin', 'New Admin', 'admin@example.com', ['admin']); throw new \RuntimeException('privileged onboarding allowed'); }
    catch (\InvalidArgumentException $expected) {}
    $h->deleteTemplate($t['id']);
    check(count($h->directoryData()['templates']) === 0, 'template delete failed');
    $auth->write = false;
    check(!$h->directoryData()['canCreate'], 'create permitted on read-only backend');
    echo "PASS: registry, membership conflicts, override, last superuser, templates, onboarding, audit, read-only\n";
    // Deliberately leave the disposable fixtures in the OS temporary directory for inspection.
}
