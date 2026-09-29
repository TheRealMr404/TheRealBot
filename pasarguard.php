<?php

function pasarguardNormalizeUrl($url)
{
    $url = rtrim(trim((string) $url), '/');
    return preg_replace('~/api(?:/.*)?$~i', '', $url);
}

function pasarguardMigrateLegacyPanel($panel)
{
    if (!is_array($panel)) {
        return $panel;
    }
    if (($panel['type'] ?? '') === 'marzban' && (string) ($panel['version_panel'] ?? '0') === '1') {
        if (!empty($panel['code_panel']) && function_exists('update')) {
            update('marzban_panel', 'type', 'pasarguard', 'code_panel', $panel['code_panel']);
            update('marzban_panel', 'version_panel', '0', 'code_panel', $panel['code_panel']);
        }
        $panel['type'] = 'pasarguard';
        $panel['version_panel'] = '0';
    }
    return $panel;
}

function pasarguardDashboardUrl($url)
{
    $baseUrl = rtrim(pasarguardNormalizeUrl($url), '/');
    return preg_match('~/dashboard$~i', $baseUrl) ? $baseUrl : $baseUrl . '/dashboard';
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

function pasarguardGeneratePassword($username = '')
{
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $special = '!@#$%*-_+';
    $all = $upper . $lower . $digits . $special;

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $characters = [];
        foreach ([[$upper, 2], [$lower, 2], [$digits, 2], [$special, 2], [$all, 10]] as $group) {
            for ($i = 0; $i < $group[1]; $i++) {
                $characters[] = $group[0][random_int(0, strlen($group[0]) - 1)];
            }
        }
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $swap = random_int(0, $i);
            [$characters[$i], $characters[$swap]] = [$characters[$swap], $characters[$i]];
        }
        $password = implode('', $characters);
        if ($username === '' || stripos($password, (string) $username) === false) {
            return $password;
        }
    }

    return 'PG@az19' . bin2hex(random_bytes(6));
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

function pasarguardListUsers($panel, $offset = 0, $limit = 20, $status = null)
{
    $query = [
        'offset' => max(0, (int) $offset),
        'limit' => max(1, min(100, (int) $limit)),
        'sort' => 'username',
    ];
    if (is_string($status) && $status !== '') {
        $query['status'] = $status;
    }

    $response = pasarguardApiRequest($panel, 'GET', 'users?' . http_build_query($query));
    if (!$response['ok']) {
        return $response;
    }

    $data = is_array($response['data']) ? $response['data'] : [];
    $items = $data['users'] ?? $data['items'] ?? $data['data'] ?? [];
    $response['items'] = is_array($items) ? array_values($items) : [];
    $response['total'] = (int) ($data['total'] ?? $data['count'] ?? count($response['items']));
    return $response;
}

function pasarguardAbsoluteUrl($panel, $url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    return rtrim(pasarguardNormalizeUrl($panel['url_panel'] ?? ''), '/') . '/' . ltrim($url, '/');
}

function pasarguardNormalizeGroupIds($value)
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $value = $decoded;
        } else {
            $value = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        }
    }
    if (is_array($value) && isset($value['group_ids'])) {
        $value = $value['group_ids'];
    }
    if (!is_array($value)) {
        return [];
    }
    $ids = [];
    foreach ($value as $id) {
        if (is_numeric($id) && (int) $id > 0) {
            $ids[(int) $id] = (int) $id;
        }
    }
    return array_values($ids);
}

function pasarguardResolveGroupIds($panel, $product = [])
{
    $productGroups = pasarguardNormalizeGroupIds(is_array($product) ? ($product['inbounds'] ?? null) : null);
    if ($productGroups) {
        return $productGroups;
    }
    return pasarguardNormalizeGroupIds($panel['inbounds'] ?? null);
}

function pasarguardGetGroups($panel)
{
    $response = pasarguardApiRequest($panel, 'GET', 'groups?offset=0&limit=100');
    if (!$response['ok']) {
        $response = pasarguardApiRequest($panel, 'GET', 'groups/simple?offset=0&limit=100');
    }
    if (!$response['ok']) {
        return $response;
    }
    $items = $response['data']['groups'] ?? $response['data']['items'] ?? $response['data'] ?? [];
    $response['items'] = is_array($items) ? array_values($items) : [];
    $response['total'] = (int) ($response['data']['total'] ?? count($response['items']));
    return $response;
}

