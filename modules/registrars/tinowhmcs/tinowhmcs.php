<?php
/**
 * Tino Domain registrar module for WHMCS 8/9.
 * API: https://api.tino.vn/v1 with a personal API token (tinopat_…, see lib/TinoApi.php).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/TinoApi.php';

use TinoWhmcs\TinoApi;
use WHMCS\Database\Capsule;
use WHMCS\Domain\TopLevel\ImportItem;
use WHMCS\Domains\DomainLookup\ResultsList;
use WHMCS\Domains\DomainLookup\SearchResult;

function tinowhmcs_MetaData()
{
    return ['DisplayName' => 'Tino Domain', 'APIVersion' => '1.1'];
}

function tinowhmcs_getConfigArray()
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Tino Domain'],
        'Description' => ['Type' => 'System', 'Value' => 'Đăng ký tên miền qua api.tino.vn bằng token API cá nhân của tài khoản Tino.'],
        'ApiToken' => ['FriendlyName' => 'API Token', 'Type' => 'password', 'Size' => '60',
            'Description' => 'tinopat_… tạo ở Client Area Tino → Token API. Quyền: domains:read, domains:write, dns:read, dns:write; '
                . 'thêm domains:owner (bắt buộc giới hạn IP máy chủ WHMCS) để lấy mã EPP và sửa liên hệ.'],
    ];
}

/** Called by WHMCS when the registrar config is saved: verify the token, then drop the old email/password login. */
function tinowhmcs_config_validate($params)
{
    try {
        $r = tinowhmcs_api($params)->get('/domain');
        if (!empty($r['error'])) {
            throw new Exception(TinoApi::error($r));
        }
    } catch (Exception $e) {
        throw new WHMCS\Exception\Module\InvalidConfiguration('Không gọi được api.tino.vn: ' . $e->getMessage());
    }
    TinoApi::forgetLogins();
    Capsule::table('tblregistrars')->where('registrar', 'tinowhmcs')->whereIn('setting', ['Username', 'Password'])->delete();
}

// ------------------------------------------------------------------ helpers

function tinowhmcs_api(array $p)
{
    return new TinoApi(trim(isset($p['ApiToken']) ? $p['ApiToken'] : ''));
}

function tinowhmcs_domain(array $p)
{
    return $p['sld'] . '.' . ltrim($p['tld'], '.');
}

function tinowhmcs_ns(array $p)
{
    $ns = [];
    for ($i = 1; $i <= 5; $i++) {
        if (!empty($p["ns{$i}"])) {
            $ns[] = trim($p["ns{$i}"]);
        }
    }
    return $ns;
}

/**
 * Build the registrant contact the API expects from the WHMCS additional domain fields
 * (resources/domains/additionalfields.php), falling back to the client profile.
 */
function tinowhmcs_contact(array $p)
{
    $a = isset($p['additionalfields']) ? $p['additionalfields'] : [];
    $f = function ($k, $fallback = '') use ($a) {
        return isset($a[$k]) && trim($a[$k]) !== '' ? trim($a[$k]) : $fallback;
    };
    $org = in_array($f('owner_type'), ['org', '1'], true);

    $c = [
        'type' => $org ? 'org' : 'ind',
        'name' => $org ? $f('congty_ten') : $f('owner_name', trim($p['lastname'] . ' ' . $p['firstname'])),
        'phonenumber' => $f('owner_phone', $p['phonenumber']),
        'email' => $f('owner_email', $p['email']),
        'country' => 'VN',
        'state' => $f('owner_state', $p['state']),
        'city' => $f('owner_city', $p['city']), // VN 2-level addressing: city holds the Phường/Xã name, no ward key
        'address1' => $f('owner_address', $p['address1']),
        'address2' => '',
        'postcode' => !empty($p['postcode']) ? $p['postcode'] : '700000',
    ];
    if ($org) {
        $c += ['taxid' => $f('congty_mst'), 'householdsid' => $f('congty_hkd'), 'position' => $f('congty_chucvu')];
    } else {
        $c += [
            'nationalid' => $f('owner_person_id'),
            'gender' => in_array(mb_strtolower($f('owner_gender')), ['nữ', 'female'], true) ? 'Female' : 'Male',
            'birthday' => $f('owner_birthday'),
        ];
    }
    return $c;
}

