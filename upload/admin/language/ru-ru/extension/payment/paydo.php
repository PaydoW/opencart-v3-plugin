<?php

$_['heading_title'] = 'Paydo';
$_['heading_invoices'] = 'Инвойсы PayDo';

$_['text_paydo'] = '<a href="https://paydo.com" target="_blank"><img style="max-width:150px;" src="view/image/payment/paydo.png"></a>';
$_['text_extension'] = 'Расширения';
$_['text_success'] = 'Настройки успешно изменены!';
$_['text_edit'] = 'Редактирование';
$_['text_no_methods'] = 'Методы ещё не загружены. Укажите Project ID и JWT-токен, затем выполните синхронизацию.';
$_['text_syncing'] = 'Загрузка способов оплаты...';
$_['text_sync_success'] = 'Загружено способов оплаты: %d. Выберите нужные методы и сохраните настройки.';
$_['text_no_results'] = 'Записи по инвойсам отсутствуют.';

$_['entry_public_id'] = 'Публичный ключ';
$_['entry_secret_key'] = 'Секретный ключ';
$_['entry_methods_mode'] = 'Выбор способов оплаты';
$_['entry_hosted_page'] = 'Общая платёжная страница PayDo';
$_['entry_project_id'] = 'Project ID';
$_['entry_jwt_token'] = 'JWT-токен';
$_['entry_sync_methods'] = 'Синхронизация способов оплаты';
$_['entry_enabled_methods'] = 'Разрешённые способы PayDo';
$_['entry_search_method'] = 'Поиск способа оплаты...';
$_['entry_order_status_wait'] = 'Статус заказа, ожидающего оплату';
$_['entry_order_status_success'] = 'Статус заказа при успешной оплате';
$_['entry_order_status_error'] = 'Статус заказа при неудачной оплате';
$_['entry_status'] = 'Статус';
$_['entry_sort_order'] = 'Порядок сортировки';
$_['button_sync_methods'] = 'Синхронизировать с PayDo';
$_['button_invoices'] = 'Инвойсы';

$_['help_public_id'] = 'Данный параметр можно получить в личном кабинете Paydo, в параметрах проекта';
$_['help_secret_key'] = 'Данный параметр можно получить в личном кабинете Paydo, в параметрах проекта';
$_['help_methods_mode'] = 'Разрешить покупателю выбрать на checkout один из включённых способов оплаты PayDo.';
$_['help_hosted_page'] = 'Показывать общую Hosted Page PayDo со всеми доступными покупателю методами. Для этой опции paymentMethod не передаётся.';
$_['help_project_id'] = 'Используется для получения доступных способов оплаты из PayDo.';
$_['help_jwt_token'] = 'Bearer-токен, который используется только для синхронизации способов оплаты через API PayDo.';
$_['help_sync_methods'] = 'Используются текущие значения Project ID и JWT-токена; предварительно сохранять их не требуется.';
$_['help_enabled_methods'] = 'На checkout будут доступны только отмеченные способы. После синхронизации способы не включаются автоматически.';

$_['error_permission'] = 'У Вас нет прав для управления данным модулем!';
$_['error_public_id'] = 'Поле обязательно для заполнения';
$_['error_secret_key'] = 'Поле обязательно для заполнения';
$_['error_enabled_methods'] = 'Включите общую платёжную страницу PayDo или выберите хотя бы один отдельный метод.';
$_['error_sync_request'] = 'Не удалось синхронизировать способы оплаты. Попробуйте ещё раз.';
$_['error_sync_credentials'] = 'Перед синхронизацией укажите Project ID и JWT-токен.';
$_['error_sync_transport'] = 'Не удалось подключиться к PayDo. Попробуйте ещё раз.';
$_['error_sync_api'] = 'API PayDo вернул некорректный ответ (HTTP %d).';
$_['error_sync_auth'] = 'PayDo отклонил Project ID или JWT-токен (HTTP %d).';
$_['error_sync_not_found'] = 'Проект PayDo или endpoint синхронизации не найден (HTTP %d).';
$_['error_sync_validation'] = 'PayDo отклонил запрос синхронизации (HTTP %d).';
$_['error_sync_server'] = 'PayDo временно недоступен (HTTP %d). Попробуйте позже.';
$_['error_sync_empty'] = 'PayDo не вернул ни одного включённого способа оплаты.';

$_['column_order_id'] = 'ID заказа';
$_['column_invoice_id'] = 'ID инвойса';
$_['column_payment_method'] = 'Вариант оплаты';
$_['column_txid'] = 'ID транзакции';
$_['column_amount'] = 'Сумма';
$_['column_currency'] = 'Валюта';
$_['column_invoice_status'] = 'Статус инвойса';
$_['column_transaction_state'] = 'Статус транзакции';
$_['column_retry_count'] = 'Повторы';
$_['column_last_error'] = 'Последняя запись проверки';
$_['column_date_added'] = 'Создан';
$_['column_date_modified'] = 'Обновлён';