function pasarguardGroupsKeyboardData($panel, $selectedIds, $callbackPrefix, $doneCallback)
{
    $groups = pasarguardGetGroups($panel);
    if (!$groups['ok']) {
        return ['ok' => false, 'msg' => $groups['msg'], 'text' => '', 'keyboard' => null];
    }
    $selectedIds = pasarguardNormalizeGroupIds($selectedIds);
    $keyboard = ['inline_keyboard' => []];
    foreach ($groups['items'] as $group) {
        if (!is_array($group) || empty($group['id'])) {
            continue;
        }
        $groupId = (int) $group['id'];
        $name = trim((string) ($group['name'] ?? ('گروه ' . $groupId)));
        $enabled = in_array($groupId, $selectedIds, true);
        $keyboard['inline_keyboard'][] = [[
            'text' => ($enabled ? '✅ ' : '▫️ ') . $name . ' (' . $groupId . ')',
            'callback_data' => $callbackPrefix . $groupId,
        ]];
    }
    $keyboard['inline_keyboard'][] = [[
        'text' => '✅ ذخیره و بازگشت',
        'callback_data' => $doneCallback,
    ]];
    $protocols = 'VMess، VLESS، Trojan، Shadowsocks، WireGuard و Hysteria2';
    $text = "⚙️ <b>انتخاب گروه‌های پاسارگارد</b>\n\n"
        . "هر پروتکلی که در گروه‌های انتخابی پنل فعال باشد، به‌صورت خودکار برای کاربر ساخته می‌شود.\n"
        . "پروتکل‌های پشتیبانی‌شده: {$protocols}\n\n"
        . "حداقل یک گروه را انتخاب کنید.";
    return [
        'ok' => true,
        'msg' => '',
        'text' => $text,
        'keyboard' => json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function pasarguardNormalizeUsername($username)
{
    $username = strtolower(trim((string) $username));
    $username = preg_replace('/[^a-z0-9_]+/', '_', $username);
    $username = trim((string) $username, '_');
    if ($username === '' || strlen($username) < 3) {
        $username = 'user_' . bin2hex(random_bytes(4));
    }
    return substr($username, 0, 32);
}

function pasarguardExpireValue($timestamp)
{
    $timestamp = (int) $timestamp;
    return $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : 0;
}

function pasarguardCreateUser($panel, $product, $username, $expire, $dataLimit, $note, $isTest = false)
{
    $groupIds = pasarguardResolveGroupIds($panel, $product);
    if (!$groupIds) {
        return [
            'ok' => false,
            'status' => 422,
            'data' => null,
            'msg' => 'هیچ گروهی برای پنل پاسارگارد انتخاب نشده است. ابتدا گروه‌های پیش‌فرض پنل را تنظیم کنید.',
        ];
    }

    $resetStrategy = (string) ($product['data_limit_reset'] ?? 'no_reset');
    if (!in_array($resetStrategy, ['no_reset', 'day', 'week', 'month', 'year'], true)) {
        $resetStrategy = 'no_reset';
    }
    $payload = [
        'username' => pasarguardNormalizeUsername($username),
        'status' => 'active',
        'expire' => pasarguardExpireValue($expire),
        'data_limit' => max(0, (int) $dataLimit),
        'data_limit_reset_strategy' => $resetStrategy,
        'group_ids' => $groupIds,
        'note' => (string) $note,
    ];

    $firstUse = ($isTest && (string) ($panel['on_hold_test'] ?? '0') === '1')
        || (!$isTest && (string) ($panel['conecton'] ?? '') === 'onconecton');
    if ($firstUse && (int) $expire > time()) {
        $payload['status'] = 'on_hold';
        $payload['expire'] = 0;
        $payload['on_hold_expire_duration'] = max(60, (int) $expire - time());
    }

    return pasarguardApiRequest($panel, 'POST', 'user', $payload);
}

function pasarguardGetUser($panel, $username)
{
    return pasarguardApiRequest($panel, 'GET', 'user/' . rawurlencode((string) $username));
}

function pasarguardModifyUser($panel, $username, $payload)
{
    $allowed = [
        'status', 'expire', 'data_limit', 'data_limit_reset_strategy', 'proxy_settings',
        'group_ids', 'note', 'on_hold_timeout', 'on_hold_expire_duration', 'next_plan',
    ];
    $payload = array_intersect_key((array) $payload, array_flip($allowed));
    if (isset($payload['expire']) && is_numeric($payload['expire'])) {
        $payload['expire'] = pasarguardExpireValue((int) $payload['expire']);
    }
    if (isset($payload['group_ids'])) {
        $payload['group_ids'] = pasarguardNormalizeGroupIds($payload['group_ids']);
    }
    if (!$payload) {
        return ['ok' => false, 'status' => 422, 'data' => null, 'msg' => 'اطلاعاتی برای ویرایش سرویس ارسال نشده است.'];
    }
    return pasarguardApiRequest($panel, 'PUT', 'user/' . rawurlencode((string) $username), $payload);
}

function pasarguardDeleteUser($panel, $username)
{
    return pasarguardApiRequest($panel, 'DELETE', 'user/' . rawurlencode((string) $username));
}

function pasarguardResetUserUsage($panel, $username)
{
    return pasarguardApiRequest($panel, 'POST', 'user/' . rawurlencode((string) $username) . '/reset');
}

function pasarguardRevokeUserSubscription($panel, $username)
{
    return pasarguardApiRequest($panel, 'POST', 'user/' . rawurlencode((string) $username) . '/revoke_sub');
}

function pasarguardPublicRequest($url, $maxBytes = 12582912)
{
    $url = trim((string) $url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'content_type' => '', 'msg' => 'آدرس اشتراک پاسارگارد معتبر نیست.'];
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    $raw = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);
    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'content_type' => '', 'msg' => $curlError ?: 'دریافت خروجی اشتراک ناموفق بود.'];
    }
    $body = substr($raw, $headerSize);
    if (strlen($body) > $maxBytes) {
        return ['ok' => false, 'status' => $statusCode, 'body' => '', 'content_type' => $contentType, 'msg' => 'حجم فایل دریافتی از حد مجاز بیشتر است.'];
    }
    $ok = $statusCode >= 200 && $statusCode < 300;
    return ['ok' => $ok, 'status' => $statusCode, 'body' => $body, 'content_type' => $contentType, 'msg' => $ok ? '' : 'خطای HTTP ' . $statusCode];
}

