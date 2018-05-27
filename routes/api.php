<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

/* Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
}); */

Route::get('lists', 'ListsController@getLists');
Route::post('lists', 'ListsController@addList');
Route::patch('lists/{id}', 'ListsController@updateList');
Route::delete('lists/{id}', 'ListsController@deleteList');

Route::get('list/{id}', 'ListController@getListData');
Route::post('list/{id}', 'ListController@addListMember');
Route::patch('list/{id}/{member_id}', 'ListController@updateMember');
Route::delete('list/{id}/{member_id}', 'ListController@deleteMember');