/** @return string[] missing-field messages; empty when the contact is complete */
function tinowhmcs_validate(array $c)
{
    $labels = [
        'name' => 'Tên chủ thể', 'phonenumber' => 'Số điện thoại', 'email' => 'Email', 'state' => 'Tỉnh / Thành phố',
        'city' => 'Phường / Xã', 'address1' => 'Địa chỉ',
    ];
    $labels += $c['type'] === 'org'
        ? ['taxid' => 'Mã số thuế']
        : ['nationalid' => 'Số CCCD', 'birthday' => 'Ngày sinh'];
    $missing = [];
    foreach ($labels as $k => $label) {
        if (!isset($c[$k]) || $c[$k] === '') {
            $missing[] = $label;
        }
    }
    if ($c['type'] === 'ind' && $c['birthday'] !== '' && !preg_match('#^\d{2}/\d{2}/\d{4}$#', $c['birthday'])) {
        $missing[] = 'Ngày sinh phải theo dạng dd/mm/YYYY';
    }
    return $missing;
}

function tinowhmcs_order(array $p, $action)
{
    $contact = tinowhmcs_contact($p);
    if ($missing = tinowhmcs_validate($contact)) {
        return ['error' => 'Thiếu/sai thông tin chủ thể: ' . implode(', ', $missing)];
    }
    $domain = tinowhmcs_domain($p);
    if ($action === 'register') {
        $chk = TinoApi::lookup($domain);
        if (empty($chk['avaliable'])) {
            return ['error' => "Tên miền {$domain} không khả dụng để đăng ký (" . TinoApi::error($chk, isset($chk['status']) ? $chk['status'] : 'taken') . ')'];
        }
    }
    $body = [
        'name' => $domain,
        'years' => (int) $p['regperiod'],
        'action' => $action,
        'nameservers' => tinowhmcs_ns($p) ?: ['ns1.tino.vn', 'ns2.tino.vn'],
        'registrant' => $contact,
        'admin' => $contact,
        'tech' => $contact,
        'billing' => $contact,
    ];
    if ($action === 'transfer') {
        $body['epp'] = $p['eppcode'];
    }
    $r = tinowhmcs_api($p)->post('/domain/order', $body);
    if (!empty($r['error']) || (isset($r['success']) && !$r['success'])) {
        return ['error' => TinoApi::error($r)];
    }
    return ['success' => true];
}

/** Wrap a module function so any exception becomes ['error' => ...]. */
function tinowhmcs_try(callable $fn)
{
    try {
        return $fn();
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// ------------------------------------------------------------------ registrar functions

function tinowhmcs_RegisterDomain($params)
{
    return tinowhmcs_try(function () use ($params) {
        return tinowhmcs_order($params, 'register');
    });
}

function tinowhmcs_TransferDomain($params)
{
    return tinowhmcs_try(function () use ($params) {
        return tinowhmcs_order($params, 'transfer');
    });
}

function tinowhmcs_RenewDomain($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->post('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/renew', ['years' => (int) $params['regperiod']]);
        return !empty($r['error']) ? ['error' => TinoApi::error($r)] : ['success' => true];
    });
}

function tinowhmcs_CheckAvailability($params)
{
    $results = new ResultsList();
    foreach ((array) $params['tldsToInclude'] as $tld) {
        $r = TinoApi::lookup($params['sld'] . '.' . ltrim($tld, '.'));
        $sr = new SearchResult($params['sld'], $tld);
        if (!empty($r['error'])) {
            $sr->setStatus(SearchResult::STATUS_UNKNOWN);
        } else {
            $sr->setStatus(!empty($r['avaliable']) ? SearchResult::STATUS_NOT_REGISTERED : SearchResult::STATUS_REGISTERED);
        }
        $results->append($sr);
    }
    return $results;
}

