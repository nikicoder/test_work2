<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use App\Lists;

use Exception;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;

class ListsController extends Controller
{
    protected $lm;

    public function __construct()
    {
        $this->lm = new Lists;
    }

    public function getLists()
    {
        // первоначально попытка достать из локальной БД
        // если там они отсутствуют -- перезаписать данными с сервера
        // ситуация когда локальные списки и списки на сервере могут отличаться
        // считаю выходит за рамки тестового задания 
        // т.к. это требует дополнительной логики "инвалидации кеша"

        $lists_local = $this->lm->getLocalListsData();

        if(count($lists_local) <= 0) {
            try {
                $response = $this->getListsFromServer();
            } catch(Exception $e) {
                return response(json_encode(['errors' => [$e->getMessage()]]), 400);
            }

            // перезагружаем локальные данные
            $this->lm->reloadLocalListsItem($response);
        } else {
            $response = $lists_local;
        }

        return response()->json($response);
    }

    public function addList(Request $request)
    {
        $list_data = [
            'list_name'     => $request->input('list_name'),
            'from_name'     => $request->input('from_name'),
            'from_email'    => $request->input('from_email'),
            'subject'       => $request->input('subject'),
        ];

        $validate_result = $this->validateListData($list_data);

        if(!$validate_result['status']) {
            return response(json_encode(['errors' => $validate_result['messages']]), 400);
        }

        // форматируем данные под формат внешнего API и отправляем на сервер
        try {
            $response = $this->addListOnServer($this->formatSendData($list_data));
        } catch(Exception $e) {
            return response(json_encode(['errors' => [$e->getMessage()]]), 400);
        }

        // поскольку ответ был получен -- сохраняем локально добавленный список
        $this->lm->addLocalListsItem($response);

        return response()->json($response);
    }

    public function updateList(Request $request, $id)
    {
        $list_data = [
            'list_name'     => $request->input('list_name'),
            'from_name'     => $request->input('from_name'),
            'from_email'    => $request->input('from_email'),
            'subject'       => $request->input('subject'),
        ];

        $validate_result = $this->validateListData($list_data);

        if(!$validate_result['status']) {
            return response(json_encode(['errors' => $validate_result['messages']]), 400);
        }

        try {
            $response = $this->updateListOnServer($this->formatSendData($list_data), $id);
        } catch(Exception $e) {
            return response(json_encode(['errors' => [$e->getMessage()]]), 400);
        }

        // аналогично, ответ считается успешным по этому обновляем список
        $this->lm->updateLocalListsItem($response, $id);

        return response()->json($response);
    }

    public function deleteList($id)
    {
        try {
            $this->deleteListOnServer($id);
        } catch(Exception $e) {
            return response(json_encode(['errors' => [$e->getMessage()]]), 400);
        }

        // удаляем локально
        $this->lm->deleteLocalListsItem($id);

        return response()->json([]);
    }

    /**
    * Получение списков с сервера
    * 
    * @return object
    */

