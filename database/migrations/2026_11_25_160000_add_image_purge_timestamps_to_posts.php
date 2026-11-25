<?php

use App\Enums\PostStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('status');
            $table->timestamp('images_purged_at')->nullable()->after('closed_at');
            $table->index(['status', 'closed_at']);
        });

        DB::table('posts')
            ->whereIn('status', [
                PostStatus::Closed->value,
                PostStatus::Withdrawn->value,
                PostStatus::Rejected->value,
            ])
            ->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['status', 'closed_at']);
            $table->dropColumn(['closed_at', 'images_purged_at']);
        });
    }
};
