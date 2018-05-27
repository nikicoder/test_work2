<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use App\ListsData;

use Exception;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;

class ListController extends Controller
{
    protected $ldm;

    public function __construct()
    {
        $this->ldm = new ListsData;
    }

    public function getListData($id)
    {
        // аналогично ListsController

        $members_local = $this->ldm->getLocalListMembers($id);

        if(count($members_local) <= 0) {
            try {
                $response = $this->getListDataFromServer($id);
            } catch(Exception $e) {
                return response(json_encode(['errors' => [$e->getMessage()]]), 400);
            }

            // перезагружаем локальные данные
            $this->ldm->reloadLocalListsMembers($response, $id);
        } else {
            $response = $members_local;
        }

        return response()->json($response);
    }

    public function addListMember(Request $request, $list_id)
    {
        $member_data = [
            'email_address' => $request->input('email_address'),
            'f_name'        => $request->input('f_name'),
            'l_name'        => $request->input('l_name')
        ];

        $validate_result = $this->validateListData($member_data);

        if(!$validate_result['status']) {
            return response(json_encode(['errors' => $validate_result['messages']]), 400);
        }

        // форматируем данные под формат внешнего API и отправляем на сервер
        try {
            $response = $this->addListMemberOnServer($this->formatSendData($member_data), $list_id);
        } catch(Exception $e) {
            return response(json_encode(['errors' => [$e->getMessage()]]), 400);
        }

        // поскольку ответ был получен -- сохраняем локально добавленный список
        $this->ldm->addLocalListMember($response, $list_id);

        return response()->json($response);
    }

    public function updateMember(Request $request, $list_id, $member_id)
    {
        $member_data = [
            'email_address' => $request->input('email_address'),
            'f_name'        => $request->input('f_name'),
            'l_name'        => $request->input('l_name')
        ];

        $validate_result = $this->validateListData($member_data);

        if(!$validate_result['status']) {
            return response(json_encode(['errors' => $validate_result['messages']]), 400);
        }

        try {
            $response = $this->updateMemberOnServer($this->formatSendData($member_data), $list_id, $member_id);
        } catch(Exception $e) {
            return response(json_encode(['errors' => [$e->getMessage()]]), 400);
        }

        // аналогично, ответ считается успешным по этому обновляем список
        $this->ldm->updateLocalListsMember($response, $list_id, $member_id);

        return response()->json($response);
    }

    public function deleteMember($list_id, $member_id)
    {
        try {
            $this->deleteMemberOnServer($list_id, $member_id);
        } catch(Exception $e) {
            return response(json_encode(['errors' => [$e->getMessage()]]), 400);
        }

        // удаляем локально
        $this->ldm->deleteLocalListsMember($list_id, $member_id);

        return response()->json([]);
    }

    /**
    * Получение списков с сервера
    * 
    * @return object
    */

    private function getListDataFromServer($id)
    {
        // для запросов используется Guzzle
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists/' . $id . '/members';
        
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
            $return = $this->parseListsDataFromServer($data);
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

    private function parseListsDataFromServer($data)
    {
        $return = [];

        // минимализация отдаваемых данных

        $decoded = json_decode($data);
        if(!$decoded) {
            throw new Exception('Invalid json data from server');
        }

        foreach($decoded->members as $m) {
            $return[] = [
                'id'            => (string)$m->id,
                'email'         => (string)$m->email_address,
                'first_name'    => (string)$m->merge_fields->FNAME,
                'last_name'     => (string)$m->merge_fields->LNAME
            ];
        }
        return $return;
    }

    /**
    * Валидатор данных для добавления/обновления пользователя
    *
    * @param array $data
    * @return array
    */
    private function validateListData($data) 
    {
        $return = ['status' => false, 'messages' => []];
        $validator = Validator::make(
            [
                'email_address' => $data['email_address'],
            ],
            [
                'email_address' => 'required|email'
            ]
        );
        
        if($validator->passes()) {
            $return['status'] = true;
        } else {
            $return['messages'] = $validator->messages();
        }

        return $return;
    }

    // аналогично: форматирование данных под запрос
    // минимальная версия: email + имя
    private function formatSendData($data)
    {
        $data = [
            'email_address' => $data['email_address'],
            'status'        => 'subscribed',
            'merge_fields'  => [
                "FNAME"     => $data['f_name'],
                "LNAME"     => $data['l_name']
            ]
        ];

        return $data;
    }

    /**
    * Добавление данных на сервер
    *
    * @param array $data
    * @return array
    */
    private function addListMemberOnServer($data, $list_id)
    {
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists/' . $list_id . '/members';

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

        $return = [
            'id'            => $data['id'], 
            'email'         => $data['email_address'], 
            'first_name'    => $data['merge_fields']['FNAME'],
            'last_name'     => $data['merge_fields']['LNAME']
        ];

        return $return;
    }

    /**
    * Обновление данных на серверу
    *
    * @param array $data
    * @return array
    */
    private function updateMemberOnServer($data, $list_id, $member_id)
    {
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists/' . $list_id . '/members/' . $member_id;

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

        $return = [
            'id'            => $data['id'], 
            'email'         => $data['email_address'], 
            'first_name'    => $data['merge_fields']['FNAME'],
            'last_name'     => $data['merge_fields']['LNAME']
        ];

        return $return;
    }

    /**
    * Удаление на сервере
    *
    * @param string $id
    * @return bool
    */
    private function deleteMemberOnServer($list_id, $member_id)
    {
        $client = new Client(); 
        $ulr = env('MAILCHIMP_URL') . 'lists/' . $list_id . '/members/' . $member_id;

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
