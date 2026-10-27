<?php
/**
 * Self-check for the Tino Domain module. Pure checks always run; live checks run when
 * TINO_TOKEN is set (a personal API token of a real Tino account, read-only calls only):
 *
 *   TINO_TOKEN=tinopat_... php modules/registrars/tinowhmcs/tests/run.php
 */

define('WHMCS', true);

// Minimal in-memory stand-in for WHMCS\Database\Capsule (only what TinoApi uses).
if (!class_exists('WHMCS\Database\Capsule')) {
    eval('namespace WHMCS\Database; class Capsule {
        public static $rows = [];
        public static function schema() { return new self; }
        public static function table($t) { return new self; }
        public function hasTable($t) { return true; }
        public function create($t, $fn) {}
        private $k;
        public function where($c, $op, $v = null) { $this->k = $v === null ? $op : $v; return $this; }
        public function delete() { $re = "/^" . str_replace("%", ".*", preg_quote($this->k, "/")) . "$/";
            foreach (array_keys(self::$rows) as $k) { if (preg_match($re, $k)) { unset(self::$rows[$k]); } } return 1; }
        public function value($c) { return isset(self::$rows[$this->k]) ? self::$rows[$this->k] : null; }
        public function count() { return isset(self::$rows[$this->k]) ? 1 : 0; }
        public function update($row) { self::$rows[$this->k] = $row["v"]; return 1; }
        public function insert($row) { self::$rows[$row["k"]] = $row["v"]; return true; }
    }');
}
if (!class_exists('WHMCS\Domain\TopLevel\ImportItem')) {
    eval('namespace WHMCS\Results; class ResultsList extends \ArrayObject {}');
    eval('namespace WHMCS\Domain\TopLevel; class ImportItem { public $v = [];
        public function __call($m, $a) { $this->v[lcfirst(substr($m, 3))] = $a[0]; return $this; } }');
}
require __DIR__ . '/../tinowhmcs.php';

use TinoWhmcs\TinoApi;
use WHMCS\Database\Capsule;

$fails = 0;
$t = function ($name, $cond, $detail = '') use (&$fails) {
    echo ($cond ? 'PASS' : 'FAIL') . " {$name}" . ($cond || $detail === '' ? '' : " -> {$detail}") . "\n";
    if (!$cond) {
        $fails++;
    }
};

// ---------------------------------------------------------------- pure: contact builder
$base = ['firstname' => 'Bình', 'lastname' => 'Trần', 'email' => 'client@example.com', 'phonenumber' => '0900000000',
    'address1' => '1 Client St', 'city' => 'Phường Client', 'state' => 'Tỉnh Client', 'postcode' => '', 'sld' => 'vidu', 'tld' => 'vn', 'regperiod' => 2,
    'ns1' => 'ns1.tino.vn', 'ns2' => 'ns2.tino.vn', 'ns3' => ''];
$ind = $base + ['additionalfields' => ['owner_type' => 'ind', 'owner_name' => 'Nguyễn Văn A', 'owner_gender' => 'Nữ', 'owner_birthday' => '17/01/1992',
    'owner_person_id' => '052092021953', 'owner_phone' => '0933000886', 'owner_email' => '', 'owner_address' => '42 Trần Phú',
    'owner_state' => 'Thành phố Hồ Chí Minh', 'owner_city' => 'Phường Bến Nghé']];
$c = tinowhmcs_contact($ind);
$t('ind: type/name/gender/birthday/nationalid', $c['type'] === 'ind' && $c['name'] === 'Nguyễn Văn A' && $c['gender'] === 'Female'
    && $c['birthday'] === '17/01/1992' && $c['nationalid'] === '052092021953', json_encode($c, JSON_UNESCAPED_UNICODE));
$t('ind: state/city from form, no ward key, country VN', $c['state'] === 'Thành phố Hồ Chí Minh' && $c['city'] === 'Phường Bến Nghé' && !array_key_exists('ward', $c) && $c['country'] === 'VN');
$t('ind: email falls back to client, postcode default', $c['email'] === 'client@example.com' && $c['postcode'] === '700000');
$t('ind: no org keys', !isset($c['taxid']) && !isset($c['householdsid']));
$t('ind: validates clean', tinowhmcs_validate($c) === [], json_encode(tinowhmcs_validate($c), JSON_UNESCAPED_UNICODE));