/** Admin > Domain Pricing > Import: 1-year prices from /cart/domain/tlds (VND; -1 means not offered). */
function tinowhmcs_GetTldPricing($params)
{
    $results = new \WHMCS\Results\ResultsList();
    foreach (TinoApi::tlds() as $tld) {
        $years = [];
        $one = null;
        foreach (isset($tld['periods']) ? $tld['periods'] : [] as $period) {
            $years[] = (int) $period['period'];
            if ((int) $period['period'] === 1) {
                $one = $period;
            }
        }
        if (!$one || (float) $one['register'] < 0) {
            continue;
        }
        $item = (new ImportItem())
            ->setTld($tld['tld'])
            ->setCurrency('VND')
            ->setMinYears(min($years))
            ->setMaxYears(max($years))
            ->setRegistrationPrice((float) $one['register'])
            ->setRenewalPrice((float) $one['renew'])
            ->setEppRequired(substr($tld['tld'], -3) !== '.vn');
        if ((float) $one['transfer'] >= 0) {
            $item->setTransferPrice((float) $one['transfer']);
        }
        if ((float) $one['redemption'] > 0) {
            $item->setRedemptionFeePrice((float) $one['redemption'])->setRedemptionFeeDays(30);
        }
        $results[] = $item;
    }
    return $results;
}

function tinowhmcs_Sync($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->get('/domain/' . $api->domainId(tinowhmcs_domain($params)));
        if (empty($r['details'])) {
            return ['error' => TinoApi::error($r)];
        }
        $d = $r['details'];
        return [
            'expirydate' => $d['expires'],
            'active' => $d['status'] === 'Active',
            'expired' => $d['status'] === 'Expired',
        ];
    });
}

function tinowhmcs_TransferSync($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $d = $api->domainByName(tinowhmcs_domain($params));
        if (!$d) {
            return []; // still pending at Tino
        }
        return $d['status'] === 'Active' ? ['completed' => true, 'expirydate' => $d['expires']] : [];
    });
}

function tinowhmcs_GetNameservers($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->get('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/ns');
        if (!empty($r['error'])) {
            return ['error' => TinoApi::error($r)];
        }
        $out = [];
        foreach (array_values((array) $r['nameservers']) as $i => $ns) {
            $out['ns' . ($i + 1)] = $ns;
        }
        return $out;
    });
}

function tinowhmcs_SaveNameservers($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->put('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/ns', ['nameservers' => tinowhmcs_ns($params)]);
        return !empty($r['error']) ? ['error' => TinoApi::error($r)] : ['success' => true];
    });
}

function tinowhmcs_GetRegistrarLock($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->get('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/reglock');
        if (!empty($r['error'])) {
            return ['error' => TinoApi::error($r)];
        }
        return !empty($r['registrar_lock']) ? 'locked' : 'unlocked';
    });
}

function tinowhmcs_SaveRegistrarLock($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->put('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/reglock', ['switch' => $params['lockenabled'] === 'locked']);
        return !empty($r['error']) ? ['error' => TinoApi::error($r)] : ['success' => 'success'];
    });
}

function tinowhmcs_IDProtectToggle($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->put('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/idprotection', ['switch' => (bool) $params['protectenable']]);
        return !empty($r['error']) ? ['error' => TinoApi::error($r)] : ['success' => 'success'];
    });
}

function tinowhmcs_GetEPPCode($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->get('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/epp');
        return !empty($r['error']) ? ['error' => TinoApi::error($r)] : ['eppcode' => $r['epp_code']];
    });
}

/** WHMCS contact label => API contact key */
function tinowhmcs_contact_map()
{
    return [
        'Email' => 'email', 'Phone' => 'phonenumber', 'Address' => 'address1', 'Address 2' => 'address2',
        'City' => 'city', 'State' => 'state', 'Postcode' => 'postcode', 'Country' => 'country',
    ];
}

