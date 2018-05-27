## Тестовое задание

Реализованы методы:

GET: /api/lists получение списков рассылки
POST: /api/lists добавление списка рассылки
PATCH: /api/lists/_list_id_ редактирование списка рассылки
DELETE: /api/lists/_list_id_ удаление списка рассылки

GET: /api/list/_list_id_ долучение списка участников рассылки
POST: /api/list/_list_id_ добавление участника в рассылку
PATCH: /api/list/_list_id_/_member_id_ редактирование участника списка рассылки
DELETE: /api/list/_list_id_/_member_id_ удаление участника списка рассылки

В случае успеха ВСЕ методы возвращают ответ с responseCode 200, в случае ошибки имеется внутри json-payload в ответе массив сообщений об ошибках, отправляемый вместе с responseCode 400, пример:

    {
        "errors": [
            "Server request error"
        ]
    }