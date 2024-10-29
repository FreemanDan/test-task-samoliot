<?php
ini_set('display_errors', '1');
ini_set('error_reporting', E_ALL);

require_once 'lib/crest/crest.php'; // Путь к crest.php';
$cacheFile = 'cache/cache_field_values.json';  // Файл для кэширования значений полей


// Функция для получения значений полей температуры лидов из кэша или API
// Кэш необходим для повышения производительности и снижения нагрузки на сервер
// и актуализируется каждые 24 часа

function getFieldValues()
{
    global $webhookUrl, $cacheFile;

    // Проверяем, если кэш-файл актуален (меньше суток с последнего обновления)
    if (time() - filemtime($cacheFile) < 86400) {
        // Читаем значения из кэша
        $fieldValues = json_decode(file_get_contents($cacheFile), true);
    } else {
        // Если кэш устарел, запрашиваем значения из API
        $response = CRest::call('crm.lead.fields', []);
        $fields = $response;

        if (isset($fields['result']['UF_CRM_1612963342082']['items'])) {
            $fieldValues = [];
            foreach ($fields['result']['UF_CRM_1612963342082']['items'] as $item) {
                $fieldValues[$item['ID']] = $item['VALUE'];
            }

            // Сохраняем значения в кэш, проверяя успешность записи
            if (file_put_contents($cacheFile, json_encode($fieldValues)) === false) {
                throw new Exception('Не удалось записать кэш-файл.');
            }
        } else {
            throw new Exception('Не удалось получить значения поля из API');
        }
    }

    return $fieldValues;
}

$fieldValues = getFieldValues();


// Параметры для запроса списка лидов с постраничной навигацией
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;  // Количество лидов на странице
$start = ($page - 1) * $limit;

$result = CRest::call('crm.lead.list', [
    'order' => ['ID' => 'DESC'],
    'filter' => ['!UF_CRM_1612963342082' => false],  // Фильтр по непустому значению температуры клиента
    'select' => ['ID', 'TITLE', 'ASSIGNED_BY_ID', 'UF_CRM_1612963342082'],
    'start' => $start,
]);

//print_r($result);

// Проверяем на ошибки
if (isset($result['error'])) {
    echo "Ошибка: " . $result['error_description'];
    exit;
}

$leads = $result['result'];
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <title>Список лидов</title>
    <!-- Подключение Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        /* Стили для фона в зависимости от температуры клиента */
        .bg-hot {
            background-color: #ffdddd;
            /* Светло-красный фон для горячих */
        }

        .bg-warm {
            background-color: #fff4cc;
            /* Светло-желтый фон для теплых */
        }

        .bg-cold {
            background-color: #d6eaff;
            /* Светло-голубой фон для холодных */
        }

        /* Стили для сообщений */
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Скрываем индикатор загрузки по умолчанию */
        #loadingIndicator {
            display: none;
        }
    </style>
</head>

<body class="container mt-4">

    <h1 class="mb-4">Список лидов</h1>

    <table class="table table-bordered">
        <thead class="thead-light">
            <tr>
                <th>Название лида</th>
                <th>ID Ответственного</th>
                <th>Температура клиента</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($leads as $lead):
                // Устанавливаем класс стиля в зависимости от значения температуры
                $temperatureClass = '';
                $temperatureValue = $fieldValues[$lead['UF_CRM_1612963342082']] ?? 'Не установлено';

                switch ($temperatureValue) {
                    case 'горячий':
                        $temperatureClass = 'bg-hot';
                        break;
                    case 'теплый':
                        $temperatureClass = 'bg-warm';
                        break;
                    case 'холодный':
                        $temperatureClass = 'bg-cold';
                        break;
                }
            ?>
                <tr>
                    <td><?= htmlspecialchars($lead['TITLE']) ?></td>
                    <td><?= htmlspecialchars($lead['ASSIGNED_BY_ID']) ?></td>
                    <td class="<?= $temperatureClass ?>"><?= htmlspecialchars(($fieldValues[$lead['UF_CRM_1612963342082']] ?? 'Неизвестно')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Постраничная навигация -->
    <div class="d-flex justify-content-between">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>" class="btn btn-primary">Назад</a>
        <?php endif; ?>
        <a href="?page=<?= $page + 1 ?>" class="btn btn-primary">Вперед</a>
    </div>

    <!-- Кнопка для открытия модального окна -->
    <button class="btn btn-success mt-4" data-toggle="modal" data-target="#addLeadModal">Добавить лид</button>

    <!-- Модальное окно для добавления лида -->
    <div class="modal fade" id="addLeadModal" tabindex="-1" role="dialog" aria-labelledby="addLeadModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addLeadModalLabel">Добавить новый лид</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Сообщение об успешном или ошибочном завершении -->
                    <div id="responseMessage" class="alert" role="alert" style="display: none;"></div>

                    <!-- Индикатор загрузки -->
                    <div id="loadingIndicator" class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Загрузка...</span>
                        </div>
                        <p>Пожалуйста, подождите...</p>
                    </div>
                    <form id="addLeadForm">
                        <div class="form-group">
                            <label for="leadTitle">Название лида</label>
                            <input type="text" class="form-control" id="leadTitle" required>
                        </div>
                        <div class="form-group">
                            <label for="leadAssignedBy">ID Ответственного</label>
                            <input type="number" class="form-control" id="leadAssignedBy" required>
                        </div>
                        <div class="form-group">
                            <label for="leadStatus">Температура клиента</label>
                            <select class="form-control" id="leadStatus" required>
                                <?php foreach ($fieldValues as $id => $value): ?>
                                    <option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-primary" onclick="submitLeadForm()">Создать лид</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Подключение Bootstrap и jQuery -->
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function submitLeadForm() {
            const title = document.getElementById('leadTitle').value;
            const assignedBy = document.getElementById('leadAssignedBy').value;
            const status = document.getElementById('leadStatus').value;

            if (!title || !assignedBy || !status) {
                alert('Заполните все поля');
                return;
            }

            const leadData = {
                fields: {
                    TITLE: title,
                    ASSIGNED_BY_ID: parseInt(assignedBy),
                    UF_CRM_1612963342082: status
                }
            };

            // Показываем индикатор загрузки
            document.getElementById('loadingIndicator').style.display = 'block';
            document.getElementById('responseMessage').style.display = 'none';

            // Отправляем данные на промежуточный PHP-скрипт
            fetch('add_lead.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(leadData)
                })
                .then(response => response.json())
                .then(data => {

                    // Скрываем индикатор загрузки
                    document.getElementById('loadingIndicator').style.display = 'none';

                    // Отображаем сообщение об успехе или ошибке
                    const responseMessage = document.getElementById('responseMessage');
                    if (data.result) {
                        responseMessage.className = 'alert alert-success';
                        responseMessage.textContent = 'Лид успешно создан с ID: ' + data.result;
                        responseMessage.style.display = 'block';
                        document.getElementById('addLeadForm').reset(); // Очищаем форму
                    } else {
                        responseMessage.className = 'alert alert-danger';
                        responseMessage.textContent = 'Ошибка: ' + (data.error_description || 'Неизвестная ошибка');
                        responseMessage.style.display = 'block';
                    }
                })
                .catch(error => {
                    // Скрываем индикатор загрузки и отображаем сообщение об ошибке
                    document.getElementById('loadingIndicator').style.display = 'none';
                    const responseMessage = document.getElementById('responseMessage');
                    responseMessage.className = 'alert alert-danger';
                    responseMessage.textContent = 'Ошибка: ' + error.message;
                    responseMessage.style.display = 'block';
                });
        }
    </script>

</body>

</html>