<?php

namespace App\Services;

/**
 * SmoothContact 経由のフォーム投稿メール（差出人 smoothcontact-system@blks.jp、
 * 件名「企業向け問い合わせに投稿がありました」、category='other'）の本文から
 * ヘッダ項目を構造化抽出する純ロジック（DB 非依存）。
 *
 * 本文フォーマット例:
 *   [ 御社名 ] Will Spark合同会社
 *   [ 部署名 ]
 *   [ ご担当者様 ] 村瀬 雄哉 (ムラセ ユウヤ)
 *   [ メールアドレス ] y-murase@willspark.jp
 *   [ お電話番号 ] 05071098139
 *   [ ご住所 ]
 *   郵便番号: 5560011
 *   都道府県: 大阪府
 *   市区町村: 大阪市浪速区
 *   町名: 難波中
 *   番地等: 1丁目16番11号
 *   建物名: レック難波ビル203号室
 *   [ お問い合わせ項目 ] 【ご紹介】...
 *   [ お問い合わせ内容 ] ...（本文・抽出対象外）
 *
 * 角括弧ラベルの正規表現は ProjectMailScoringService の SmoothContact 抽出と同じ idiom
 * （全角/半角スペース両対応・次の角括弧/改行で停止）。お問い合わせ内容と本文中 URL は抽出しない。
 */
class ContactFormSubmissionParser
{
    /**
     * @return array{
     *   company: ?string, department: ?string, contact_person: ?string,
     *   email: ?string, phone: ?string, address: ?string, inquiry_subject: ?string
     * }
     */
    public function parse(string $body): array
    {
        return [
            'company'         => $this->label($body, '御社名'),
            'department'      => $this->label($body, '部署名'),
            'contact_person'  => $this->stripReading($this->label($body, 'ご担当者様')),
            'email'           => $this->label($body, 'メールアドレス'),
            'phone'           => $this->label($body, 'お電話番号'),
            'address'         => $this->parseAddress($body),
            'inquiry_subject' => $this->label($body, 'お問い合わせ項目'),
        ];
    }

    /**
     * 「[ ラベル ] 値」を抽出。値は次の角括弧 [ または改行まで。空なら null。
     */
    private function label(string $body, string $label): ?string
    {
        $escaped = preg_quote($label, '/');
        if (preg_match('/\[[ 　]*' . $escaped . '[ 　]*\][ 　\t]*([^\n\r\[]*)/u', $body, $m)) {
            $val = trim($m[1]);
            return $val === '' ? null : $val;
        }
        return null;
    }

    /**
     * ご担当者様の末尾の読み仮名（全角/半角括弧）を除去。
     * 「村瀬 雄哉 (ムラセ ユウヤ)」→「村瀬 雄哉」
     */
    private function stripReading(?string $name): ?string
    {
        if ($name === null) return null;
        $name = trim(preg_replace('/\s*[（(][^）)]*[）)]\s*$/u', '', $name) ?? $name);
        return $name === '' ? null : $name;
    }

    /**
     * 「[ ご住所 ]」以降の 郵便番号:/都道府県:/市区町村:/町名:/番地等:/建物名: を取得し、
     * 非空のものを 1 行の住所文字列に連結する。郵便番号は 〒NNN-NNNN 整形。
     *
     * 注意: 一部メールは「番地等」に住所全体が再掲され重複することがある（低頻度・人が読む前提のため許容）。
     */
    private function parseAddress(string $body): ?string
    {
        // 値の前の空白は横方向のみ（\s だと空値のとき改行を跨いで次フィールドを巻き込む）
        $sub = fn(string $key): ?string =>
            preg_match('/' . preg_quote($key, '/') . '[:：][ 　\t]*([^\n\r]*)/u', $body, $m) && trim($m[1]) !== ''
                ? trim($m[1])
                : null;

        $postal  = $sub('郵便番号');
        $parts   = array_filter([
            $sub('都道府県'),
            $sub('市区町村'),
            $sub('町名'),
            $sub('番地等'),
            $sub('建物名'),
        ]);

        $segments = [];
        if ($postal !== null) {
            $digits = preg_replace('/[^0-9]/', '', $postal) ?? '';
            $segments[] = strlen($digits) === 7
                ? '〒' . substr($digits, 0, 3) . '-' . substr($digits, 3)
                : '〒' . $postal;
        }
        if (!empty($parts)) {
            $segments[] = implode('', $parts);
        }

        return empty($segments) ? null : implode(' ', $segments);
    }
}
