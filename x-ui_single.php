<?php
require_once 'config.php';
require_once 'request.php';
ini_set('error_log', 'error_log');

function xuiEnsurePanelSchema()
{
    global $pdo;
    static $ensured = false;
    if ($ensured || !isset($pdo)) {
        return;
    }
    $ensured = true;
    $columns = [
        'xui_version' => "VARCHAR(20) NULL DEFAULT 'legacy'",
        'xui_auth_mode' => "VARCHAR(20) NULL DEFAULT 'session'",
        'xui_api_token' => 'TEXT NULL',
    ];
    foreach ($columns as $column => $definition) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['marzban_panel', $column]);
        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE marzban_panel ADD COLUMN {$column} {$definition}");
        }
    }
}

function xuiIsV3Panel($panel)
{
    $version = strtolower(trim((string)($panel['xui_version'] ?? 'legacy')));
    return in_array($version, ['3', '3.x', 'v3', 'modern'], true);
}

function xuiUsesToken($panel)
{
    return xuiIsV3Panel($panel)
        && strtolower((string)($panel['xui_auth_mode'] ?? 'session')) === 'token'
        && trim((string)($panel['xui_api_token'] ?? '')) !== '';
}

function xuiNormalizeInboundIds($inboundIds)
{
    if (is_string($inboundIds)) {
        $decoded = json_decode($inboundIds, true);
        $inboundIds = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', $inboundIds);
    }
    if (!is_array($inboundIds)) {
        $inboundIds = [$inboundIds];
    }
    $normalized = [];
    foreach ($inboundIds as $inboundId) {
        if (is_numeric($inboundId) && (int)$inboundId > 0) {
            $normalized[] = (int)$inboundId;
        }
    }
    return array_values(array_unique($normalized));
}

function xuiNormalizeV3Client($client)
{
    if (!is_array($client)) {
        return $client;
    }
    $traffic = is_array($client['traffic'] ?? null) ? $client['traffic'] : [];
    $client['total'] = (int)($client['totalGB'] ?? $traffic['total'] ?? 0);
    $client['up'] = (int)($traffic['up'] ?? $client['up'] ?? 0);
    $client['down'] = (int)($traffic['down'] ?? $client['down'] ?? 0);
    $client['lastOnline'] = (int)($traffic['lastOnline'] ?? $client['lastOnline'] ?? 0);
    $uuid = (string)($client['uuid'] ?? '');
    if ($uuid === '' && isset($client['id']) && is_string($client['id']) && preg_match('/^[0-9a-f-]{16,}$/i', $client['id'])) {
        $uuid = $client['id'];
    }
    $client['uuid'] = $uuid;
    $client['inboundId'] = (int)(xuiNormalizeInboundIds($client['inboundIds'] ?? [])[0] ?? 0);
    if (isset($traffic['enable']) && !isset($client['enable'])) {
        $client['enable'] = (bool)$traffic['enable'];
    }
    return $client;
}

function xuiBuildV3ClientPayload($email, $total, $expiryTime, $uuid, $flow, $subId, $note = '')
{
    return [
        'email' => (string)$email,
        'id' => (string)$uuid,
        'flow' => (string)$flow,
        'totalGB' => (int)$total,
        'expiryTime' => (int)$expiryTime,
        'enable' => true,
        'tgId' => 0,
        'limitIp' => 0,
        'limitHwid' => 0,
        'subId' => (string)$subId,
        'reset' => 0,
        'comment' => (string)$note,
    ];
}

function xuiFetchCsrfToken($panel)
{
    if (!$panel || !xuiIsV3Panel($panel)) {
        return ['success' => false, 'msg' => 'CSRF token is only required for Sanaei v3'];
    }
    $curl = curl_init();
    $options = [
        CURLOPT_URL => rtrim($panel['url_panel'], '/') . '/csrf-token',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_COOKIEJAR => 'cookie.txt',
    ];
    if (is_file('cookie.txt')) {
        $options[CURLOPT_COOKIEFILE] = 'cookie.txt';
    }
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    if ($response === false) {
        $error = curl_error($curl);
        curl_close($curl);
        return ['success' => false, 'msg' => $error];
    }
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $decoded = json_decode((string)$response, true);
    if ($status !== 200 || !is_array($decoded) || empty($decoded['success']) || !is_string($decoded['obj'] ?? null)) {
        return [
            'success' => false,
            'msg' => is_array($decoded) ? ($decoded['msg'] ?? "HTTP {$status}") : "HTTP {$status}",
        ];
    }
    return ['success' => true, 'token' => $decoded['obj']];
}

