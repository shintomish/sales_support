<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * emails テーブルに RFC822 Message-ID 列を追加。
 *
 * 既存 `gmail_message_id` は IMAP UID 文字列（"imap-{uid}"）を保存しており
 * RFC822 の Message-ID ヘッダ値ではない。
 * SelfMailsView の返信 (POST /emails/{id}/reply) で In-Reply-To / References を
 * 正しくセットしてスレッド維持するために、受信時の Message-ID を別カラムで保持する。
 *
 * バックフィル: 過去メールは raw header を保存していないため null のまま。
 * KagoyaMailService::storeRawMessage() で今後の受信から自動的に埋まる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            // RFC 5322 Message-ID は理論上 998 octets まで。< > は除いて格納する。
            $table->string('rfc_message_id', 998)->nullable()->after('gmail_message_id');
        });

        // 返信送信時に「元 email の rfc_message_id を取得」する用途のみ。
        // 受信側で In-Reply-To マッチに使うフローは現状ないため、テナント横断 unique は付けない。
        // 単純な lookup index で十分。
        Schema::table('emails', function (Blueprint $table) {
            $table->index('rfc_message_id', 'emails_rfc_message_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex('emails_rfc_message_id_idx');
            $table->dropColumn('rfc_message_id');
        });
    }
};
