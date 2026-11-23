<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_batch_results', function (Blueprint $table) {
            $table->string('batch_id');
            $table->char('job_id_hash', 32);
            $table->longText('retry_id')->nullable();
            $table->primary(['batch_id', 'job_id_hash']);

            if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
                $table->index('batch_id')->whereNotNull('retry_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_batch_results');
    }
};