function tinowhmcs_GetContactDetails($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $r = $api->get('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/contact');
        if (!empty($r['error'])) {
            return ['error' => TinoApi::error($r)];
        }
        $out = [];
        foreach (['Registrant' => 'registrant', 'Admin' => 'admin', 'Technical' => 'tech', 'Billing' => 'billing'] as $label => $type) {
            foreach (tinowhmcs_contact_map() as $field => $key) {
                $out[$label][$field] = isset($r['contact_info'][$type][$key]) ? $r['contact_info'][$type][$key] : '';
            }
        }
        return $out;
    });
}

function tinowhmcs_SaveContactDetails($params)
{
    return tinowhmcs_try(function () use ($params) {
        $info = [];
        foreach (['Registrant' => 'registrant', 'Admin' => 'admin', 'Technical' => 'tech', 'Billing' => 'billing'] as $label => $type) {
            foreach (tinowhmcs_contact_map() as $field => $key) {
                $info[$type][$key] = isset($params['contactdetails'][$label][$field]) ? $params['contactdetails'][$label][$field] : '';
            }
        }
        $api = tinowhmcs_api($params);
        $r = $api->put('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/contact', ['contact_info' => $info]);
        return !empty($r['error']) ? ['error' => TinoApi::error($r)] : ['success' => true];
    });
}

// ------------------------------------------------------------------ DNS (Tino DNS zone of the domain)

/** @return string zone path "/service/{sid}/dns/{zid}"; throws when the domain has no Tino DNS zone */
function tinowhmcs_zone(TinoApi $api, $domain)
{
    $r = $api->get('/domain/' . $api->domainId($domain));
    if (empty($r['details']['dns']['service_id']) || empty($r['details']['dns']['zone_id'])) {
        throw new Exception("Tên miền {$domain} không có zone DNS tại Tino (cần trỏ về ns1/ns2.tino.vn). " . (!empty($r['error']) ? TinoApi::error($r) : ''));
    }
    return '/service/' . $r['details']['dns']['service_id'] . '/dns/' . $r['details']['dns']['zone_id'];
}

function tinowhmcs_dns_types()
{
    return ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];
}

/** Normalise one record to ['hostname' (relative, '@' = apex), 'type', 'address' (TXT unquoted), 'priority'] */
function tinowhmcs_dns_norm(array $rec, $domain)
{
    $host = strtolower(trim($rec['hostname']));
    $host = preg_replace('/\.?' . preg_quote($domain, '/') . '\.?$/', '', $host);
    $type = strtoupper(trim($rec['type'])) === 'SPF' ? 'TXT' : strtoupper(trim($rec['type']));
    $addr = trim($rec['address']);
    if ($type === 'TXT') {
        $addr = trim($addr, '"');
    }
    return [
        'hostname' => $host === '' ? '@' : $host,
        'type' => $type,
        'address' => $addr,
        'priority' => $type === 'MX' ? (int) $rec['priority'] : 0,
    ];
}

function tinowhmcs_dns_key(array $rec)
{
    return implode('|', [$rec['hostname'], $rec['type'], $rec['address'], $rec['priority']]);
}

/** @return array [record id => normalised record] of the zone's managed records */
function tinowhmcs_dns_records(TinoApi $api, $zone, $domain)
{
    $r = $api->get($zone);
    if (!empty($r['error'])) {
        throw new Exception(TinoApi::error($r));
    }
    $out = [];
    foreach (isset($r['records']) ? (array) $r['records'] : [] as $rec) {
        if (in_array($rec['type'], tinowhmcs_dns_types(), true)) {
            $out[$rec['id']] = tinowhmcs_dns_norm(['hostname' => $rec['name'], 'type' => $rec['type'], 'address' => $rec['content'], 'priority' => $rec['priority']], $domain);
        }
    }
    return $out;
}

