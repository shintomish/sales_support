<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 非開発・ロースキルロールの同義語グループを skill_aliases にシードする。
 *
 * 目的: extractSkills が辞書 surfaces を走査するようになったため（辞書駆動抽出）、辞書に
 * 語を登録すれば抽出・検索に反映される。ロール語の表記揺れ（ヘルプデスク↔サポートデスク等）を
 * まとめて検索時の同義語展開も効かせる。
 *
 * insertOrIgnore（alias UNIQUE）で既存エントリと衝突しても安全。誤検出しやすい短い汎用語
 * （事務 / テスト 等の単体）は入れない。
 */
return new class extends Migration
{
    private array $groups = [
        'ヘルプデスク'     => ['ヘルプデスク', 'サポートデスク', 'Helpdesk', 'ヘルプデスク業務'],
        'コールセンター'   => ['コールセンター', 'カスタマーサポート', 'テレオペ', 'テレオペレーター', '電話対応', '問い合わせ対応'],
        '一般事務'         => ['一般事務', 'OA事務', '事務スタッフ'],
        'データ入力'       => ['データ入力', 'データエントリ', 'データエントリー', 'データ入力業務'],
        'キッティング'     => ['キッティング', 'PCキッティング', 'PCセットアップ'],
        '運用保守'         => ['運用保守', '保守運用', '運用監視'],
        '監視'             => ['監視', '監視業務'],
        '障害対応'         => ['障害対応', '一次対応', '二次対応', 'インシデント対応'],
        'ITサポート'       => ['ITサポート', 'テクニカルサポート', 'PCサポート'],
        '導入支援'         => ['導入支援', '導入サポート'],
        'テスター'         => ['テスター', 'テスト要員', 'テスト担当'],
        'マニュアル作成'   => ['マニュアル作成', '手順書作成'],
        'オペレーター'     => ['オペレーター', '運用オペレーター', '監視オペレーター'],
        'PMO'              => ['PMO', 'PMOサポート'],
        'サーバー運用'     => ['サーバー運用', 'サーバ運用'],
        'ネットワーク運用' => ['ネットワーク運用', 'NW運用'],
        'インフラ運用'     => ['インフラ運用', 'インフラ保守'],
    ];

    public function up(): void
    {
        $now  = now();
        $rows = [];
        foreach ($this->groups as $canonical => $aliases) {
            foreach (array_unique($aliases) as $alias) {
                $rows[] = ['canonical' => $canonical, 'alias' => $alias, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        // alias UNIQUE。既存と衝突しても無視（べき等）。
        DB::table('skill_aliases')->insertOrIgnore($rows);

        \App\Services\SkillDictionary::forgetCache();
    }

    public function down(): void
    {
        foreach ($this->groups as $aliases) {
            DB::table('skill_aliases')->whereIn('alias', $aliases)->delete();
        }
        \App\Services\SkillDictionary::forgetCache();
    }
};
