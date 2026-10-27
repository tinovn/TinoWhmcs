<?php
/**
 * Tino API client (https://api.tino.vn) for the WHMCS registrar module.
 *
 * Auth: a personal API token (tinopat_…) the customer makes in the Tino Client Area (Token API).
 * Account calls go through the token gateway https://api.tino.vn/v1/<route> with
 * "Authorization: Bearer <token>": only routes of the token's scopes (else 403 insufficient_scope),
 * only from its allowed IPs when set (else 403 ip_not_allowed). Revoked/expired: 401 invalid_token.
 * Public calls (lookup, TLD prices, VN locations) stay at the API root without a token.
 *
 * The VN location lists are cached in the WHMCS DB table `mod_tinowhmcs`.
 */

namespace TinoWhmcs;

use WHMCS\Database\Capsule;

class TinoApi
{
    const BASE = 'https://api.tino.vn';
    const GATEWAY = '/v1';
    const TABLE = 'mod_tinowhmcs';
    const LOCATION_TTL = 86400;

    private static $tableReady = false;

    /** Gateway error codes => what the WHMCS admin should fix. */
    const HINTS = [
        'invalid_token' => 'API Token sai, đã thu hồi hoặc hết hạn',
        'insufficient_scope' => 'API Token thiếu quyền cho thao tác này',
        'ip_not_allowed' => 'IP máy chủ WHMCS không có trong danh sách IP của API Token',
    ];

    private $token;

    public function __construct($token)
    {
        if (strpos($token, 'tinopat_') !== 0) {
            throw new \Exception('Tino module: API Token phải là token cá nhân tinopat_… tạo ở Client Area Tino (Token API).');
        }
        $this->token = $token;
    }

    // ------------------------------------------------------------ requests

    public function get($path, array $query = [])
    {
        return $this->call('GET', $path, $query);
    }

    public function post($path, array $body = [])
    {
        return $this->call('POST', $path, $body);
    }

    public function put($path, array $body = [])
    {
        return $this->call('PUT', $path, $body);
    }

    /** @return array|null domain record from GET /domain/name/{name}, null when not found */
    public function domainByName($name)
    {
        $r = $this->get('/domain/name/' . rawurlencode($name));
        foreach (isset($r['domains']) ? (array) $r['domains'] : [] as $d) {
            if (strcasecmp($d['name'], $name) === 0) {
                return $d;
            }
        }
        return null;
    }

    /** @return string domain id, throws when the account does not own the domain */
    public function domainId($name)
    {
        $d = $this->domainByName($name);
        if (!$d) {
            throw new \Exception("Không tìm thấy tên miền {$name} trong tài khoản Tino.");
        }
        return $d['id'];
    }

    /** Public endpoint, no auth. */
    public static function lookup($name)
    {
        return self::http('POST', '/domain/lookup', ['name' => $name], null);
    }

    /** Public endpoint, no auth: TLD list with per-period prices (VND). */
    public static function tlds()
    {
        $r = self::http('GET', '/cart/domain/tlds', [], null);
        return isset($r['tlds']) && is_array($r['tlds']) ? $r['tlds'] : [];
    }

    /** @return string error text from an API response */
    public static function error($resp, $fallback = 'Tino API: phản hồi không hợp lệ')
    {
        if (!empty($resp['error'])) {
            return implode(' | ', array_map(function ($e) {
                return isset(self::HINTS[$e]) ? self::HINTS[$e] . " ({$e})" : $e;
            }, (array) $resp['error']));
        }
        return $fallback;
    }

    // ------------------------------------------------------------ VN locations (public, cached)

    /** @return string[] province names, e.g. "Thành phố Hồ Chí Minh" */
    public static function states()
    {
        return self::cached('states', function () {
            $rows = self::http('GET', '/location/VN/state-v2', [], null);
            $out = [];
            foreach (isset($rows[0]) ? $rows : [] as $row) {
                if (!empty($row['name'])) {
                    $out[] = $row['name'];
                }
            }
            return $out;
        });
    }

    /** @return array province name => list of ward names ("Phường ...", "Xã ...") */
    public static function wards()
    {
        return self::cached('wards', function () {
            $rows = self::http('GET', '/location/citys', [], null);
            $out = [];
            foreach (is_array($rows) ? $rows : [] as $state => $list) {
                if (is_array($list) && $list) {
                    $out[$state] = array_values($list);
                }
            }
            return $out;
        });
    }

    private static function cached($name, callable $fetch)
    {
        $key = 'loc:' . $name;
        $c = static::loadStore($key);
        if ($c && isset($c['t'], $c['data']) && $c['t'] > time() - self::LOCATION_TTL) {
            return $c['data'];
        }
        $data = $fetch();
        if (!$data && $c) {
            $data = $c['data']; // API down: keep stale list, retry after TTL
        }
        if ($data) {
            static::saveStore($key, ['t' => time(), 'data' => $data]);
        }
        return $data ?: [];
    }

    // ------------------------------------------------------------ auth

    public function call($method, $path, array $data = [])
    {
        return self::http($method, self::GATEWAY . $path, $data, $this->token);
    }

    /** Drops the email/password login tokens (auth:*) stored by module versions before the API token. */
    public static function forgetLogins()
    {
        self::ensureTable();
        Capsule::table(self::TABLE)->where('k', 'like', 'auth:%')->delete();
    }

    // ------------------------------------------------------------ transport

    private static function http($method, $path, array $data, $bearer)
    {
        $url = self::BASE . $path;
        if ($method === 'GET' && $data) {
            $url .= '?' . http_build_query($data);
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $bearer ? 60 : 20,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data ?: new \stdClass()));
        }
        $body = curl_exec($ch);
        $err = curl_error($ch);

        $json = $body === false ? ['error' => ['curl: ' . $err]] : json_decode($body, true);
        if (!is_array($json)) {
            $json = ['error' => ['Tino API: phản hồi không phải JSON']];
        }
        if (function_exists('logModuleCall')) {
            logModuleCall('tinowhmcs', $method . ' ' . $path, $data, $body, $json, $bearer ? [$bearer] : []);
        }
        return $json;
    }

    // ------------------------------------------------------------ storage (WHMCS DB)

    protected static function loadStore($k)
    {
        self::ensureTable();
        $v = Capsule::table(self::TABLE)->where('k', $k)->value('v');
        return $v ? json_decode($v, true) : null;
    }

    protected static function saveStore($k, $v)
    {
        self::ensureTable();
        $row = ['v' => json_encode($v), 'updated_at' => time()];
        if (Capsule::table(self::TABLE)->where('k', $k)->count() > 0) {
            Capsule::table(self::TABLE)->where('k', $k)->update($row);
        } else {
            Capsule::table(self::TABLE)->insert($row + ['k' => $k]);
        }
    }

    private static function ensureTable()
    {
        if (self::$tableReady) {
            return;
        }
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($t) {
                $t->string('k', 64)->primary();
                $t->longText('v');
                $t->integer('updated_at');
            });
        }
        self::$tableReady = true;
    }
}
