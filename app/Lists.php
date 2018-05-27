<?php

namespace App;

use Illuminate\Support\Facades\Storage;

// данная модель всего лишь эмулирует работу с БД
// по этому имеет минималистичный дизайн
class Lists
{
    protected $lists = [];

    public function __construct()
    {
        // поскольку у нас БД нет, точнее она как бы есть
        // но в рамках тестового задания упростим до уровня чтения 
        // сериализованных данных из файла
        if(!Storage::disk('local')->exists('lists.json')) {
            Storage::disk('local')->put('lists.json', '[]');
        } else {
            $data = Storage::disk('local')->get('lists.json');
            $this->lists = json_decode($data, true);
        }
    }

    public function getLocalListsData()
    {
        return array_values($this->lists);
    }

    public function reloadLocalListsItem($data)
    {
        $this->lists = [];
        foreach($data as $d) {
            $this->lists[$d['id']] = $d;
        }
        
        Storage::disk('local')->put('lists.json', json_encode($this->lists));
        return true;
    }
    public function addLocalListsItem($data)
    {
        $this->lists[$data['id']] = $data;
        Storage::disk('local')->put('lists.json', json_encode($this->lists));
        return true;
    }

    public function updateLocalListsItem($data, $id)
    {
        $this->lists[$id] = $data;
        Storage::disk('local')->put('lists.json', json_encode($this->lists));
        return true;
    }

    public function deleteLocalListsItem($id)
    {
        unset($this->lists[$id]);
        Storage::disk('local')->put('lists.json', json_encode($this->lists));
        return true;
    }
}