    private function getListsFromServer()
    {
        // для запросов используется Guzzle
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists';
        
        // запрос оборачивается в try/catch чтобы в случае невыполнения запроса
        // имелась возможность выбросить исключение и обработать по цепочке далее
        try {
            $response = $client->request('GET', $ulr, [
                'headers' => [
                    // авторизация через ключ приложения
                    'Authorization' => 'apikey ' . env('MAILCHIMP_API_KEY')
                ]
            ]);
        } catch(GuzzleException $e) {
            // детальный разбор исключений выходит за рамки тестового задания
            // по этому "упрощаю" работу таким образом
            throw new Exception('Server request error');
        }

        // данные потребуют последующей обработки
        $data = $response->getBody()->getContents();

        try {
            $return = $this->parseListsFromServer($data);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return $return;
    }

    /**
    * Парсинг полученных с сервера данных
    *
    * @param string $data
    * @return array
    */

    private function parseListsFromServer($data)
    {
        $return = [];

        // предположим что наше API не требует избыточной информации, которую предоставляет сервер
        // достаточно к примеру отдать id и наименование списка

        $decoded = json_decode($data);
        if(!$decoded) {
            throw new Exception('Invalid json data from server');
        }

        foreach($decoded->lists as $l) {
            $return[] = [
                'id'    => (string)$l->id,
                'name'  => (string)$l->name
            ];
        }
        return $return;
    }

    /**
    * Валидатор данных для добавления/обновления списка
    *
    * @param array $data
    * @return array
    */
    private function validateListData($data) 
    {
        $return = ['status' => false, 'messages' => []];
        // API получает эти данные (остальные берутся из настроек, 
        // валидация их выходит за пределы данного задания)
        $validator = Validator::make(
            [
                'list_name'     => $data['list_name'],
                'from_name'     => $data['from_name'],
                'from_email'    => $data['from_email'],
                'subject'       => $data['subject'],
            ],
            [
                'list_name' => 'required',
                'from_name' => 'required',
                'from_email' => 'required|email',
                'subject' => 'required',
            ]
        );
        
        if($validator->passes()) {
            $return['status'] = true;
        } else {
            $return['messages'] = $validator->messages();
        }

        return $return;
    }

    // данная функция "упрощенный вариант" компиляции данных
    // предположим что часть данных уже есть где-то в БД приложения
    // такие как имя компании, и т.д., а часть прилетает в запросе
    // в итоге данная функция возвращает окончательные данные для отправки на сервер
    private function formatSendData($data)
    {
        $data = [
            'name'      => $data['list_name'],
            'contact'   => [
                'company'   => "my company",
                'address1'  => "some addr",
                'address2'  => "",
                'city'      => "some city",
                'state'     => "",
                'zip'       => "",
                'country'   => "",
                'phone'     => ""
            ],
            'permission_reminder'   => "You are receiving this email because you opted in via our website.",
            'use_archive_bar'       => true,
            'campaign_defaults' => [
                'from_name'     => $data['from_name'],
                'from_email'    => $data['from_email'],
                'subject'       => $data['subject'],
                'language'      => "ru"
            ],
            'notify_on_subscribe'   => "",
            'notify_on_unsubscribe' => "",
            'email_type_option'     => false,
            'visibility'            => "pub",
            'double_optin'          => false
        ];

        return $data;
    }

    /**
    * Добавление данных на сервер
    *
    * @param array $data
    * @return array
    */
    private function addListOnServer($data)
    {
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists';

        try {
            $response = $client->request('POST', $ulr, [
                'headers' => [
                    'Authorization' => 'apikey ' . env('MAILCHIMP_API_KEY')
                ],
                'body' => json_encode($data)
            ]);
        } catch(GuzzleException $e) {
            throw new Exception('Server request error');
        }

        $data = $response->getBody()->getContents();

        // в отдельную функцию парсинг данных выносить не стану,
        // возвращаю те же id и name
        $data = json_decode($data, true);

        return ['id' => $data['id'], 'name' => $data['name']];
    }

    /**
    * Обновление данных на серверу
    *
    * @param array $data
    * @return array
    */
    private function updateListOnServer($data, $id)
    {
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists/' . $id;

        try {
            $response = $client->request('PATCH', $ulr, [
                'headers' => [
                    'Authorization' => 'apikey ' . env('MAILCHIMP_API_KEY')
                ],
                'body' => json_encode($data)
            ]);
        } catch(GuzzleException $e) {
            throw new Exception('Server request error');
        }

        $data = $response->getBody()->getContents();

        // в отдельную функцию парсинг данных выносить не стану,
        // возвращаю те же id и name
        $data = json_decode($data, true);

        return ['id' => $data['id'], 'name' => $data['name']];
    }

    /**
    * Удаление на сервере
    *
    * @param string $id
    * @return bool
    */
    private function deleteListOnServer($id)
    {
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists/' . $id;

        try {
            $response = $client->request('DELETE', $ulr, [
                'headers' => [
                    'Authorization' => 'apikey ' . env('MAILCHIMP_API_KEY')
                ]
            ]);
        } catch(GuzzleException $e) {
            throw new Exception('Server request error');
        }

        return true;
    }
}
