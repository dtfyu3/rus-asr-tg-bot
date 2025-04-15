<?php
$input = json_decode(file_get_contents('php://input'), true);
$secretToken = getenv("SECRET_TOKEN");
$headers = getallheaders();
if (!isset($headers['X-Telegram-Bot-Api-Secret-Token']) && $headers['X-Telegram-Bot-Api-Secret-Token'] !== $secretToken) {
    http_response_code(403);
    die('Access denied');
}



?>