function pasarguardGetSubscriptionLinks($panel, $subscriptionUrl)
{
    $subscriptionUrl = rtrim(pasarguardAbsoluteUrl($panel, $subscriptionUrl), '/');
    if ($subscriptionUrl === '') {
        return [];
    }
    $response = pasarguardPublicRequest($subscriptionUrl . '/links', 4194304);
    if (!$response['ok']) {
        return [];
    }
    $body = trim((string) $response['body']);
    $decoded = base64_decode($body, true);
    if ($decoded !== false && preg_match('/(?:vmess|vless|trojan|ss|wireguard|hysteria2?):\/\//i', $decoded)) {
        $body = $decoded;
    }
    $links = preg_split('/\r?\n/', trim($body), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_filter(array_map('trim', $links), function ($link) {
        return preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $link);
    }));
}

function pasarguardUserOutput($panel, $user, $customSubscriptionUrl = null)
{
    $subscriptionUrl = pasarguardAbsoluteUrl($panel, $user['subscription_url'] ?? '');
    $expire = $user['expire'] ?? 0;
    if (is_string($expire) && !ctype_digit($expire)) {
        $expire = strtotime($expire) ?: 0;
    }
    return [
        'status' => (string) ($user['status'] ?? 'active'),
        'username' => (string) ($user['username'] ?? ''),
        'data_limit' => (int) ($user['data_limit'] ?? 0),
        'expire' => (int) $expire,
        'online_at' => $user['online_at'] ?? null,
        'used_traffic' => (int) ($user['used_traffic'] ?? 0),
        'links' => pasarguardGetSubscriptionLinks($panel, $subscriptionUrl),
        'subscription_url' => $customSubscriptionUrl ?: $subscriptionUrl,
        'panel_subscription_url' => $subscriptionUrl,
        'sub_updated_at' => $user['sub_updated_at'] ?? null,
        'sub_last_user_agent' => $user['sub_last_user_agent'] ?? null,
        'uuid' => $user['proxy_settings'] ?? [],
        'data_limit_reset' => $user['data_limit_reset_strategy'] ?? 'no_reset',
        'group_ids' => pasarguardNormalizeGroupIds($user['group_ids'] ?? []),
    ];
}

