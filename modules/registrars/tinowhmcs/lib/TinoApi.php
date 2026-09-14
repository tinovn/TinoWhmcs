<?php
/**
 * Tino API client (https://api.tino.vn) for the WHMCS registrar module.
 *
 * Auth flow: POST /login (username/password) -> {token, refresh}. Every call sends
 * "Authorization: Bearer <token>". When the API answers invalid_token/token_expired/
 * unauthorized, the client refreshes via POST /token {refresh_token}; if that fails it
 * logs in again. Refresh rotates both tokens, so both are persisted after each refresh.
 *
 * Tokens and the VN location lists are stored in the WHMCS DB table `mod_tinowhmcs`.
 */

namespace TinoWhmcs;

use WHMCS\Database\Capsule;

class TinoApi
{
    const BASE = 'https://api.tino.vn';
    const TABLE = 'mod_tinowhmcs';
    const LOCATION_TTL = 86400;

    private static $tableReady = false;

    private $username;
    private $password;
    private $key;
    /** @var array|null ['token' => .., 'refresh' => .., 'exp' => ..] */
    private $tokens = null;

    public function __construct($username, $password)
    {
        if ($username === '' || $password === '') {
            throw new \Exception('Tino module: chưa cấu hình Username/Password.');
        }
        $this->username = $username;
        $this->password = $password;
        $this->key = 'auth:' . md5($username);
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
            return implode(' | ', (array) $resp['error']);
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
        $r = self::http($method, $path, $data, $this->token());
        if (self::isAuthError($r)) {
            $this->tokens['exp'] = 0; // forces refresh, then login, in token()
            $r = self::http($method, $path, $data, $this->token());
        }
        return $r;
    }

    private function token()
    {
        if ($this->tokens === null) {
            $this->tokens = static::loadStore($this->key) ?: [];
        }
        if (!empty($this->tokens['token']) && !empty($this->tokens['exp']) && $this->tokens['exp'] > time() + 60) {
            return $this->tokens['token'];
        }
        if (!empty($this->tokens['refresh']) && $this->refresh()) {
            return $this->tokens['token'];
        }
        $this->login();
        return $this->tokens['token'];
    }

    private function login()
    {
        $r = self::http('POST', '/login', ['username' => $this->username, 'password' => $this->password], null);
        if (empty($r['token'])) {
            throw new \Exception('Tino API login thất bại: ' . self::error($r, 'wronglogin'));
        }
        $this->store($r['token'], isset($r['refresh']) ? $r['refresh'] : '');
    }

    private function refresh()
    {
        $r = self::http('POST', '/token', ['refresh_token' => $this->tokens['refresh']], null);
        if (empty($r['token'])) {
            return false;
        }
        $this->store($r['token'], !empty($r['refresh']) ? $r['refresh'] : $this->tokens['refresh']);
        return true;
    }

    private function store($token, $refresh)
    {
        $this->tokens = ['token' => $token, 'refresh' => $refresh, 'exp' => self::jwtExp($token)];
        static::saveStore($this->key, $this->tokens);
    }

    private static function jwtExp($jwt)
    {
        $parts = explode('.', $jwt);
        $claims = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        return !empty($claims['exp']) ? (int) $claims['exp'] : time() + 3600;
    }

    private static function isAuthError($r)
    {
        $errors = isset($r['error']) ? (array) $r['error'] : [];
        return (bool) array_intersect($errors, ['invalid_token', 'token_expired', 'unauthorized']);
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
            logModuleCall('tinowhmcs', $method . ' ' . $path, $data, $body, $json,
                array_filter([$bearer, isset($data['password']) ? $data['password'] : null, isset($data['refresh_token']) ? $data['refresh_token'] : null]));
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
