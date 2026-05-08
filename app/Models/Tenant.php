<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'plan', 'is_active', 'ses_enabled',
        'invoice_issuer_name',
        'invoice_issuer_postal_code',
        'invoice_issuer_address',
        'invoice_issuer_tel',
        'invoice_issuer_fax',
        'invoice_issuer_logo_path',
        'invoice_issuer_seal_path',
        'invoice_issuer_url',
        'invoice_issuer_invoice_number',
        'invoice_issuer_bank_name',
        'invoice_issuer_bank_branch',
        'invoice_issuer_bank_account_type',
        'invoice_issuer_bank_account_number',
        'invoice_issuer_bank_account_holder',
    ];

    protected $casts = [
        'ses_enabled' => 'boolean',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
}