function pasarguardPrepareWireGuardFiles($panel, $username)
{
    $userResponse = pasarguardGetUser($panel, $username);
    if (!$userResponse['ok'] || empty($userResponse['data']['subscription_url'])) {
        return [];
    }
    $proxySettings = $userResponse['data']['proxy_settings'] ?? [];
    if (!is_array($proxySettings) || !array_key_exists('wireguard', $proxySettings)) {
        return [];
    }
    $subscriptionUrl = rtrim(pasarguardAbsoluteUrl($panel, $userResponse['data']['subscription_url']), '/');
    $download = pasarguardPublicRequest($subscriptionUrl . '/wireguard');
    if (!$download['ok'] || $download['body'] === '') {
        return [];
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'pgwg_');
    if ($zipPath === false || file_put_contents($zipPath, $download['body']) === false) {
        return [];
    }
    $safeUser = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $username);
    if (!class_exists('ZipArchive')) {
        $finalZipPath = $zipPath . '.zip';
        @unlink($finalZipPath);
        if (!@rename($zipPath, $finalZipPath)) {
            @unlink($zipPath);
            return [];
        }
        return [[
            'path' => $finalZipPath,
            'name' => ($safeUser ?: 'pasarguard') . '-wireguard.zip',
            'mime' => 'application/zip',
        ]];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [[
            'path' => $zipPath,
            'name' => ($safeUser ?: 'pasarguard') . '-wireguard.zip',
            'mime' => 'application/zip',
        ]];
    }
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if (!is_string($entry) || !preg_match('/\.conf$/i', $entry)) {
            continue;
        }
        $content = $zip->getFromIndex($i);
        if (!is_string($content) || strpos($content, '[Interface]') === false) {
            continue;
        }
        $path = tempnam(sys_get_temp_dir(), 'pgconf_');
        if ($path === false) {
            continue;
        }
        $finalPath = $path . '.conf';
        @unlink($path);
        if (file_put_contents($finalPath, $content) !== false) {
            $files[] = [
                'path' => $finalPath,
                'name' => ($safeUser ?: 'pasarguard') . '-' . (count($files) + 1) . '.conf',
                'mime' => 'application/x-wireguard-profile',
            ];
        }
    }
    $zip->close();
    @unlink($zipPath);
    return $files;
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

function pasarguardListAdmins($panel, $offset = 0, $limit = 20)
{
    $query = http_build_query([
        'offset' => max(0, (int) $offset),
        'limit' => max(1, min(100, (int) $limit)),
        'sort' => 'username',
    ]);
    $response = pasarguardApiRequest($panel, 'GET', 'admins?' . $query);
    if (!$response['ok']) {
        return $response;
    }
    $response['items'] = pasarguardListValues($response['data']);
    $response['total'] = (int) ($response['data']['total'] ?? count($response['items']));
    $response['active'] = (int) ($response['data']['active'] ?? 0);
    $response['disabled'] = (int) ($response['data']['disabled'] ?? 0);
    $response['limited'] = (int) ($response['data']['limited'] ?? 0);
    return $response;
}

