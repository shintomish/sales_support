<?php

namespace App\Services;

use App\Models\BusinessCard;
use App\Models\Customer;
use App\Models\Contact;



class BusinessCardRegistrationService
{
    /**
     * 氏名比較用の異体字マップ (旧字体・代替形 → 標準形)
     *
     * OCR で 「富⇄冨」「高⇄髙」のようなゆれがあるため、
     * 比較時に同じ字に正規化して同一人物と判定する。
     * 表示は元のまま (ユーザーが編集した字体を尊重)。
     */
    private const KANJI_VARIANTS = [
        '冨' => '富', '髙' => '高', '齋' => '斎', '齊' => '斎', '斉' => '斎',
        '邊' => '辺', '邉' => '辺', '澤' => '沢', '濱' => '浜', '櫻' => '桜',
        '國' => '国', '學' => '学', '廣' => '広', '靜' => '静', '醫' => '医',
        '舘' => '館', '嶋' => '島', '嵜' => '崎', '會' => '会', '應' => '応',
        '處' => '処', '專' => '専', '德' => '徳', '惠' => '恵', '寬' => '寛',
    ];


    /**
     * 名刺データから顧客・担当者を自動登録
     */
    public function register(BusinessCard $card): array
    {
        // 1. 既存顧客を検索（会社名の類似度チェック）
        $customer = $this->findOrCreateCustomer($card);
        
        // 2. 担当者を作成
        $contact = $this->createContact($card, $customer);
        
        // 3. 名刺レコードを更新
        $card->update([
            'customer_id' => $customer->id,
            'contact_id' => $contact->id,
            'status' => 'registered',
        ]);
        
        return [
            'customer' => $customer,
            'contact' => $contact,
            'is_new_customer' => $customer->wasRecentlyCreated,
        ];
    }
    
    /**
     * 既存顧客を検索、なければ新規作成。既存があれば住所/電話を最新の名刺で更新する。
     */
    private function findOrCreateCustomer(BusinessCard $card): Customer
    {
        if (empty($card->company_name)) {
            // 会社名がない場合は個人として扱う
            return $this->createIndividualCustomer($card);
        }

        // 完全一致で検索
        $customer = Customer::where('company_name', $card->company_name)->first();

        if ($customer) {
            \Log::info('既存顧客を発見: ' . $customer->company_name);
            return $this->updateCustomerIfChanged($customer, $card);
        }

        // あいまい検索（類似度チェック）
        $customer = $this->findSimilarCustomer($card->company_name);

        if ($customer) {
            \Log::info('類似顧客を発見: ' . $customer->company_name);
            return $this->updateCustomerIfChanged($customer, $card);
        }

        // 新規作成
        \Log::info('新規顧客を作成: ' . $card->company_name);
        return Customer::create([
            'company_name' => $card->company_name,
            'phone' => $card->phone,
            'address' => $card->address,
        ]);
    }

    /**
     * 名刺の新しい値で顧客を上書き更新する。
     * null/空文字は既存値を保持。
     */
    private function updateCustomerIfChanged(Customer $customer, BusinessCard $card): Customer
    {
        $updates = [];
        if (!empty($card->phone)   && $card->phone   !== $customer->phone)   $updates['phone']   = $card->phone;
        if (!empty($card->address) && $card->address !== $customer->address) $updates['address'] = $card->address;
        if (!empty($updates)) {
            $customer->update($updates);
            \Log::info('顧客情報を更新: ' . $customer->company_name . ' / ' . implode(',', array_keys($updates)));
        }
        return $customer;
    }
    
    /**
     * 類似する顧客を検索（あいまい検索）
     */
    private function findSimilarCustomer(string $companyName): ?Customer
    {
        // 「株式会社」「有限会社」などを除去して検索
        $normalized = $this->normalizeCompanyName($companyName);
        
        $customers = Customer::all();
        
        foreach ($customers as $customer) {
            $existingNormalized = $this->normalizeCompanyName($customer->company_name);
            
            // 類似度を計算（Levenshtein距離）
            $similarity = $this->calculateSimilarity($normalized, $existingNormalized);
            
            // 80%以上の類似度で一致と判定
            if ($similarity >= 0.8) {
                \Log::info("類似度 {$similarity}: {$companyName} ≈ {$customer->company_name}");
                return $customer;
            }
        }
        
        return null;
    }
    
