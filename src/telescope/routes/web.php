<?php

declare(strict_types=1);

use Hypervel\Support\Facades\Route;

// Mail entries...
Route::post('/telescope-api/mail', 'MailController@index');
Route::get('/telescope-api/mail/{id}', 'MailController@show');
Route::get('/telescope-api/mail/{id}/preview', 'MailHtmlController@show');
Route::get('/telescope-api/mail/{id}/download', 'MailEmlController@show');

// Exception entries...
Route::post('/telescope-api/exceptions', 'ExceptionController@index');
Route::get('/telescope-api/exceptions/{id}', 'ExceptionController@show');
Route::put('/telescope-api/exceptions/{id}', 'ExceptionController@update');

// Dump entries...
Route::post('/telescope-api/dumps', 'DumpController@index');

// Log entries...
Route::post('/telescope-api/logs', 'LogController@index');
Route::get('/telescope-api/logs/{id}', 'LogController@show');

// Notifications entries...
Route::post('/telescope-api/notifications', 'NotificationsController@index');
Route::get('/telescope-api/notifications/{id}', 'NotificationsController@show');

// Queue entries...
Route::post('/telescope-api/jobs', 'QueueController@index');
Route::get('/telescope-api/jobs/{id}', 'QueueController@show');

// Queue Batches entries...
Route::post('/telescope-api/batches', 'QueueBatchesController@index');
Route::get('/telescope-api/batches/{id}', 'QueueBatchesController@show');

// Events entries...
Route::post('/telescope-api/events', 'EventsController@index');
Route::get('/telescope-api/events/{id}', 'EventsController@show');

// Gates entries...
Route::post('/telescope-api/gates', 'GatesController@index');
Route::get('/telescope-api/gates/{id}', 'GatesController@show');

// Cache entries...
Route::post('/telescope-api/cache', 'CacheController@index');
Route::get('/telescope-api/cache/{id}', 'CacheController@show');

// Queries entries...
Route::post('/telescope-api/queries', 'QueriesController@index');
Route::get('/telescope-api/queries/{id}', 'QueriesController@show');

// Eloquent entries...
Route::post('/telescope-api/models', 'ModelsController@index');
Route::get('/telescope-api/models/{id}', 'ModelsController@show');

// Requests entries...
Route::post('/telescope-api/requests', 'RequestsController@index');
Route::get('/telescope-api/requests/{id}', 'RequestsController@show');

// View entries...
Route::post('/telescope-api/views', 'ViewsController@index');
Route::get('/telescope-api/views/{id}', 'ViewsController@show');

// Artisan Commands entries...
Route::post('/telescope-api/commands', 'CommandsController@index');
Route::get('/telescope-api/commands/{id}', 'CommandsController@show');

// Scheduled Commands entries...
Route::post('/telescope-api/schedule', 'ScheduleController@index');
Route::get('/telescope-api/schedule/{id}', 'ScheduleController@show');

// Redis Commands entries...
Route::post('/telescope-api/redis', 'RedisController@index');
Route::get('/telescope-api/redis/{id}', 'RedisController@show');

// Client Requests entries...
Route::post('/telescope-api/client-requests', 'ClientRequestController@index');
Route::get('/telescope-api/client-requests/{id}', 'ClientRequestController@show');

// Monitored Tags...
Route::get('/telescope-api/monitored-tags', 'MonitoredTagController@index');
Route::post('/telescope-api/monitored-tags/', 'MonitoredTagController@store');
Route::post('/telescope-api/monitored-tags/delete', 'MonitoredTagController@destroy');

// Toggle Recording...
Route::post('/telescope-api/toggle-recording', 'RecordingController@toggle');

// Clear Entries...
Route::delete('/telescope-api/entries', 'EntriesController@destroy');

Route::get('/', 'HomeController@index', ['name' => 'telescope.index']);
Route::get('/{path:.*}', 'HomeController@index');