function panel_login_cookie($code_panel)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    $isV3 = xuiIsV3Panel($panel);
    $csrfToken = null;
    if ($isV3) {
        if (is_file('cookie.txt')) {
            @unlink('cookie.txt');
        }
        $csrf = xuiFetchCsrfToken($panel);
        if (empty($csrf['success'])) {
            return json_encode(['success' => false, 'msg' => $csrf['msg'] ?? 'Unable to get CSRF token']);
        }
        $csrfToken = $csrf['token'];
    }
    $postFields = $isV3
        ? json_encode([
            'username' => (string)$panel['username_panel'],
            'password' => (string)$panel['password_panel'],
        ], JSON_UNESCAPED_UNICODE)
        : http_build_query([
            'username' => (string)$panel['username_panel'],
            'password' => (string)$panel['password_panel'],
        ]);
    $curl = curl_init();
    $headers = $isV3
        ? ['Accept: application/json', 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrfToken]
        : ['Accept: application/json'];
    $options = array(
        CURLOPT_URL => rtrim($panel['url_panel'], '/') . '/login',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT_MS => 4000,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => 'cookie.txt',
    );
    if ($isV3) {
        $options[CURLOPT_COOKIEFILE] = 'cookie.txt';
    }
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    if (curl_error($curl)) {
        return json_encode(array(
            'success' => false,
            'msg' => curl_error($curl)
        ));
    }
    curl_close($curl);
    return $response;
}

function login($code_panel, $verify = true)
{
    $panel = select("marzban_panel", "*", "code_panel", $code_panel, "select");
    if (!$panel) {
        return ['success' => false, 'msg' => 'Panel not found'];
    }
    if (xuiUsesToken($panel)) {
        $req = new CurlRequest(rtrim($panel['url_panel'], '/') . '/panel/api/inbounds/options');
        $req->setHeaders(['Accept: application/json', 'Content-Type: application/json']);
        $req->setBearerToken(trim((string)$panel['xui_api_token']));
        $response = $req->get();
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            return ['success' => false, 'msg' => $response['error'] ?? ('HTTP ' . ($response['status'] ?? 0))];
        }
        return $decoded;
    }
    if ($panel['datelogin'] != null && $verify) {
        $date = json_decode($panel['datelogin'], true);
        if (isset($date['time'])) {
            $timecurrent = time();
            $start_date = time() - strtotime($date['time']);
            if ($start_date <= 3000 && isset($date['access_token'])) {
                file_put_contents('cookie.txt', $date['access_token']);
                return ['success' => true, 'msg' => 'Cached session'];
            }
        }
    }
    $response = panel_login_cookie($panel['code_panel']);
    $cookieContents = is_file('cookie.txt') ? file_get_contents('cookie.txt') : '';
    $time = date('Y/m/d H:i:s');
    $data = json_encode(array(
        'time' => $time,
        'access_token' => $cookieContents
    ));
    update("marzban_panel", "datelogin", $data, 'name_panel', $panel['name_panel']);
    if (!is_string($response))
        return array('success' => false);
    return json_decode($response, true);
}

function xuiPanelRequest($panel, $method, $path, $payload = null)
{
    if (!$panel) {
        return ['status' => 404, 'body' => json_encode(['success' => false, 'msg' => 'Panel not found'])];
    }
    $method = strtolower((string)$method);
    $req = new CurlRequest(rtrim($panel['url_panel'], '/') . '/' . ltrim($path, '/'));
    $req->setHeaders(['Accept: application/json', 'Content-Type: application/json']);
    if (xuiUsesToken($panel)) {
        $req->setBearerToken(trim((string)$panel['xui_api_token']));
    } else {
        $loginResult = login($panel['code_panel']);
        if (is_array($loginResult) && isset($loginResult['success']) && !$loginResult['success']) {
            return ['status' => 401, 'body' => json_encode($loginResult, JSON_UNESCAPED_UNICODE)];
        }
        $req->setCookie('cookie.txt');
        if (xuiIsV3Panel($panel) && !in_array($method, ['get', 'head', 'options'], true)) {
            $csrf = xuiFetchCsrfToken($panel);
            if (empty($csrf['success'])) {
                return [
                    'status' => 403,
                    'body' => json_encode(['success' => false, 'msg' => $csrf['msg'] ?? 'Unable to get CSRF token'], JSON_UNESCAPED_UNICODE),
                ];
            }
            $req->setHeaders(['X-CSRF-Token: ' . $csrf['token']]);
        }
    }
    if ($method === 'get') {
        $response = $req->get();
    } elseif ($method === 'delete') {
        $response = $req->delete($payload);
    } else {
        $response = $req->post($payload ?? '');
    }
    if (!xuiUsesToken($panel) && is_file('cookie.txt')) {
        @unlink('cookie.txt');
    }
    return $response;
}

