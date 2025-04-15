<?php
$input = json_decode(file_get_contents('php://input'), true);
// $SECRET_TOKEN = getenv("SECRET_TOKEN");
// $BOT_TOKEN = getenv("BOT_TOKEN");
// $ASR_ENDPOINT = getenv("ASR_ENDPOINT");
define('BOT_TOKEN', getenv("BOT_TOKEN"));
define('ASR_ENDPOINT', getenv("ASR_ENDPOINT") . "/transcribe");
define('TEMP_DIR', __DIR__ . '/tmp_audio');
define('MAX_FILE_SIZE', 16 * 1024 * 1024);

$headers = getallheaders();

if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
}

if (!isset($headers['X-Telegram-Bot-Api-Secret-Token']) && $headers['X-Telegram-Bot-Api-Secret-Token'] !== $SECRET_TOKEN) {
    http_response_code(403);
    die('Access denied');
}
if (!$input || !isset($input['message'])) {
    http_response_code(400);
    exit;
}
$message = $input['message'];
$chat_id = $message['chat']['id'];

try {
    if (isset($message['voice'])) {
        $result = process_audio($message['voice'], 'voice');
    } elseif (isset($message['audio'])) {
        $result = process_audio($message['audio'], 'audio');
    } elseif (isset($message['document']) && strpos($message['document']['mime_type'], 'audio/') === 0) {
        $result = process_audio($message['document'], 'document');
    } else {
        send_message($chat_id, "Пожалуйста, отправьте голосовое сообщение или аудиофайл (поддерживаются WAV, MP3, OGG)");
        exit;
    }

    if ($result['success']) {
        $text = $result['text'] ?: "Речь не распознана";
        send_message($chat_id, $text);
    } else {
        send_message($chat_id, "Ошибка: " . $result['error']);
    }
} catch (Exception $e) {
    send_message($chat_id, "Произошла ошибка при обработке аудио");
    error_log("Error: " . $e->getMessage());
}

function process_audio($file_info, $type)
{
    $file_id = $file_info['file_id'];
    $file_path = download_file($file_id);

    if (!$file_path) {
        return ['success' => false, 'error' => 'Не удалось загрузить файл'];
    }

    // Конвертируем в WAV если нужно
    $wav_path = convert_to_wav($file_path);
    if (!$wav_path) {
        unlink($file_path);
        return ['success' => false, 'error' => 'Ошибка конвертации в WAV'];
    }

    // Отправляем на ASR сервер
    $text = send_to_asr($wav_path);

    // Удаляем временные файлы
    unlink($file_path);
    unlink($wav_path);

    return ['success' => true, 'text' => $text];
}
function download_file($file_id)
{
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/getFile?file_id=" . $file_id;
    $response = json_decode(file_get_contents($url), true);

    if (!$response || !$response['ok']) {
        return false;
    }

    $file_path = $response['result']['file_path'];
    $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path;
    $local_path = TEMP_DIR . '/' . uniqid() . '_' . basename($file_path);

    $file_content = file_get_contents($file_url);
    if (strlen($file_content) > MAX_FILE_SIZE) {
        return false;
    }

    file_put_contents($local_path, $file_content);
    return $local_path;
}

function convert_to_wav($input_path)
{
    $output_path = $input_path . '.wav';

    $cmd = "ffmpeg -i {$input_path} -ar 16000 -ac 1 -sample_fmt s16 -y {$output_path} 2>&1";
    exec($cmd, $output, $return_code);

    if ($return_code !== 0 || !file_exists($output_path)) {
        return false;
    }

    return $output_path;
}
function send_to_asr($audio_path)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, ASR_ENDPOINT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $post_fields = [
        'audio' => new CURLFile($audio_path, 'audio/wav', 'audio.wav')
    ];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("ASR Error: " . $response);
        return false;
    }

    $data = json_decode($response, true);
    return $data['text'] ?? false;
}
function send_message($chat_id, $text)
{
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => substr($text, 0, 4096) // Ограничение Telegram
    ];

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($data)
        ]
    ];

    file_get_contents($url, false, stream_context_create($options));
}
if (rand(1, 10) === 1) {
    foreach (glob(TEMP_DIR . '/*') as $file) {
        if (filemtime($file) < time() - 3600) { // Удаляем файлы старше 1 часа
            unlink($file);
        }
    }
}
?>
