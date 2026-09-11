<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;

class DynamicContentIsShown extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('url')->nullable();
            $table->string('name')->nullable();
        });

        DB::table('blogs')->insert([
            ['url' => 'www.janedoe.com'],
            ['url' => 'www.johndoe.com'],
        ]);

        DB::statement("ALTER TABLE 'pseudo_table_name' MODIFY 'column_name' VARCHAR(191)");

        $tablesList = DB::withoutPretending(function (): Collection {
            return DB::table('people')->get();
        });

        $tablesList->each(function (stdClass $person, int $key): void {
            DB::table('blogs')->where('blog_id', '=', $person->blog_id)->insert([
                'id' => $key + 1,
                'name' => "{$person->name} Blog",
            ]);
        });
    }
}
