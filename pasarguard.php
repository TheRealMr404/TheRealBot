<?php

function pasarguardNormalizeUrl($url)
{
    $url = rtrim(trim((string) $url), '/');
    return preg_replace('~/api(?:/.*)?$~i', '', $url);
}

function pasarguardErrorText($data, $fallback = 'خطای نامشخص از پنل پاسارگارد')
{
    if (!is_array($data)) {
        return $fallback;
    }
    $detail = $data['detail'] ?? $data['message'] ?? $data['error'] ?? $fallback;
    if (is_array($detail) || is_object($detail)) {
        return json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string) $detail;
}

function pasarguardDecodeJwtExpiry($token)
{
    $parts = explode('.', (string) $token);
    if (count($parts) < 2) {
        return time() + 300;
    }
    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $decoded = json_decode((string) base64_decode($payload), true);
    return isset($decoded['exp']) ? (int) $decoded['exp'] : time() + 300;
}

function pasarguardHttpRequest($panel, $method, $path, $payload = null, $token = null, $form = false)
{
    $url = pasarguardNormalizeUrl($panel['url_panel'] ?? '') . '/api/' . ltrim($path, '/');
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($payload !== null) {
        if ($form) {
            $body = http_build_query($payload);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (isset($body)) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'msg' => $curlError ?: 'خطا در اتصال به پنل پاسارگارد'];
    }
    $data = $raw === '' ? [] : json_decode($raw, true);
    if ($data === null && $raw !== '' && strtolower(trim($raw)) !== 'null') {
        $data = ['message' => $raw];
    }
    $ok = $statusCode >= 200 && $statusCode < 300;
    return [
        'ok' => $ok,
        'status' => $statusCode,
        'data' => $data,
        'msg' => $ok ? '' : pasarguardErrorText($data, 'خطای HTTP ' . $statusCode),
    ];
}

function pasarguardAuthenticate($panel, $force = false)
{
    $cached = json_decode((string) ($panel['datelogin'] ?? ''), true);
    if (!$force && is_array($cached) && !empty($cached['pasarguard_token']) && (int) ($cached['expires_at'] ?? 0) > time() + 30) {
        return ['ok' => true, 'token' => $cached['pasarguard_token']];
    }

    $response = pasarguardHttpRequest($panel, 'POST', 'admin/token', [
        'grant_type' => 'password',
        'username' => (string) ($panel['username_panel'] ?? ''),
        'password' => (string) ($panel['password_panel'] ?? ''),
    ], null, true);
    $token = $response['data']['access_token'] ?? null;
    if (!$response['ok'] || !$token) {
        return ['ok' => false, 'msg' => $response['msg'] ?: 'نام کاربری یا رمز عبور پنل صحیح نیست'];
    }

    $cache = [
        'pasarguard_token' => $token,
        'expires_at' => pasarguardDecodeJwtExpiry($token),
    ];
    if (!empty($panel['code_panel']) && function_exists('update')) {
        update('marzban_panel', 'datelogin', json_encode($cache), 'code_panel', $panel['code_panel']);
    }
    return ['ok' => true, 'token' => $token];
}

function pasarguardApiRequest($panel, $method, $path, $payload = null, $retry = true)
{
    $auth = pasarguardAuthenticate($panel);
    if (!$auth['ok']) {
        return ['ok' => false, 'status' => 401, 'data' => null, 'msg' => $auth['msg']];
    }
    $response = pasarguardHttpRequest($panel, $method, $path, $payload, $auth['token']);
    if ($response['status'] === 401 && $retry) {
        $auth = pasarguardAuthenticate($panel, true);
        if (!$auth['ok']) {
            return ['ok' => false, 'status' => 401, 'data' => null, 'msg' => $auth['msg']];
        }
        return pasarguardHttpRequest($panel, $method, $path, $payload, $auth['token']);
    }
    return $response;
}

function pasarguardCheckConnection($panel)
{
    return pasarguardApiRequest($panel, 'GET', 'admin');
}

function pasarguardListValues($data)
{
    if (!is_array($data)) {
        return [];
    }
    foreach (['items', 'admins', 'roles', 'results', 'data'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return array_values($data[$key]);
        }
    }
    if (array_is_list($data)) {
        return $data;
    }
    return isset($data['username']) || isset($data['id']) ? [$data] : [];
}