function pasarguardFindAdminById($panel, $adminId)
{
    $response = pasarguardApiRequest($panel, 'GET', 'admins?ids=' . (int) $adminId . '&limit=1');
    if (!$response['ok']) {
        return $response;
    }
    foreach (pasarguardListValues($response['data']) as $admin) {
        if (is_array($admin) && (int) ($admin['id'] ?? 0) === (int) $adminId) {
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
    $admin = pasarguardFindAdmin($panel, $username);
    if (!$admin['ok'] || empty($admin['data']['id'])) {
        return $admin;
    }
    return pasarguardModifyAdminById($panel, (int) $admin['data']['id'], $payload);
}

function pasarguardModifyAdminById($panel, $adminId, $payload)
{
    return pasarguardApiRequest($panel, 'PUT', 'admin/by-id/' . (int) $adminId, $payload);
}

function pasarguardDeleteAdmin($panel, $username)
{
    $admin = pasarguardFindAdmin($panel, $username);
    if (!$admin['ok'] || empty($admin['data']['id'])) {
        return $admin;
    }
    return pasarguardDeleteAdminById($panel, (int) $admin['data']['id']);
}

function pasarguardDeleteAdminById($panel, $adminId)
{
    return pasarguardApiRequest($panel, 'DELETE', 'admin/by-id/' . (int) $adminId);
}

function pasarguardResetAdminUsage($panel, $username)
{
    $admin = pasarguardFindAdmin($panel, $username);
    if (!$admin['ok'] || empty($admin['data']['id'])) {
        return $admin;
    }
    return pasarguardResetAdminUsageById($panel, (int) $admin['data']['id']);
}

function pasarguardResetAdminUsageById($panel, $adminId)
{
    return pasarguardApiRequest($panel, 'POST', 'admin/by-id/' . (int) $adminId . '/reset');
}

function pasarguardHumanBytes($bytes, $zeroLabel = 'نامحدود')
{
    $bytes = max(0, (float) $bytes);
    if ($bytes <= 0) {
        return (string) $zeroLabel;
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / pow(1024, $power);
    return number_format($value, $power > 1 ? 2 : 0) . ' ' . $units[$power];
}

function pasarguardStatusLabel($status)
{
    $status = strtolower((string) $status);
    $labels = [
        'active' => 'فعال',
        'disabled' => 'غیرفعال',
        'limited' => 'محدودشده',
        'expired' => 'منقضی',
    ];
    return $labels[$status] ?? $status;
}

function pasarguardProductSettings($product, $panel)
{
    $product = is_array($product) ? $product : [];
    $panel = is_array($panel) ? $panel : [];
    $settings = json_decode((string) ($product['inbounds'] ?? ''), true);
    if (!is_array($settings) || ($settings['provider'] ?? '') !== 'pasarguard') {
        $settings = [];
    }
    return [
        'role_id' => max(1, (int) ($settings['role_id'] ?? $panel['inboundid'] ?? 1)),
        'max_users' => max(0, (int) ($settings['max_users'] ?? 0)),
    ];
}

function pasarguardApplyInvoiceExtension($invoice, $days, $dataLimitBytes = null)
{
    if (!is_array($invoice) || empty($invoice['id_invoice']) || !function_exists('update')) {
        return false;
    }

    $now = time();
    $days = max(0, (int) $days);
    $currentExpire = (int) ($invoice['Service_time'] ?? 0) > 0
        ? (int) ($invoice['time_sell'] ?? 0) + ((int) $invoice['Service_time'] * 86400)
        : 0;
    $baseTime = max($now, $currentExpire);
    $newExpire = $days === 0 ? 0 : $baseTime + ($days * 86400);
    $storedDays = $newExpire === 0 ? 0 : (int) ceil(($newExpire - $now) / 86400);

    update('invoice', 'time_sell', $now, 'id_invoice', $invoice['id_invoice']);
    update('invoice', 'Service_time', $storedDays, 'id_invoice', $invoice['id_invoice']);
    update('invoice', 'Status', 'active', 'id_invoice', $invoice['id_invoice']);
    if ($dataLimitBytes !== null) {
        $volume = max(0, (int) round(((float) $dataLimitBytes) / pow(1024, 3)));
        update('invoice', 'Volume', $volume, 'id_invoice', $invoice['id_invoice']);
    }

    return true;
}

function pasarguardBuildDeliveryText($panel, $output, $product)
{
    $url = htmlspecialchars(pasarguardDashboardUrl($panel['url_panel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $username = htmlspecialchars((string) ($output['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $password = htmlspecialchars((string) ($output['subscription_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name = htmlspecialchars((string) ($product['name_product'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $days = (int) ($product['Service_time'] ?? 0);
    $volume = (int) ($product['Volume_constraint'] ?? 0);
    $maxUsers = (int) ($output['max_users'] ?? 0);
    $duration = $days > 0 ? $days . ' روز' : 'نامحدود';
    $traffic = $volume > 0 ? $volume . ' گیگابایت' : 'نامحدود';
    $users = $maxUsers > 0 ? $maxUsers . ' کاربر' : 'مطابق نقش انتخابی';

    return "<tg-emoji emoji-id=\"5350572310627632617\">✅</tg-emoji> <b>نمایندگی پاسارگارد با موفقیت فعال شد</b>\n\n"
        . "<tg-emoji emoji-id=\"5348540950010412359\">🌐</tg-emoji> <b>آدرس ورود:</b> <code>{$url}</code>\n"
        . "<tg-emoji emoji-id=\"5258011929993026890\">👤</tg-emoji> <b>نام کاربری:</b> <code>{$username}</code>\n"
        . "<tg-emoji emoji-id=\"5373052667671093676\">🔑</tg-emoji> <b>رمز عبور:</b> <code>{$password}</code>\n\n"
        . "<tg-emoji emoji-id=\"5280962371207077415\">🛍</tg-emoji> <b>پلن:</b> {$name}\n"
        . "<tg-emoji emoji-id=\"5258113901106580375\">⏳</tg-emoji> <b>اعتبار:</b> {$duration}\n"
        . "<tg-emoji emoji-id=\"5350481089817232086\">💾</tg-emoji> <b>سقف ترافیک:</b> {$traffic}\n"
        . "<tg-emoji emoji-id=\"5985379274524202415\">👥</tg-emoji> <b>حداکثر کاربران:</b> {$users}\n\n"
        . "<tg-emoji emoji-id=\"5350626912546865231\">⚠️</tg-emoji> برای امنیت بیشتر، پس از اولین ورود رمز عبور را تغییر دهید.";
}

function pasarguardBuildTestDeliveryText($panel, $output, $hours, $volumeMb)
{
    $url = htmlspecialchars(pasarguardDashboardUrl($panel['url_panel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $username = htmlspecialchars((string) ($output['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $password = htmlspecialchars((string) ($output['subscription_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $hours = max(1, (int) $hours);
    $volumeMb = max(0, (int) $volumeMb);
    $traffic = $volumeMb > 0 ? number_format($volumeMb) . ' مگابایت' : 'نامحدود';

    return "<tg-emoji emoji-id=\"5350572310627632617\">✅</tg-emoji> <b>نمایندگی آزمایشی پاسارگارد ساخته شد</b>\n\n"
        . "<tg-emoji emoji-id=\"5348540950010412359\">🌐</tg-emoji> <b>آدرس ورود:</b> <code>{$url}</code>\n"
        . "<tg-emoji emoji-id=\"5258011929993026890\">👤</tg-emoji> <b>نام کاربری:</b> <code>{$username}</code>\n"
        . "<tg-emoji emoji-id=\"5373052667671093676\">🔑</tg-emoji> <b>رمز عبور:</b> <code>{$password}</code>\n\n"
        . "<tg-emoji emoji-id=\"5258113901106580375\">⏳</tg-emoji> <b>مدت اعتبار:</b> {$hours} ساعت\n"
        . "<tg-emoji emoji-id=\"5350481089817232086\">💾</tg-emoji> <b>سقف ترافیک:</b> {$traffic}\n"
        . "<tg-emoji emoji-id=\"5985379274524202415\">👥</tg-emoji> <b>حداکثر کاربران:</b> 1 کاربر\n\n"
        . "<tg-emoji emoji-id=\"5350626912546865231\">⚠️</tg-emoji> این حساب آزمایشی است و پس از پایان زمان تعیین‌شده غیرفعال می‌شود.";
}

function pasarguardBuildCustomerPanelText($panel, $invoice, $data)
{
    $panelName = htmlspecialchars((string) ($panel['name_panel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dashboardUrl = htmlspecialchars(pasarguardDashboardUrl($panel['url_panel'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $username = htmlspecialchars((string) ($invoice['username'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $password = htmlspecialchars((string) ($invoice['user_info'] ?? $data['subscription_url'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $productName = htmlspecialchars((string) ($invoice['name_product'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $isTest = ($invoice['name_product'] ?? '') === 'سرویس تست';
    $accountType = $isTest ? 'آزمایشی' : 'خریداری‌شده';

    $status = (string) ($data['status'] ?? $invoice['Status'] ?? 'Unknown');
    $statusText = htmlspecialchars(pasarguardStatusLabel($status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($status === 'Unsuccessful') {
        $statusText = 'عدم دسترسی به پنل';
    }

    $expire = (int) ($data['expire'] ?? 0);
    if ($expire <= 0 && (int) ($invoice['Service_time'] ?? 0) > 0) {
        $durationSeconds = $isTest ? 3600 : 86400;
        $expire = (int) ($invoice['time_sell'] ?? 0) + ((int) $invoice['Service_time'] * $durationSeconds);
    }
    if ($expire > 0) {
        $remainingSeconds = max(0, $expire - time());
        $remainingHours = (int) ceil($remainingSeconds / 3600);
        $remainingText = $remainingHours >= 24
            ? (int) ceil($remainingHours / 24) . ' روز'
            : $remainingHours . ' ساعت';
        $expireText = date('Y/m/d H:i', $expire) . " ({$remainingText} باقی‌مانده)";
    } else {
        $expireText = 'نامحدود';
    }

    $dataLimit = max(0, (int) ($data['data_limit'] ?? 0));
    $usedTraffic = max(0, (int) ($data['used_traffic'] ?? 0));
    $remainingTraffic = $dataLimit > 0 ? max(0, $dataLimit - $usedTraffic) : 0;
    $limitText = pasarguardHumanBytes($dataLimit);
    $usedText = pasarguardHumanBytes($usedTraffic, '0 B');
    $remainingTrafficText = $dataLimit > 0 ? pasarguardHumanBytes($remainingTraffic, '0 B') : 'نامحدود';
    $maxUsers = (int) ($data['max_users'] ?? 0);
    $maxUsersText = $maxUsers > 0 ? $maxUsers . ' کاربر' : 'مطابق نقش نمایندگی';

    $text = "<tg-emoji emoji-id=\"5350295774863311434\">🧩</tg-emoji> <b>پنل نمایندگی من</b>\n\n"
        . "<tg-emoji emoji-id=\"5348404473129614535\">🖥</tg-emoji> <b>پنل:</b> {$panelName}\n"
        . "<tg-emoji emoji-id=\"5280962371207077415\">🛍</tg-emoji> <b>پلن:</b> {$productName}\n"
        . "<tg-emoji emoji-id=\"5348470692935384957\">🏷</tg-emoji> <b>نوع حساب:</b> {$accountType}\n"
        . "<tg-emoji emoji-id=\"5348498060466996739\">📊</tg-emoji> <b>وضعیت:</b> {$statusText}\n\n"
        . "<tg-emoji emoji-id=\"5348540950010412359\">🌐</tg-emoji> <b>آدرس ورود:</b> <code>{$dashboardUrl}</code>\n"
        . "<tg-emoji emoji-id=\"5258011929993026890\">👤</tg-emoji> <b>نام کاربری:</b> <code>{$username}</code>\n"
        . "<tg-emoji emoji-id=\"5373052667671093676\">🔑</tg-emoji> <b>رمز عبور:</b> <code>{$password}</code>\n\n"
        . "<tg-emoji emoji-id=\"5258113901106580375\">⏳</tg-emoji> <b>اعتبار:</b> {$expireText}\n"
        . "<tg-emoji emoji-id=\"5350481089817232086\">💾</tg-emoji> <b>سقف ترافیک:</b> {$limitText}\n"
        . "<tg-emoji emoji-id=\"5429571366384842791\">📥</tg-emoji> <b>مصرف‌شده:</b> {$usedText}\n"
        . "<tg-emoji emoji-id=\"5350572310627632617\">📤</tg-emoji> <b>باقی‌مانده:</b> {$remainingTrafficText}\n"
        . "<tg-emoji emoji-id=\"5985379274524202415\">👥</tg-emoji> <b>حداکثر کاربران:</b> {$maxUsersText}";

    if ($status === 'Unsuccessful') {
        $text .= "\n\n<tg-emoji emoji-id=\"5350626912546865231\">⚠️</tg-emoji> دریافت اطلاعات زنده ممکن نشد؛ اطلاعات ورود ذخیره‌شده همچنان نمایش داده شده است.";
    }

    return $text;
}
