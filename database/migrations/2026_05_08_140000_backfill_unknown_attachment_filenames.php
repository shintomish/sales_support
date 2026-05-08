<?php

use App\Services\KagoyaMailService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 過去の Kagoya メール取り込みで filename='unknown' になった添付を、
 * mime_type から推測した attachment.<ext> に置き換える。
 * バイナリは復元できないが、UI の表示と DL 時のファイル名がまともになる。
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('email_attachments')
            ->whereIn('filename', ['unknown', ''])
            ->orWhereNull('filename')
            ->select('id', 'mime_type')
            ->get();

        foreach ($rows as $row) {
            $ext = KagoyaMailService::extensionForMime($row->mime_type);
            DB::table('email_attachments')
                ->where('id', $row->id)
                ->update(['filename' => 'attachment' . $ext]);
        }
    }

    public function down(): void
    {
        // 元の 'unknown' に戻す処理は意図的に行わない（壊れた状態に戻したくない）
    }
};
