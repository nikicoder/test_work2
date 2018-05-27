<?php

namespace App;

use Illuminate\Support\Facades\Storage;

// данная модель всего лишь эмулирует работу с БД
// по этому имеет минималистичный дизайн
class ListsData
{
    protected $lists_data = [];

    public function __construct()
    {
        // поскольку у нас БД нет, точнее она как бы есть
        // но в рамках тестового задания упростим до уровня чтения 
        // сериализованных данных из файла
        if(!Storage::disk('local')->exists('lists_data.json')) {
            Storage::disk('local')->put('lists_data.json', '[]');
        } else {
            $data = Storage::disk('local')->get('lists_data.json');
            $this->lists_data = json_decode($data, true);
        }
    }

    public function getLocalListMembers($list_id)
    {
        if(array_key_exists($list_id, $this->lists_data)) {
            return array_values($this->lists_data[$list_id]);
        } else {
            return [];
        }
    }

    public function reloadLocalListsMembers($data, $list_id)
    {
        $this->lists_data[$list_id] = [];
        foreach($data as $d) {
            $this->lists_data[$list_id][$d['id']] = $d;
        }
        Storage::disk('local')->put('lists_data.json', json_encode($this->lists_data));
        return true;
    }
    public function addLocalListMember($data, $list_id)
    {
        $this->lists_data[$list_id][$data['id']] = $data;
        Storage::disk('local')->put('lists_data.json', json_encode($this->lists_data));
        return true;
    }

    // тут есть момент: member_id МОЖЕТ изменится если был изменен email
    // поэтому необходимо делать unset, а после записывать полученные данные
    public function updateLocalListsMember($data, $list_id, $member_id)
    {
        unset($this->lists_data[$list_id][$member_id]);
        $this->lists_data[$list_id][$data['id']] = $data;
        Storage::disk('local')->put('lists_data.json', json_encode($this->lists_data));
        return true;
    }

    public function deleteLocalListsMember($list_id, $member_id)
    {
        unset($this->lists_data[$list_id][$member_id]);
        Storage::disk('local')->put('lists_data.json', json_encode($this->lists_data));
        return true;
    }
}