    /**
     * 会社名を正規化（比較用）
     */
    private function normalizeCompanyName(string $name): string
    {
        // 株式会社、有限会社、合同会社等を除去
        $name = preg_replace('/株式会社|有限会社|合同会社|一般社団法人|一般財団法人/', '', $name);
        // スペースを除去
        $name = str_replace([' ', '　'], '', $name);
        // 小文字に統一
        $name = mb_strtolower($name);
        
        return $name;
    }
    
    /**
     * 文字列の類似度を計算（0.0〜1.0）
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $len1 = mb_strlen($str1);
        $len2 = mb_strlen($str2);
        
        if ($len1 === 0 || $len2 === 0) {
            return 0.0;
        }
        
        $distance = levenshtein($str1, $str2);
        $maxLen = max($len1, $len2);
        
        return 1 - ($distance / $maxLen);
    }
    
    /**
     * 個人顧客を作成（会社名なし）
     */
    private function createIndividualCustomer(BusinessCard $card): Customer
    {
        return Customer::create([
            'company_name' => $card->person_name . '（個人）',
            'phone' => $card->mobile ?? $card->phone,
            'address' => $card->address,
        ]);
    }
    
    /**
     * 担当者を作成または更新
     *
     * 重複判定の優先順位:
     *  1. 同じ顧客 × 同じメール → 既存を更新 (確実な同一人物)
     *  2. 同じ顧客 × 同じ氏名 (空白除去) → 既存を更新 (役職変更・住所変更等を反映)
     *  3. 上記いずれにも該当しなければ新規作成
     *
     * 更新ポリシー: 名刺側の値が非空かつ既存と異なる場合のみ上書き。
     */
    private function createContact(BusinessCard $card, Customer $customer): Contact
    {
        // 1. メール一致
        if ($card->email) {
            $existing = Contact::where('customer_id', $customer->id)
                ->where('email', $card->email)
                ->first();

            if ($existing) {
                \Log::info('既存担当者を発見 (email): ' . $existing->name);
                return $this->updateContactIfChanged($existing, $card);
            }
        }

        // 2. 氏名一致 (空白除去 + 異体字正規化)
        if ($card->person_name) {
            $cardKey = $this->normalizePersonName($card->person_name);
            $existing = Contact::where('customer_id', $customer->id)
                ->whereNotNull('name')
                ->get()
                ->first(fn ($c) => $this->normalizePersonName($c->name) === $cardKey);

            if ($existing) {
                \Log::info("既存担当者を発見 (name): {$existing->name} ≒ {$card->person_name} / 更新");
                return $this->updateContactIfChanged($existing, $card);
            }
        }

        // 3. 新規作成
        \Log::info('新規担当者を作成: ' . $card->person_name);
        return Contact::create([
            'customer_id' => $customer->id,
            'name' => $card->person_name,
            'email' => $card->email,
            'phone' => $card->mobile ?? $card->phone,
            'position' => $card->position,
        ]);
    }

    /**
     * 氏名の比較キーを生成: 空白除去 + 異体字を標準形に
     */
    public function normalizePersonName(string $name): string
    {
        $normalized = str_replace([' ', '　'], '', $name);
        return strtr($normalized, self::KANJI_VARIANTS);
    }

    /**
     * 同一会社 + 同一氏名 (異体字許容) の既存 BusinessCard を返す。
     * 見つからなければ null。アップロード前の重複判定に使用。
     */
    public function findExistingCard(?string $companyName, ?string $personName): ?BusinessCard
    {
        if (empty($companyName) || empty($personName)) return null;

        $cardKey = $this->normalizePersonName($personName);
        return BusinessCard::where('company_name', $companyName)
            ->whereNotNull('person_name')
            ->get()
            ->first(fn ($c) => $this->normalizePersonName($c->person_name) === $cardKey);
    }

    /**
     * 名刺の新しい値で担当者を上書き更新する。
     * null/空文字は既存値を保持。
     */
    private function updateContactIfChanged(Contact $contact, BusinessCard $card): Contact
    {
        $cardPhone = $card->mobile ?? $card->phone;

        $updates = [];
        if (!empty($card->email)    && $card->email    !== $contact->email)    $updates['email']    = $card->email;
        if (!empty($cardPhone)      && $cardPhone      !== $contact->phone)    $updates['phone']    = $cardPhone;
        if (!empty($card->position) && $card->position !== $contact->position) $updates['position'] = $card->position;
        if (!empty($updates)) {
            $contact->update($updates);
            \Log::info('担当者情報を更新: ' . $contact->name . ' / ' . implode(',', array_keys($updates)));
        }
        return $contact;
    }
}
