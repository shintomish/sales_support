<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_status_check');
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_status_check CHECK (status::text = ANY (ARRAY['新規','提案','交渉','成約','失注','稼働中','更新交渉中','期限切れ']::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deals DROP CONSTRAINT IF EXISTS deals_status_check');
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_status_check CHECK (status::text = ANY (ARRAY['新規','提案','交渉','成約','失注']::text[]))");
    }
};
