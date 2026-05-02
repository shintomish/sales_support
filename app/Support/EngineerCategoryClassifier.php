<?php

namespace App\Support;

/**
 * 技術者名らしさを判定し、ses_contracts.category の値を返す
 * 値: 'engineer' (技術者) / 'project' (案件のみ)
 *
 * 用途: 旧データで category が未設定のとき、engineer_name を見て分類する。
 */
class EngineerCategoryClassifier
{
    private const PROJECT_KEYWORDS = [
        '銀行', '証券', '商事', '会社', '企業',
        'NTT', 'MUFG', 'MUFJ', 'MUTB', 'Yahoo', 'JBIC', 'ET',
        'サービス', 'サポート', '支援', 'ツール', '開発', '契約', '業務',
        'データ', 'システム', 'プロジェクト', 'コンサル',
        'チェック', '対応', '更改', '管理', '保守', '運用',
        '様', '/', '-', '(', ')', '（', '）', '・',
    ];

    /**
     * @return 'engineer'|'project'
     */
    public static function classify(?string $engineerName): string
    {
        return self::looksLikePersonName($engineerName) ? 'engineer' : 'project';
    }

    public static function looksLikePersonName(?string $s): bool
    {
        if ($s === null) return false;
        $v = trim($s);
        if ($v === '') return false;
        if (mb_strlen($v) > 10) return false;
        foreach (self::PROJECT_KEYWORDS as $kw) {
            if (mb_strpos($v, $kw) !== false) return false;
        }
        if (preg_match('/[A-Za-z0-9]{3,}/', $v)) return false;
        if (!preg_match('/^[\p{Han}\p{Hiragana}\p{Katakana}\s]+$/u', $v)) return false;
        if (substr_count($v, ' ') > 1) return false;
        return true;
    }
}
