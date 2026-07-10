<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * スキル同義語辞書（名寄せ）。skill_aliases テーブルを read-time で参照し、
 * 表記揺れ・別名・略称を吸収する。
 *
 * 使い方:
 *   - normalize($s)  : 比較用の正規化（小文字化＋全角→半角＋空白圧縮）
 *   - canonical($s)  : 正規名グループキー（同義語の等価判定に使う）
 *   - expand($s)     : その語の全表記揺れ（ILIKE 検索の OR 展開用・生サーフェス文字列）
 *
 * 既存データは書き換えない（再抽出・再スコアしない）。比較の瞬間に名寄せする。
 */
class SkillDictionary
{
    private const CACHE_KEY = 'skill_dictionary_v1';
    private const TTL       = 21600; // 6h

    /** normalize(alias) => normalize(canonical) */
    private array $aliasToCanon = [];
    /** normalize(canonical) => [生の表記揺れ文字列...] */
    private array $canonToForms = [];
    private bool $loaded = false;

    /** 比較用の正規化: 小文字化＋全角英数/空白→半角＋連続空白の圧縮。 */
    public function normalize(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        $s = mb_convert_kana($s, 'as'); // a=全角英数→半角, s=全角空白→半角
        $s = mb_strtolower($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function load(): void
    {
        if ($this->loaded) return;

        $rows = Cache::remember(self::CACHE_KEY, self::TTL, function () {
            return DB::table('skill_aliases')->get(['canonical', 'alias'])
                ->map(fn($r) => ['canonical' => $r->canonical, 'alias' => $r->alias])
                ->all();
        });

        foreach ($rows as $r) {
            $nc = $this->normalize($r['canonical']);
            $na = $this->normalize($r['alias']);
            if ($nc === '' || $na === '') continue;
            $this->aliasToCanon[$na]   = $nc;
            $this->canonToForms[$nc][] = $r['alias'];
        }
        $this->loaded = true;
    }

    /** 正規名グループキー（未知語は normalize した自身を返す）。 */
    public function canonical(string $s): string
    {
        $this->load();
        $n = $this->normalize($s);
        return $this->aliasToCanon[$n] ?? $n;
    }

    /**
     * 入力語の全表記揺れ（生サーフェス文字列）。ILIKE の OR 展開用。
     * 未知語は入力自身のみを返す（取りこぼし防止）。
     */
    public function expand(string $s): array
    {
        $this->load();
        $c     = $this->canonical($s);
        $forms = $this->canonToForms[$c] ?? [];
        $forms[] = $s; // 入力自身も必ず含める
        return array_values(array_unique(array_filter($forms, fn($x) => trim((string) $x) !== '')));
    }

    /** 辞書更新後にキャッシュを破棄（将来の管理UI用）。 */
    /**
     * 抽出用の全サーフェス（canonical＋alias の生表記・重複排除）。
     * extractSkills が「辞書に載っている語」を skills に格納するために使う（辞書駆動抽出）。
     * 誤検出防止のため 2 文字未満は除外（短すぎる語は substring で暴発する）。
     */
    public function surfaces(): array
    {
        return Cache::remember(self::CACHE_KEY . ':surfaces', self::TTL, function () {
            $rows = DB::table('skill_aliases')->get(['canonical', 'alias']);
            $out = [];
            foreach ($rows as $r) {
                $out[] = (string) $r->canonical;
                $out[] = (string) $r->alias;
            }
            return array_values(array_unique(array_filter(
                array_map('trim', $out),
                fn ($x) => mb_strlen($x) >= 2,
            )));
        });
    }

    /** 辞書更新後にキャッシュを破棄（将来の管理UI用）。 */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY . ':surfaces');
    }
}
