<?php
/**
 * Tino Domain registrar module for WHMCS 8/9.
 * API: https://api.tino.vn (Bearer token, see lib/TinoApi.php).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/TinoApi.php';

use TinoWhmcs\TinoApi;
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
        'Description' => ['Type' => 'System', 'Value' => 'Đăng ký tên miền qua api.tino.vn. Dùng email/mật khẩu tài khoản Tino; module tự lấy và làm mới token.'],
        'Username' => ['FriendlyName' => 'Email tài khoản Tino', 'Type' => 'text', 'Size' => '40'],
        'Password' => ['FriendlyName' => 'Mật khẩu', 'Type' => 'password', 'Size' => '40'],
    ];
}

/** Called by WHMCS when the registrar config is saved: verify the credentials. */
function tinowhmcs_config_validate($params)
{
    try {
        $r = tinowhmcs_api($params)->get('/balance');
        if (!empty($r['error'])) {
            throw new Exception(TinoApi::error($r));
        }
    } catch (Exception $e) {
        throw new WHMCS\Exception\Module\InvalidConfiguration('Không đăng nhập được api.tino.vn: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------------ helpers

function tinowhmcs_api(array $p)
{
    return new TinoApi(trim($p['Username']), $p['Password']);
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