$bad = $ind;
$bad['additionalfields']['owner_birthday'] = '1992-01-17';
$bad['additionalfields']['owner_person_id'] = '';
$m = tinowhmcs_validate(tinowhmcs_contact($bad));
$t('ind: bad birthday + missing CCCD reported', count($m) === 2 && in_array('Số CCCD', $m, true), json_encode($m, JSON_UNESCAPED_UNICODE));

$org = $ind;
$org['additionalfields'] += ['congty_ten' => 'CÔNG TY TNHH ABC', 'congty_mst' => '0312345678', 'congty_hkd' => '', 'congty_chucvu' => 'Giám đốc'];
$org['additionalfields']['owner_type'] = 'org';
$c = tinowhmcs_contact($org);
$t('org: type/name/taxid/position, no ind keys', $c['type'] === 'org' && $c['name'] === 'CÔNG TY TNHH ABC' && $c['taxid'] === '0312345678'
    && $c['position'] === 'Giám đốc' && !isset($c['nationalid']) && !isset($c['birthday']), json_encode($c, JSON_UNESCAPED_UNICODE));
$org['additionalfields']['congty_ten'] = $org['additionalfields']['congty_mst'] = '';
$m = tinowhmcs_validate(tinowhmcs_contact($org));
$t('org: missing name + taxid reported', in_array('Tên chủ thể', $m, true) && in_array('Mã số thuế', $m, true), json_encode($m, JSON_UNESCAPED_UNICODE));
$t('legacy owner_type=1 means org', tinowhmcs_contact($base + ['additionalfields' => ['owner_type' => '1']])['type'] === 'org');
$t('nameservers: empty slots skipped', tinowhmcs_ns($base) === ['ns1.tino.vn', 'ns2.tino.vn']);
$t('domain name: tld with or without dot', tinowhmcs_domain($base) === 'vidu.vn' && tinowhmcs_domain(['sld' => 'a', 'tld' => '.com.vn']) === 'a.com.vn');
$r = tinowhmcs_order($base + ['additionalfields' => []], 'register');
$t('order: incomplete contact rejected before any API call', isset($r['error']) && strpos($r['error'], 'Thiếu') === 0, json_encode($r, JSON_UNESCAPED_UNICODE));

// ---------------------------------------------------------------- pure: API token auth
$threw = function ($p) {
    try {
        tinowhmcs_api($p);
        return false;
    } catch (Exception $e) {
        return strpos($e->getMessage(), 'tinopat_') !== false;
    }
};
$t('token: old email/password config and non-personal tokens rejected before any API call',
    $threw(['Username' => 'a@b.vn', 'Password' => 'x']) && $threw(['ApiToken' => '']) && $threw(['ApiToken' => 'tinoat_' . str_repeat('a', 43)])
    && !$threw(['ApiToken' => ' tinopat_' . str_repeat('a', 43) . ' ']));
$e = TinoApi::error(['error' => ['insufficient_scope']]);
$t('token: gateway codes explained, code kept, other errors unchanged', strpos($e, 'thiếu quyền') !== false && strpos($e, '(insufficient_scope)') !== false
    && strpos(TinoApi::error(['error' => ['ip_not_allowed']]), 'IP máy chủ WHMCS') === 0 && TinoApi::error(['error' => ['wrong', 'x']]) === 'wrong | x', $e);
Capsule::$rows = ['auth:abc' => '{"token":"jwt","refresh":"r"}', 'loc:states' => '{}'];
TinoApi::forgetLogins();
$t('token: forgetLogins drops stored login tokens, keeps location cache', array_keys(Capsule::$rows) === ['loc:states'], json_encode(Capsule::$rows));

// ---------------------------------------------------------------- pure: DNS normalise + diff
$n = function ($h, $ty, $a, $p = 0) { return tinowhmcs_dns_norm(['hostname' => $h, 'type' => $ty, 'address' => $a, 'priority' => $p], 'vidu.vn'); };
$t('dns: apex/FQDN/relative hostnames normalise, TXT unquoted, SPF->TXT, MX priority kept',
    $n('vidu.vn', 'A', '1.2.3.4')['hostname'] === '@' && $n('', 'A', '1.2.3.4')['hostname'] === '@'
    && $n('www.vidu.vn', 'CNAME', 'vidu.vn')['hostname'] === 'www' && $n('WWW', 'cname', 'vidu.vn') === ['hostname' => 'www', 'type' => 'CNAME', 'address' => 'vidu.vn', 'priority' => 0]
    && $n('@', 'SPF', '"v=spf1 -all"') === ['hostname' => '@', 'type' => 'TXT', 'address' => 'v=spf1 -all', 'priority' => 0]
    && $n('@', 'MX', 'mx.tino.vn', '10')['priority'] === 10 && $n('@', 'A', '1.1.1.1', '10')['priority'] === 0);
