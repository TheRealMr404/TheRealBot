<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$base = 'http://127.0.0.1:18765';

function mockJson($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/api/admin/token' && $method === 'POST') {
    mockJson(['access_token' => 'e30.eyJleHAiOjQxMDI0NDQ4MDB9.signature']);
}
if ($path === '/api/admin' && $method === 'GET') {
    mockJson(['username' => 'owner']);
}
if ($path === '/api/groups' && $method === 'GET') {
    mockJson([
        'groups' => [
            ['id' => 7, 'name' => 'all-protocols', 'inbound_tags' => ['vless', 'vmess', 'trojan', 'ss', 'wg', 'hysteria2']],
        ],
        'total' => 1,
    ]);
}
if ($path === '/api/users' && $method === 'GET') {
    $status = $_GET['status'] ?? null;
    mockJson([
        'users' => $status === 'active'
            ? [['username' => 'active_user', 'status' => 'active']]
            : [
                ['username' => 'active_user', 'status' => 'active'],
                ['username' => 'disabled_user', 'status' => 'disabled'],
            ],
        'total' => $status === 'active' ? 1 : 2,
    ]);
}

$user = [
    'username' => 'test_user',
    'status' => 'active',
    'expire' => '2030-01-01T00:00:00Z',
    'data_limit' => 1073741824,
    'used_traffic' => 1024,
    'group_ids' => [7],
    'proxy_settings' => [
        'vmess' => new stdClass(),
        'vless' => new stdClass(),
        'trojan' => new stdClass(),
        'shadowsocks' => new stdClass(),
        'wireguard' => ['private_key' => 'private'],
        'hysteria' => new stdClass(),
    ],
    'subscription_url' => $base . '/sub/test-token',
    'data_limit_reset_strategy' => 'no_reset',
];

if ($path === '/api/user' && $method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    $user['username'] = $payload['username'] ?? $user['username'];
    mockJson($user, 201);
}
if ($path === '/api/user/test_user/reset' && $method === 'POST') {
    $user['used_traffic'] = 0;
    mockJson($user);
}
if ($path === '/api/user/test_user/revoke_sub' && $method === 'POST') {
    mockJson($user);
}
if ($path === '/api/user/test_user' && $method === 'GET') {
    mockJson($user);
}
if ($path === '/api/user/test_user' && $method === 'PUT') {
    mockJson(array_merge($user, json_decode(file_get_contents('php://input'), true) ?: []));
}
if ($path === '/api/user/test_user' && $method === 'DELETE') {
    http_response_code(204);
    exit;
}
if ($path === '/sub/test-token/links') {
    header('Content-Type: text/plain');
    echo "vmess://one\nvless://two\ntrojan://three\nss://four\nwireguard://five\nhysteria2://six\n";
    exit;
}
if ($path === '/sub/test-token/wireguard') {
    header('Content-Type: application/zip');
    echo "PK\x03\x04mock-wireguard-zip";
    exit;
}

mockJson(['detail' => 'not found'], 404);