function pasarguardGetRoles($panel)
{
    $response = pasarguardApiRequest($panel, 'GET', 'admin-roles/simple');
    if (!$response['ok']) {
        return $response;
    }
    $response['items'] = pasarguardListValues($response['data']);
    return $response;
}

function pasarguardFindAdmin($panel, $username)
{
    $response = pasarguardApiRequest($panel, 'GET', 'admins?username=' . rawurlencode($username));
    if (!$response['ok']) {
        return $response;
    }
    foreach (pasarguardListValues($response['data']) as $admin) {
        if (is_array($admin) && strcasecmp((string) ($admin['username'] ?? ''), (string) $username) === 0) {
            return ['ok' => true, 'status' => $response['status'], 'data' => $admin, 'msg' => ''];
        }
    }
    return ['ok' => false, 'status' => 404, 'data' => null, 'msg' => 'ادمین در پنل پیدا نشد'];
}

function pasarguardCreateAdmin($panel, $username, $password, $roleId, $dataLimit, $maxUsers, $note)
{
    $payload = [
        'username' => (string) $username,
        'password' => (string) $password,
        'role_id' => (int) $roleId,
        'status' => 'active',
        'note' => (string) $note,
    ];
    if ((int) $dataLimit > 0) {
        $payload['data_limit'] = (int) $dataLimit;
    }
    if ((int) $maxUsers > 0) {
        $payload['permission_overrides'] = ['max_users' => (int) $maxUsers];
    }
    return pasarguardApiRequest($panel, 'POST', 'admin', $payload);
}

function pasarguardModifyAdmin($panel, $username, $payload)
{
    return pasarguardApiRequest($panel, 'PUT', 'admin/' . rawurlencode($username), $payload);
}

function pasarguardDeleteAdmin($panel, $username)
{
    return pasarguardApiRequest($panel, 'DELETE', 'admin/' . rawurlencode($username));
}

function pasarguardResetAdminUsage($panel, $username)
{
    return pasarguardApiRequest($panel, 'POST', 'admin/' . rawurlencode($username) . '/reset');
}

function pasarguardProductSettings($product, $panel)
{
    $settings = json_decode((string) ($product['inbounds'] ?? ''), true);
    if (!is_array($settings) || ($settings['provider'] ?? '') !== 'pasarguard') {
        $settings = [];
    }
    return [
        'role_id' => max(1, (int) ($settings['role_id'] ?? $panel['inboundid'] ?? 1)),
        'max_users' => max(0, (int) ($settings['max_users'] ?? 0)),
    ];
}

function pasarguardBuildDeliveryText($panel, $output, $product)
{
    $url = htmlspecialchars(pasarguardNormalizeUrl($panel['url_panel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $username = htmlspecialchars((string) ($output['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $password = htmlspecialchars((string) ($output['subscription_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name = htmlspecialchars((string) ($product['name_product'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $days = (int) ($product['Service_time'] ?? 0);
    $volume = (int) ($product['Volume_constraint'] ?? 0);
    $maxUsers = (int) ($output['max_users'] ?? 0);
    $duration = $days > 0 ? $days . ' روز' : 'نامحدود';
    $traffic = $volume > 0 ? $volume . ' گیگابایت' : 'نامحدود';
    $users = $maxUsers > 0 ? $maxUsers . ' کاربر' : 'مطابق نقش انتخابی';

    return "✅ <b>نمایندگی پاسارگارد با موفقیت فعال شد</b>\n\n"
        . "🌐 <b>آدرس ورود:</b> <code>{$url}</code>\n"
        . "👤 <b>نام کاربری:</b> <code>{$username}</code>\n"
        . "🔑 <b>رمز عبور:</b> <code>{$password}</code>\n\n"
        . "🛍 <b>پلن:</b> {$name}\n"
        . "⏳ <b>اعتبار:</b> {$duration}\n"
        . "💾 <b>سقف ترافیک:</b> {$traffic}\n"
        . "👥 <b>حداکثر کاربران:</b> {$users}\n\n"
        . "⚠️ برای امنیت بیشتر، پس از اولین ورود رمز عبور را تغییر دهید.";
}