function get_clinets($username, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    $path = xuiIsV3Panel($marzban_list_get)
        ? '/panel/api/clients/get/' . rawurlencode($username)
        : '/panel/api/inbounds/getClientTraffics/' . rawurlencode($username);
    $response = xuiPanelRequest($marzban_list_get, 'get', $path);

    if (isset($response['body'])) {
        $decodedBody = json_decode($response['body'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
            if (isset($decodedBody['success']) && $decodedBody['success'] === false) {
                $response['error'] = $decodedBody['msg'] ?? 'Unknown panel error';
            } elseif (xuiIsV3Panel($marzban_list_get) && isset($decodedBody['obj'])) {
                $decodedBody['obj'] = xuiNormalizeV3Client($decodedBody['obj']);
                $response['body'] = json_encode($decodedBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }

    if (!empty($response['error'])) {
        error_log(json_encode($response));
    }

    return $response;
}

function addClient($namepanel, $usernameac, $Expire, $Total, $Uuid, $Flow, $subid, $inboundid, $name_product, $note = "")
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if ($name_product == "usertest") {
        if ($marzban_list_get['on_hold_test'] == "1") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    } else {
        if ($marzban_list_get['conecton'] == "onconecton") {
            if ($Expire == 0) {
                $timeservice = 0;
            } else {
                $timelast = $Expire - time();
                $timeservice = -intval(($timelast / 86400) * 86400000);
            }
        } else {
            $timeservice = $Expire * 1000;
        }
    }
    $config = array(
        "id" => intval($inboundid),
        'settings' => json_encode(array(
            'clients' => array(
                array(
                    "id" => $Uuid,
                    "flow" => $Flow,
                    "email" => $usernameac,
                    "totalGB" => $Total,
                    "expiryTime" => $timeservice,
                    "enable" => true,
                    "tgId" => "",
                    "subId" => $subid,
                    "reset" => 0,
                    "comment" => $note
                )
            ),
            'decryption' => 'none',
            'fallbacks' => array(),
        ))
    );
    if (!isset($usernameac))
        return array(
            'status' => 500,
            'msg' => 'username is null'
        );
    if (xuiIsV3Panel($marzban_list_get)) {
        $inboundIds = xuiNormalizeInboundIds($inboundid);
        if (!$inboundIds) {
            return ['status' => 422, 'body' => json_encode(['success' => false, 'msg' => 'Inbound ID is invalid'])];
        }
        $payload = [
            'client' => xuiBuildV3ClientPayload($usernameac, $Total, $timeservice, $Uuid, $Flow, $subid, $note),
            'inboundIds' => $inboundIds,
        ];
        return xuiPanelRequest(
            $marzban_list_get,
            'post',
            '/panel/api/clients/add',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
    return xuiPanelRequest(
        $marzban_list_get,
        'post',
        '/panel/api/inbounds/addClient',
        json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function updateClient($namepanel, $uuid, array $config)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (!xuiIsV3Panel($marzban_list_get)) {
        return xuiPanelRequest(
            $marzban_list_get,
            'post',
            '/panel/api/inbounds/updateClient/' . rawurlencode($uuid),
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    $settings = is_array($config['settings'] ?? null)
        ? $config['settings']
        : json_decode((string)($config['settings'] ?? ''), true);
    $changes = is_array($settings['clients'][0] ?? null) ? $settings['clients'][0] : [];
    $email = (string)($changes['email'] ?? '');
    if ($email === '') {
        return ['status' => 422, 'body' => json_encode(['success' => false, 'msg' => 'Client email is required'])];
    }
    $currentResponse = get_clinets($email, $namepanel);
    $currentBody = json_decode((string)($currentResponse['body'] ?? ''), true);
    $current = is_array($currentBody['obj'] ?? null) ? $currentBody['obj'] : [];
    if (!$current) {
        return $currentResponse;
    }
    $payload = [
        'email' => $email,
        'id' => (string)($changes['id'] ?? $current['uuid'] ?? ''),
        'password' => (string)($changes['password'] ?? $current['password'] ?? ''),
        'auth' => (string)($changes['auth'] ?? $current['auth'] ?? ''),
        'flow' => (string)($changes['flow'] ?? $current['flow'] ?? ''),
        'totalGB' => (int)($changes['totalGB'] ?? $current['totalGB'] ?? $current['total'] ?? 0),
        'expiryTime' => (int)($changes['expiryTime'] ?? $current['expiryTime'] ?? 0),
        'limitIp' => (int)($changes['limitIp'] ?? $current['limitIp'] ?? 0),
        'limitHwid' => (int)($changes['limitHwid'] ?? $current['limitHwid'] ?? 0),
        'tgId' => (int)($changes['tgId'] ?? $current['tgId'] ?? 0),
        'subId' => (string)($changes['subId'] ?? $current['subId'] ?? ''),
        'comment' => (string)($changes['comment'] ?? $current['comment'] ?? ''),
        'enable' => (bool)($changes['enable'] ?? $current['enable'] ?? true),
        'reset' => (int)($changes['reset'] ?? $current['reset'] ?? 0),
        'resetDay' => (int)($changes['resetDay'] ?? $current['resetDay'] ?? 0),
        'resetMax' => (int)($changes['resetMax'] ?? $current['resetMax'] ?? 0),
        'group' => (string)($changes['group'] ?? $current['group'] ?? ''),
    ];
    return xuiPanelRequest(
        $marzban_list_get,
        'post',
        '/panel/api/clients/update/' . rawurlencode($email),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function ResetUserDataUsagex_uisin($usernamepanel, $namepanel)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (xuiIsV3Panel($marzban_list_get)) {
        return xuiPanelRequest($marzban_list_get, 'post', '/panel/api/clients/resetTraffic/' . rawurlencode($usernamepanel), '');
    }
    $data_user = get_clinets($usernamepanel, $namepanel);
    $data_user = json_decode($data_user['body'], true)['obj'];
    return xuiPanelRequest(
        $marzban_list_get,
        'post',
        "/panel/api/inbounds/{$data_user['inboundId']}/resetClientTraffic/" . rawurlencode($usernamepanel),
        ''
    );
}

function removeClient($location, $username)
{
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $location, "select");
    $path = xuiIsV3Panel($marzban_list_get)
        ? '/panel/api/clients/del/' . rawurlencode($username) . '?keepTraffic=0'
        : "/panel/api/inbounds/{$marzban_list_get['inboundid']}/delClientByEmail/" . rawurlencode($username);
    return xuiPanelRequest($marzban_list_get, 'post', $path, '');
}

function xuiGetClientLinks($namepanel, $username)
{
    $panel = select("marzban_panel", "*", "name_panel", $namepanel, "select");
    if (!$panel || !xuiIsV3Panel($panel)) {
        return [];
    }
    $response = xuiPanelRequest($panel, 'get', '/panel/api/clients/links/' . rawurlencode($username));
    $body = json_decode((string)($response['body'] ?? ''), true);
    return is_array($body['obj'] ?? null) ? array_values($body['obj']) : [];
}

//-----------------------port forward (Tunnel)------------------------//

function addTunnelForward($name_panel, $listen_port, $target_ip, $target_port, $remark = "Tunnel", $expire_time = 0, $total_gb = 0, $is_test = false) {
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    if (!$marzban_list_get) {
        return ['body' => json_encode(['success' => false, 'msg' => 'Panel not found'])];
    }
    
    $settings = [
        "address"        => trim((string)$target_ip),
        "port"           => intval($target_port),
        "portMap"        => new stdClass(),
        "network"        => "tcp,udp",
        "followRedirect" => false
    ];

    $sniffing = [
        "enabled"      => false,
        "destOverride" => [
            "http",
            "tls",
            "quic",
            "fakedns"
        ],
        "metadataOnly" => false,
        "routeOnly"    => false
    ];

    // محاسبه بر اساس مگابایت در حالت تست، یا گیگابایت در حالت عادی
    $total_bytes = 0;
    if (floatval($total_gb) > 0) {
        if ($is_test) {
            $total_bytes = round(floatval($total_gb) * 1048576);
        } else {
            $total_bytes = round(floatval($total_gb) * 1073741824);
        }
    }

    $postData = [
        "up"                   => 0,
        "down"                 => 0,
        "total"                => $total_bytes,
        "remark"               => $remark,
        "enable"               => true,
        "expiryTime"           => ($expire_time > 0) ? intval($expire_time) * 1000 : 0,
        "trafficReset"         => "never",
        "lastTrafficResetTime" => 0,
        "listen"               => "",
        "port"                 => intval($listen_port),
        "protocol"             => "tunnel",
        "settings"             => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        "streamSettings"       => json_encode([
            "network"  => "raw",
            "security" => "none"
        ], JSON_UNESCAPED_SLASHES),
        "sniffing"             => json_encode($sniffing, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    ];

    return xuiPanelRequest(
        $marzban_list_get,
        'post',
        '/panel/api/inbounds/add',
        json_encode($postData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function updateTunnelForward($panel_name, $inbound_id, $listen_port, $target_ip, $target_port, $remark = "Tunnel", $expire_time = 0, $total_gb = 0, $is_test = false) {
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $panel_name, "select");
    if (!$marzban_list_get) {
        return ['body' => json_encode(['success' => false, 'msg' => 'Panel not found'])];
    }
    
    $settings = [
        "address"        => trim((string)$target_ip),
        "port"           => intval($target_port),
        "portMap"        => new stdClass(),
        "network"        => "tcp,udp",
        "followRedirect" => false
    ];

    $sniffing = [
        "enabled"      => false,
        "destOverride" => [
            "http",
            "tls",
            "quic",
            "fakedns"
        ],
        "metadataOnly" => false,
        "routeOnly"    => false
    ];

    // محاسبه بر اساس مگابایت در حالت تست، یا گیگابایت در حالت عادی
    $total_bytes = 0;
    if (floatval($total_gb) > 0) {
        if ($is_test) {
            $total_bytes = round(floatval($total_gb) * 1048576);
        } else {
            $total_bytes = round(floatval($total_gb) * 1073741824);
        }
    }

    $postData = [
        "up"                   => 0,
        "down"                 => 0,
        "total"                => $total_bytes,
        "remark"               => $remark,
        "enable"               => true,
        "expiryTime"           => ($expire_time > 0) ? intval($expire_time) * 1000 : 0,
        "trafficReset"         => "never",
        "lastTrafficResetTime" => 0,
        "listen"               => "",
        "port"                 => intval($listen_port),
        "protocol"             => "tunnel",
        "settings"             => json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        "streamSettings"       => json_encode([
            "network"  => "raw",
            "security" => "none"
        ], JSON_UNESCAPED_SLASHES),
        "sniffing"             => json_encode($sniffing, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    ];

    return xuiPanelRequest(
        $marzban_list_get,
        'post',
        '/panel/api/inbounds/update/' . intval($inbound_id),
        json_encode($postData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function removeTunnelForward($name_panel, $inbound_id) {
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    if (!$marzban_list_get) {
        return ['body' => json_encode(['success' => false, 'msg' => 'Panel not found'])];
    }

    return xuiPanelRequest($marzban_list_get, 'post', '/panel/api/inbounds/del/' . intval($inbound_id), '');
}

function getInboundsList($name_panel) {
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    if (!$marzban_list_get) {
        return ['body' => json_encode(['success' => false, 'msg' => 'Panel not found'])];
    }

    return xuiPanelRequest($marzban_list_get, 'get', '/panel/api/inbounds/list');
}

function resetTunnelTraffic($name_panel, $inbound_id) {
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    if (!$marzban_list_get) {
        return ['body' => json_encode(['success' => false, 'msg' => 'Panel not found'])];
    }

    $path = xuiIsV3Panel($marzban_list_get)
        ? '/panel/api/inbounds/' . intval($inbound_id) . '/resetTraffic'
        : '/panel/api/inbounds/resetInboundTraffics/' . intval($inbound_id);
    return xuiPanelRequest($marzban_list_get, 'post', $path, '');
}

function isValidPublicIpv4($ip) {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function getInbound($name_panel, $inbound_id) {
    $marzban_list_get = select("marzban_panel", "*", "name_panel", $name_panel, "select");
    if (!$marzban_list_get) {
        return ['body' => json_encode(['success' => false, 'msg' => 'Panel not found'])];
    }

    return xuiPanelRequest($marzban_list_get, 'get', '/panel/api/inbounds/get/' . intval($inbound_id));
}
