<?php

namespace App\Services;

use App\Models\BusinessCard;
use App\Models\Customer;
use App\Models\Contact;

class BusinessCardRegistrationService
{
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
     * 既存顧客を検索、なければ新規作成
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
            return $customer;
        }
        
        // あいまい検索（類似度チェック）
        $customer = $this->findSimilarCustomer($card->company_name);
        
        if ($customer) {
            \Log::info('類似顧客を発見: ' . $customer->company_name);
            return $customer;
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
     * 担当者を作成
     */
    private function createContact(BusinessCard $card, Customer $customer): Contact
    {
        // 既存の担当者をチェック（同じ顧客で同じメールアドレス）
        if ($card->email) {
            $existing = Contact::where('customer_id', $customer->id)
                ->where('email', $card->email)
                ->first();
            
            if ($existing) {
                \Log::info('既存担当者を発見: ' . $existing->name);
                return $existing;
            }
        }
        
        // 新規作成
        \Log::info('新規担当者を作成: ' . $card->person_name);
        return Contact::create([
            'customer_id' => $customer->id,
            'name' => $card->person_name,
            'email' => $card->email,
            'phone' => $card->mobile ?? $card->phone,
            'position' => $card->position,
        ]);
    }
}
