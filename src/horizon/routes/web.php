<?php

declare(strict_types=1);

use Hypervel\Horizon\Http\Middleware\Authenticate;
use Hypervel\Support\Facades\Route;

Route::group('api', function () {
    // Dashboard Routes...
    Route::get('/stats', 'DashboardStatsController@index', ['as' => 'horizon.stats.index']);

    // Workload Routes...
    Route::get('/workload', 'WorkloadController@index', ['as' => 'horizon.workload.index']);

    // Master Supervisor Routes...
    Route::get('/masters', 'MasterSupervisorController@index', ['as' => 'horizon.masters.index']);

    // Monitoring Routes...
    Route::get('/monitoring', 'MonitoringController@index', ['as' => 'horizon.monitoring.index']);
    Route::post('/monitoring', 'MonitoringController@store', ['as' => 'horizon.monitoring.store']);
    Route::get('/monitoring/{tag}', 'MonitoringController@paginate', ['as' => 'horizon.monitoring-tag.paginate']);
    Route::delete('/monitoring/{tag:.*}', 'MonitoringController@destroy', ['as' => 'horizon.monitoring-tag.destroy']);

    // Job Metric Routes...
    Route::get('/metrics/jobs', 'JobMetricsController@index', ['as' => 'horizon.jobs-metrics.index']);
    Route::get('/metrics/jobs/{id}', 'JobMetricsController@show', ['as' => 'horizon.jobs-metrics.show']);

    // Queue Metric Routes...
    Route::get('/metrics/queues', 'QueueMetricsController@index', ['as' => 'horizon.queues-metrics.index']);
    Route::get('/metrics/queues/{id}', 'QueueMetricsController@show', ['as' => 'horizon.queues-metrics.show']);

    // Batches Routes...
    Route::get('/batches', 'BatchesController@index', ['as' => 'horizon.jobs-batches.index']);
    Route::get('/batches/{id}', 'BatchesController@show', ['as' => 'horizon.jobs-batches.show']);
    Route::post('/batches/retry/{id}', 'BatchesController@retry', ['as' => 'horizon.jobs-batches.retry']);

    // Job Routes...
    Route::get('/jobs/pending', 'PendingJobsController@index', ['as' => 'horizon.pending-jobs.index']);
    Route::get('/jobs/completed', 'CompletedJobsController@index', ['as' => 'horizon.completed-jobs.index']);
    Route::get('/jobs/silenced', 'SilencedJobsController@index', ['as' => 'horizon.silenced-jobs.index']);
    Route::get('/jobs/failed', 'FailedJobsController@index', ['as' => 'horizon.failed-jobs.index']);
    Route::get('/jobs/failed/{id}', 'FailedJobsController@show', ['as' => 'horizon.failed-jobs.show']);
    Route::post('/jobs/retry/{id}', 'RetryController@store', ['as' => 'horizon.retry-jobs.show']);
    Route::get('/jobs/{id}', 'JobsController@show', ['as' => 'horizon.jobs.show']);
}, [
    'middleware' => [Authenticate::class],
]);

// Catch-all Route...
Route::get('/[{view:.*}]', 'HomeController@index', [
    'as' => 'horizon.index',
    'middleware' => [Authenticate::class],
]);
