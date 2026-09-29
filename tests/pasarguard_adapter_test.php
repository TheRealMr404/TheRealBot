<?php

require_once __DIR__ . '/../pasarguard.php';

function assertTrue($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$panel = [
    'url_panel' => 'http://127.0.0.1:18765',
    'username_panel' => 'owner',
    'password_panel' => 'password',
    'inbounds' => '[7]',
    'on_hold_test' => '0',
    'conecton' => 'offconecton',
];

assertTrue(pasarguardNormalizeGroupIds('[7,"8",0,"bad"]') === [7, 8], 'group ids normalize');
assertTrue(pasarguardNormalizeUsername('TEST User!') === 'test_user', 'username normalize');
assertTrue(pasarguardCheckConnection($panel)['ok'], 'authentication and connection');

$groups = pasarguardGetGroups($panel);
assertTrue($groups['ok'] && count($groups['items']) === 1, 'group list');

$users = pasarguardListUsers($panel, 0, 1);
$activeUsers = pasarguardListUsers($panel, 0, 1, 'active');
assertTrue($users['ok'] && $users['total'] === 2, 'user list total');
assertTrue($activeUsers['ok'] && $activeUsers['total'] === 1, 'active user list total');

$created = pasarguardCreateUser(
    $panel,
    ['data_limit_reset' => 'no_reset'],
    'TEST User!',
    strtotime('2030-01-01 UTC'),
    1073741824,
    'adapter test'
);
assertTrue($created['ok'] && $created['data']['username'] === 'test_user', 'create user');

$links = pasarguardGetSubscriptionLinks($panel, $created['data']['subscription_url']);
assertTrue(count($links) === 6, 'all protocol links');
assertTrue(strpos(implode("\n", $links), 'wireguard://') !== false, 'wireguard link');
assertTrue(strpos(implode("\n", $links), 'hysteria2://') !== false, 'hysteria2 link');

assertTrue(pasarguardGetUser($panel, 'test_user')['ok'], 'get user');
assertTrue(pasarguardModifyUser($panel, 'test_user', ['status' => 'disabled'])['ok'], 'modify user');
assertTrue(pasarguardResetUserUsage($panel, 'test_user')['ok'], 'reset usage');
assertTrue(pasarguardRevokeUserSubscription($panel, 'test_user')['ok'], 'revoke subscription');

$files = pasarguardPrepareWireGuardFiles($panel, 'test_user');
assertTrue(count($files) === 1 && is_file($files[0]['path']), 'wireguard file download');
@unlink($files[0]['path']);

assertTrue(pasarguardDeleteUser($panel, 'test_user')['ok'], 'delete user');
echo "PasarGuard adapter tests passed.\n";