$existing = [1 => $n('@', 'A', '1.1.1.1'), 2 => $n('www', 'CNAME', 'vidu.vn'), 3 => $n('@', 'MX', 'mx.tino.vn', 10), 4 => $n('@', 'TXT', 'v=spf1 -all')];
$wanted = [$n('@', 'A', '1.1.1.1'), $n('www', 'CNAME', 'vidu.vn'), $n('@', 'MX', 'mx.tino.vn', 20), $n('@', 'TXT', 'v=spf1 -all'), $n('@', 'TXT', 'v=spf1 -all'), $n('api', 'A', '2.2.2.2')];
list($del, $add) = tinowhmcs_dns_diff($existing, $wanted);
$t('dns diff: changed MX priority = delete+create, duplicates collapsed, unchanged kept', $del === [3] && count($add) === 2
    && $add[0]['priority'] === 20 && $add[1]['hostname'] === 'api', json_encode([$del, $add]));
list($del, $add) = tinowhmcs_dns_diff($existing, []);
$t('dns diff: empty wanted deletes all managed records', $del === [1, 2, 3, 4] && $add === []);

// ---------------------------------------------------------------- pure: additionalfields.php built from cached lists
Capsule::$rows = [
    'loc:states' => json_encode(['t' => time(), 'data' => ['Tỉnh A', 'Tỉnh B,C']]),
    'loc:wards' => json_encode(['t' => time(), 'data' => ['Tỉnh A' => ['Xã 1', 'Xã 2'], 'Tỉnh B,C' => ['Xã 2', 'Phường 3']]]),
];
$additionaldomainfields = [];
include __DIR__ . '/../../../../resources/domains/additionalfields.php';
$byName = [];
foreach ($additionaldomainfields['.vn'] as $f) {
    $byName[$f['Name']] = $f;
}
$t('fields: .vn and .com.vn defined, owner_state dropdown from cache with escaped comma',
    isset($additionaldomainfields['.com.vn']) && $byName['owner_state']['Type'] === 'dropdown' && $byName['owner_state']['Options'] === 'Tỉnh A,Tỉnh B&comma;C',
    json_encode($byName['owner_state'], JSON_UNESCAPED_UNICODE));
$t('fields: owner_city dropdown holds every ward once', $byName['owner_city']['Options'] === 'Xã 1,Xã 2,Phường 3', $byName['owner_city']['Options']);
$t('fields: required set on owner_* only', !empty($byName['owner_name']['Required']) && empty($byName['congty_ten']['Required']));

// ---------------------------------------------------------------- live
$token = getenv('TINO_TOKEN');
if (!$token) {
    echo "SKIP live checks (set TINO_TOKEN)\n";
    exit($fails ? 1 : 0);
}
$params = ['ApiToken' => $token];
Capsule::$rows = [];

$states = TinoApi::states();
$wards = TinoApi::wards();
$t('live: 34 provinces from /location/VN/state-v2', count($states) === 34, count($states));
$t('live: ward map keys == province list', $states && !array_diff($states, array_keys($wards)) && !array_diff(array_keys($wards), $states),
    json_encode(array_diff($states, array_keys($wards)) + array_diff(array_keys($wards), $states), JSON_UNESCAPED_UNICODE));
$t('live: HCM has wards', !empty($wards['Thành phố Hồ Chí Minh']) && count($wards['Thành phố Hồ Chí Minh']) > 50, count(isset($wards['Thành phố Hồ Chí Minh']) ? $wards['Thành phố Hồ Chí Minh'] : []));
$t('live: location lists cached in store', isset(Capsule::$rows['loc:states'], Capsule::$rows['loc:wards']));

$r = tinowhmcs_api($params)->get('/domain');
$t('live: GET /v1/domain with the token', !empty($r['success']), json_encode($r));
$bad = tinowhmcs_api(['ApiToken' => 'tinopat_' . str_repeat('A', 43)])->get('/domain');
$t('live: unknown token -> invalid_token', strpos(TinoApi::error($bad), '(invalid_token)') !== false, json_encode($bad));