/** Pure diff: which existing ids to delete and which wanted records to create. */
function tinowhmcs_dns_diff(array $existing, array $wanted)
{
    $have = [];
    foreach ($existing as $id => $rec) {
        $have[tinowhmcs_dns_key($rec)] = $id;
    }
    $keep = [];
    $create = [];
    foreach ($wanted as $rec) {
        $key = tinowhmcs_dns_key($rec);
        if (isset($have[$key])) {
            $keep[$key] = true;
        } elseif (!isset($create[$key])) {
            $create[$key] = $rec;
        }
    }
    $delete = [];
    foreach ($have as $key => $id) {
        if (!isset($keep[$key])) {
            $delete[] = $id;
        }
    }
    return [$delete, array_values($create)];
}

function tinowhmcs_GetDNS($params)
{
    return tinowhmcs_try(function () use ($params) {
        $api = tinowhmcs_api($params);
        $domain = tinowhmcs_domain($params);
        $out = [];
        foreach (tinowhmcs_dns_records($api, tinowhmcs_zone($api, $domain), $domain) as $rec) {
            $out[] = ['hostname' => $rec['hostname'], 'type' => $rec['type'], 'address' => $rec['address'], 'priority' => $rec['type'] === 'MX' ? $rec['priority'] : ''];
        }
        return $out;
    });
}

function tinowhmcs_SaveDNS($params)
{
    return tinowhmcs_try(function () use ($params) {
        $domain = tinowhmcs_domain($params);
        $wanted = [];
        foreach (isset($params['dnsrecords']) ? (array) $params['dnsrecords'] : [] as $rec) {
            if (trim($rec['address']) === '') {
                continue; // blank row in the WHMCS form
            }
            $rec = tinowhmcs_dns_norm($rec, $domain);
            if (!in_array($rec['type'], tinowhmcs_dns_types(), true)) {
                return ['error' => "Loại bản ghi {$rec['type']} không được Tino DNS hỗ trợ (chỉ A, AAAA, CNAME, MX, TXT/SPF)."];
            }
            $wanted[] = $rec;
        }
        $api = tinowhmcs_api($params);
        $zone = tinowhmcs_zone($api, $domain);
        list($delete, $create) = tinowhmcs_dns_diff(tinowhmcs_dns_records($api, $zone, $domain), $wanted);
        foreach ($delete as $id) {
            $r = $api->call('DELETE', $zone . '/records/' . $id);
            if (!empty($r['error'])) {
                return ['error' => 'Xoá bản ghi thất bại: ' . TinoApi::error($r)];
            }
        }
        foreach ($create as $rec) {
            $r = $api->post($zone . '/records', [
                'name' => $rec['hostname'] === '@' ? $domain : $rec['hostname'] . '.' . $domain,
                'type' => $rec['type'],
                'content' => $rec['address'],
                'priority' => $rec['priority'],
                'ttl' => 3600,
            ]);
            if (!empty($r['error'])) {
                return ['error' => "Thêm bản ghi {$rec['type']} {$rec['hostname']} thất bại: " . TinoApi::error($r)];
            }
        }
        return ['success' => true];
    });
}

function tinowhmcs_nsreg(array $params, array $body)
{
    return tinowhmcs_try(function () use ($params, $body) {
        $api = tinowhmcs_api($params);
        $r = $api->post('/domain/' . $api->domainId(tinowhmcs_domain($params)) . '/reg', $body);
        return !empty($r['error']) ? ['error' => TinoApi::error($r)] : ['success' => 'success'];
    });
}

function tinowhmcs_RegisterNameserver($params)
{
    return tinowhmcs_nsreg($params, ['action' => 'registerNameServer', 'nameserver' => $params['nameserver'], 'ip' => $params['ipaddress']]);
}

function tinowhmcs_ModifyNameserver($params)
{
    return tinowhmcs_nsreg($params, ['action' => 'modifyNameServer', 'nameserver' => $params['nameserver'], 'oldip' => $params['currentipaddress'], 'newip' => $params['newipaddress']]);
}

function tinowhmcs_DeleteNameserver($params)
{
    return tinowhmcs_nsreg($params, ['action' => 'deleteNameServer', 'nameserver' => $params['nameserver']]);
}
