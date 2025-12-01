<?php
// Вставьте сюда токен бота и ID чата
$botToken = "7869572806:AAFMqgkrodvf6yhhKrOH6frSI_d4-7P2AZY";
$chatId = "@step12site_bot"; // Замените на ваш chat_id или id группы

// Получаем данные из запроса
$data = json_decode(file_get_contents("php://input"), true);
$name = $data['name'];
$email = $data['email'];

// Создаем текст сообщения
$message = "Новое сообщение с сайта:\nИмя: $name\nEmail: $email";

// URL для отправки запроса в Telegram API
$sendToTelegramUrl = "https://api.telegram.org/bot{$botToken}/getUpdates";

$response = file_get_contents($sendToTelegramUrl . "?chat_id={$chatId}&text=" . urlencode($message));

if ($response) {
    echo "Сообщение отправлено!";
} else {
    echo "Ошибка отправки.";
}
?>
