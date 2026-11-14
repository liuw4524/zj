<?php

use App\Support\TraceCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('trace_code', 10)->nullable()->unique()->after('id');
        });

        $ids = DB::table('posts')->where('status', '!=', 0)->whereNull('trace_code')->pluck('id');
        foreach ($ids as $id) {
            DB::table('posts')->where('id', $id)->update([
                'trace_code' => $this->unusedCode(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['trace_code']);
            $table->dropColumn('trace_code');
        });
    }

    private function unusedCode(): string
    {
        do {
            $code = TraceCode::make();
        } while (DB::table('posts')->where('trace_code', $code)->exists());

        return $code;
    }
};