$lk = TinoApi::lookup('tino-whmcs-selfcheck-' . mt_rand(1000, 9999) . '.vn');
$t('live: lookup of a random .vn is available', !empty($lk['avaliable']), json_encode($lk));
$lk = TinoApi::lookup('tino.vn');
$t('live: lookup of tino.vn is taken', isset($lk['avaliable']) && !$lk['avaliable']);

$pricing = tinowhmcs_GetTldPricing($params);
$vn = null;
foreach ($pricing as $item) {
    if ($item->v['tld'] === '.vn') {
        $vn = $item->v;
    }
}
$t('live: GetTldPricing returns many TLDs incl. .vn in VND with 1-year prices', count($pricing) > 20 && $vn && $vn['currency'] === 'VND'
    && $vn['registrationPrice'] > 0 && $vn['renewalPrice'] > 0 && $vn['minYears'] === 1 && $vn['maxYears'] >= 1 && $vn['eppRequired'] === false,
    json_encode($vn));

$domains = isset($r['domains']) ? $r['domains'] : [];
if (!$domains) {
    echo "SKIP domain checks (account has no domains)\n";
    exit($fails ? 1 : 0);
}
list($sld, $tld) = explode('.', $domains[0]['name'], 2);
$p = $params + ['sld' => $sld, 'tld' => $tld];
$r = tinowhmcs_Sync($p);
$t('live: Sync returns expirydate/active', isset($r['expirydate'], $r['active']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['expirydate']), json_encode($r));
$r = tinowhmcs_GetNameservers($p);
$t('live: GetNameservers ns1..', !empty($r['ns1']) && !isset($r['error']), json_encode($r));
$r = tinowhmcs_GetRegistrarLock($p);
$t('live: GetRegistrarLock locked|unlocked', in_array($r, ['locked', 'unlocked'], true), json_encode($r));
$r = tinowhmcs_GetContactDetails($p);
$t('live: GetContactDetails has Registrant.Email', isset($r['Registrant']['Email']) && !isset($r['error']), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = tinowhmcs_GetEPPCode($p);
$t('live: GetEPPCode', isset($r['eppcode']) && !isset($r['error']), json_encode($r, JSON_UNESCAPED_UNICODE));
$r = tinowhmcs_TransferSync($params + ['sld' => 'khong-ton-tai-' . mt_rand(), 'tld' => 'vn']);
$t('live: TransferSync unknown domain => pending []', $r === [], json_encode($r));

$r = tinowhmcs_GetDNS($p);
$hasZone = !isset($r['error']);
$t('live: GetDNS lists records (or explains missing zone)', $hasZone ? count($r) > 0 && isset($r[0]['hostname'], $r[0]['type'], $r[0]['address']) : strpos($r['error'], 'zone DNS') !== false, json_encode($r, JSON_UNESCAPED_UNICODE));
if ($hasZone && getenv('TINO_LIVE_WRITE')) {
    $before = $r;
    $probe = ['hostname' => '_tinowhmcs-selfcheck', 'type' => 'TXT', 'address' => 'tinowhmcs selfcheck', 'priority' => ''];
    $w = tinowhmcs_SaveDNS($p + ['dnsrecords' => array_merge($before, [$probe])]);
    $mid = tinowhmcs_GetDNS($p);
    $found = array_filter($mid, function ($x) { return $x['hostname'] === '_tinowhmcs-selfcheck' && $x['type'] === 'TXT' && $x['address'] === 'tinowhmcs selfcheck'; });
    $t('live-write: SaveDNS adds the probe TXT and keeps the rest', !isset($w['error']) && count($found) === 1 && count($mid) === count($before) + 1, json_encode([$w, $mid], JSON_UNESCAPED_UNICODE));
    $w = tinowhmcs_SaveDNS($p + ['dnsrecords' => $before]);
    $after = tinowhmcs_GetDNS($p);
    $t('live-write: SaveDNS with the original list removes the probe and nothing else', !isset($w['error']) && $after == $before, json_encode([$w, $after], JSON_UNESCAPED_UNICODE));
} else {
    echo "SKIP live DNS write (set TINO_LIVE_WRITE=1 on a domain with a Tino DNS zone)\n";
}

echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
