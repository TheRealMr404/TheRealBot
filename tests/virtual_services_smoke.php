<?php

class VirtualServicesFakeStatement
{
    private $sql;

    public function __construct($sql)
    {
        $this->sql = $sql;
    }

    public function execute($params = [])
    {
        return true;
    }

    public function fetchColumn()
    {
        if (stripos($this->sql, 'information_schema.COLUMNS') !== false) {
            return 1;
        }
        return false;
    }

    public function fetch($mode = null)
    {
        return false;
    }

    public function fetchAll($mode = null)
    {
        return [];
    }
}

class VirtualServicesFakePdo
{
    public function exec($sql)
    {
        return true;
    }

    public function prepare($sql)
    {
        return new VirtualServicesFakeStatement($sql);
    }

    public function query($sql)
    {
        return new VirtualServicesFakeStatement($sql);
    }

    public function inTransaction()
    {
        return false;
    }
}

$sentMessages = [];
$editedMessages = [];

function telegram($method, $data = [])
{
    return ['ok' => true, 'result' => true];
}

function sendmessage($chatId, $text, $keyboard, $parseMode)
{
    global $sentMessages;
    $sentMessages[] = compact('chatId', 'text', 'keyboard', 'parseMode');
    if (is_string($keyboard) && strpos($keyboard, '"style"') !== false) {
        return ['ok' => false, 'description' => 'Unsupported inline keyboard style'];
    }
    return ['ok' => true, 'result' => ['message_id' => count($sentMessages)]];
}

function Editmessagetext($chatId, $messageId, $text, $keyboard, $parseMode = 'HTML')
{
    global $editedMessages;
    $editedMessages[] = compact('chatId', 'messageId', 'text', 'keyboard', 'parseMode');
    if (is_string($keyboard) && strpos($keyboard, '"style"') !== false) {
        return ['ok' => false, 'description' => 'Unsupported inline keyboard style'];
    }
    return ['ok' => true, 'result' => true];
}

function step($state, $userId)
{
    return true;
}

function update($table, $field, $value, $whereField, $whereValue)
{
    return true;
}

function deletemessage($chatId, $messageId)
{
    return true;
}

require dirname(__DIR__) . '/telegram_products.php';
require dirname(__DIR__) . '/telegram_products_admin.php';

$pdo = new VirtualServicesFakePdo();
$from_id = 10;
$message_id = 1;
$callback_query_id = 0;
$datain = '';
$text = 'خدمات مجازی';
$user = ['step' => 'home', 'agent' => 'f', 'Processing_value' => ''];
$setting = [];
$admin_ids = [1];
$datatextbot = ['text_virtual_services' => 'خدمات مجازی'];
$keyboard = '{}';

if (telegramProductsHandleRequest() !== true) {
    throw new RuntimeException('User virtual-services request was not handled.');
}
if (count($sentMessages) !== 2) {
    throw new RuntimeException('Styled keyboard fallback was not executed.');
}
if (strpos((string) $sentMessages[1]['keyboard'], '"style"') !== false) {
    throw new RuntimeException('Fallback keyboard still contains unsupported style fields.');
}

$from_id = 1;
$message_id = 20;
$callback_query_id = 'callback-test';
$datain = 'vsa_products';
$text = '';
$user = ['step' => 'home', 'agent' => 'f', 'Processing_value' => ''];
$textbotlang = ['Admin' => ['backadmin' => 'بازگشت', 'backmenu' => 'منوی اصلی']];
$keyboardadmin = '{}';

if (telegramProductsAdminPanelHandleRequest() !== true) {
    throw new RuntimeException('Admin products callback was not handled.');
}
if (count($editedMessages) < 1 || strpos($editedMessages[0]['text'], 'محصولات خدمات مجازی') === false) {
    throw new RuntimeException('Admin products page was not rendered.');
}

$datain = 'vsa_categories';
if (telegramProductsAdminPanelHandleRequest() !== true) {
    throw new RuntimeException('Admin categories callback was not handled.');
}
$lastEdit = $editedMessages[count($editedMessages) - 1] ?? [];
if (strpos((string) ($lastEdit['text'] ?? ''), 'دسته‌بندی خدمات مجازی') === false) {
    throw new RuntimeException('Admin categories page was not rendered.');
}

echo "virtual services smoke test passed\n";
