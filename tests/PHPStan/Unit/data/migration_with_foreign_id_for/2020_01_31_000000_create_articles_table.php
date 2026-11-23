<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Data\MigrationWithForeignIdFor;

use App\MemberWithCustomKey;
use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

class CreateArticlesTable extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            $table->string('uuid');
        });

        Schema::create('articles', static function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('title');
            $table->foreignIdFor(MemberWithCustomKey::class);
            $table->timestamps();
        });
    }
}
