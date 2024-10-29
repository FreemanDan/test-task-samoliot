<?php
require_once 'lib/crest/crest.php'; // Путь к crest.php';

// Получение данных из POST-запроса
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['fields'])) {
    // Подготовка запроса к Битрикс24 для создания лида
    $leadData = [
        'fields' => $data['fields']
    ];

    // Отправляем данные в Bitrix24 c помощью CRest
    $response = CRest::call('crm.lead.add', $leadData);

    // Ответ клиенту
    echo json_encode($response);
} else {
    // Возвращаем ошибку, если данных нет
    echo json_encode(['error' => 'Неверные данные']);
}
?>
