<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Supplier extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'suppliers';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $guarded = ['id'];

    protected $casts = [
        'taxable' => 'boolean',
        'taxed_from_date' => 'date',
        'taxed_till_date' => 'date',
        'subjected_to_tax' => 'boolean',
        'added_tax' => 'decimal:2',
        'accept_cheques' => 'boolean',
        'active' => 'boolean',
        'is_foreign' => 'boolean',
        'add_message' => 'boolean',
        'opening_amount' => 'decimal:2',
        'opening_date' => 'date',
        'credit_limit' => 'decimal:2',
        'max_cheques' => 'integer',
        'search_terms' => 'array',
    ];

    // Relationships
    public function supplierGroup()
    {
        return $this->belongsTo(SupplierGroup::class, 'supplier_group_id');
    }

    public function trade()
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    public function businessType()
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id');
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    // Opening balances relationship for multiple currencies
    public function openingBalances()
    {
        return $this->hasMany(SupplierOpeningBalance::class);
    }

    // Cheque limits relationship for multiple currencies
    public function chequeLimits()
    {
        return $this->hasMany(SupplierChequeLimit::class);
    }

    // Credit limits relationship for multiple currencies
    public function creditLimits()
    {
        return $this->hasMany(SupplierCreditLimit::class);
    }

    // Address relationships using pivot table
    public function addresses()
    {
        return $this->belongsToMany(Address::class, 'supplier_addresses')
                    ->withPivot(['address_type', 'is_primary', 'address_name', 'notes'])
                    ->withTimestamps();
    }

    public function billingAddresses()
    {
        return $this->belongsToMany(Address::class, 'supplier_addresses')
                    ->wherePivot('address_type', 'billing')
                    ->withPivot(['is_primary', 'address_name', 'notes'])
                    ->withTimestamps();
    }

    public function shippingAddresses()
    {
        return $this->belongsToMany(Address::class, 'supplier_addresses')
                    ->wherePivot('address_type', 'shipping')
                    ->withPivot(['is_primary', 'address_name', 'notes'])
                    ->withTimestamps();
    }

    public function primaryBillingAddress()
    {
        return $this->belongsToMany(Address::class, 'supplier_addresses')
                    ->wherePivot('address_type', 'billing')
                    ->wherePivot('is_primary', true)
                    ->withPivot(['address_name', 'notes'])
                    ->withTimestamps();
    }

    public function primaryShippingAddress()
    {
        return $this->belongsToMany(Address::class, 'supplier_addresses')
                    ->wherePivot('address_type', 'shipping')
                    ->wherePivot('is_primary', true)
                    ->withPivot(['address_name', 'notes'])
                    ->withTimestamps();
    }

    // Legacy methods for backward compatibility
    public function billingAddress()
    {
        return $this->primaryBillingAddress();
    }

    public function shippingAddress()
    {
        return $this->primaryShippingAddress();
    }

    public function attachments()
    {
        return $this->hasMany(SupplierAttachment::class);
    }

    // Contacts relationship
    public function contacts()
    {
        return $this->hasMany(SupplierContact::class);
    }

    // Primary contact relationship
    public function primaryContact()
    {
        return $this->belongsTo(SupplierContact::class, 'contacts_id');
    }

    // Tax-related helper methods
    public function isCurrentlyTaxable()
    {
        if (!$this->taxable) {
            return false;
        }

        $now = now()->toDateString();

        // Check if current date is within taxable period
        if ($this->taxed_from_date && $this->taxed_from_date > $now) {
            return false; // Taxable period hasn't started yet
        }

        if ($this->taxed_till_date && $this->taxed_till_date < $now) {
            return false; // Taxable period has expired
        }

        return true;
    }

    public function getTaxStatus()
    {
        if ($this->isCurrentlyTaxable()) {
            return 'taxable';
        }

        return 'not_taxable';
    }

    public function shouldApplyTax()
    {
        // Apply tax if supplier is currently taxable and subjected to tax
        return $this->isCurrentlyTaxable() && $this->subjected_to_tax;
    }

    public function getTaxInfo()
    {
        if (!$this->shouldApplyTax()) {
            return [
                'should_apply_tax' => false,
                'added_tax' => 0,
                'reason' => 'Supplier is not taxable or not subjected to tax',
            ];
        }

        return [
            'should_apply_tax' => true,
            'added_tax' => $this->added_tax ?? 0,
            'taxable' => $this->taxable,
            'subjected_to_tax' => $this->subjected_to_tax,
            'taxed_from_date' => $this->taxed_from_date,
            'taxed_till_date' => $this->taxed_till_date,
        ];
    }

    /**
     * Calculate tax amount for a given amount
     */
    public function calculateTaxAmount($amount)
    {
        if (!$this->shouldApplyTax()) {
            return 0;
        }

        return $amount * (($this->added_tax ?? 0) / 100);
    }

    /**
     * Calculate total amount including tax
     */
    public function calculateTotalWithTax($amount)
    {
        $taxAmount = $this->calculateTaxAmount($amount);
        return $amount + $taxAmount;
    }

    public function setTaxable($taxable, $fromDate = null, $tillDate = null)
    {
        $this->update([
            'taxable' => $taxable,
            'taxed_from_date' => $fromDate,
            'taxed_till_date' => $tillDate,
        ]);
    }

    public function setSubjectedToTax($subjectedToTax, $addedTax = null)
    {
        $this->update([
            'subjected_to_tax' => $subjectedToTax,
            'added_tax' => $addedTax,
        ]);
    }

    // Opening balance helper methods
    public function hasOpeningBalance()
    {
        return !is_null($this->opening_amount) && $this->opening_amount != 0;
    }

    public function getOpeningBalanceSummary()
    {
        if (!$this->hasOpeningBalance()) {
            return null;
        }

        return [
            'currency' => $this->currency,
            'opening_amount' => $this->opening_amount,
            'opening_date' => $this->opening_date,
            'balance_type' => $this->opening_amount > 0 ? 'positive' : 'negative',
            'is_positive' => $this->opening_amount > 0,
            'is_negative' => $this->opening_amount < 0,
            'is_zero' => $this->opening_amount == 0,
        ];
    }

    public function setOpeningBalance($amount, $openingDate = null, $currencyId = null)
    {
        $this->update([
            'opening_amount' => $amount,
            'opening_date' => $openingDate ?? now()->toDateString(),
            'currency_id' => $currencyId ?? $this->currency_id,
        ]);
    }

    // Multi-currency opening balance methods
    public function setOpeningBalanceForCurrency($currencyId, $amount, $openingDate = null, $notes = null)
    {
        $openingBalance = $this->getOpeningBalanceForCurrency($currencyId);
        
        if ($openingBalance) {
            $openingBalance->update([
                'opening_amount' => $amount,
                'opening_date' => $openingDate ?? now()->toDateString(),
                'notes' => $notes,
            ]);
        } else {
            $this->openingBalances()->create([
                'currency_id' => $currencyId,
                'opening_amount' => $amount,
                'opening_date' => $openingDate ?? now()->toDateString(),
                'notes' => $notes,
            ]);
        }
    }

    public function getOpeningBalanceForCurrency($currencyId)
    {
        return $this->openingBalances()
            ->where('currency_id', $currencyId)
            ->where('is_active', true)
            ->first();
    }

    public function hasOpeningBalanceForCurrency($currencyId)
    {
        return $this->openingBalances()
            ->where('currency_id', $currencyId)
            ->where('is_active', true)
            ->exists();
    }

    public function getOpeningCurrencyIds()
    {
        return $this->openingBalances()
            ->where('is_active', true)
            ->pluck('currency_id')
            ->toArray();
    }

    public function getTotalOpeningBalance($currencyId = null)
    {
        if ($currencyId) {
            $openingBalance = $this->getOpeningBalanceForCurrency($currencyId);
            return $openingBalance ? $openingBalance->opening_amount : 0;
        }

        return $this->openingBalances()
            ->where('is_active', true)
            ->sum('opening_amount');
    }

    public function removeOpeningBalance($currencyId)
    {
        $openingBalance = $this->getOpeningBalanceForCurrency($currencyId);
        if ($openingBalance) {
            $openingBalance->update(['is_active' => false]);
            return true;
        }
        return false;
    }

    public function hasMultipleCurrencies()
    {
        return $this->openingBalances()
            ->where('is_active', true)
            ->count() > 1;
    }

    public function getPrimaryCurrency()
    {
        return $this->currency;
    }

    // Credit limit helper methods (Legacy - kept for backward compatibility)
    public function hasCreditLimit()
    {
        return !is_null($this->credit_limit) && $this->credit_limit > 0;
    }

    public function getCreditLimitInfo()
    {
        if (!$this->hasCreditLimit()) {
            return null;
        }

        return [
            'credit_limit' => $this->credit_limit,
            'currency' => $this->currency,
            'has_credit_limit' => true,
        ];
    }

    public function setCreditLimit($amount)
    {
        $this->update(['credit_limit' => $amount]);
    }

    // Multi-currency credit limit methods
    public function getCreditLimitForCurrency($currencyId)
    {
        // Only return credit limit if currency is in opening balances
        if (!$this->hasOpeningBalanceForCurrency($currencyId)) {
            return null;
        }

        return $this->creditLimits()
                    ->where('currency_id', $currencyId)
                    ->active()
                    ->first();
    }

    public function hasCreditLimitForCurrency($currencyId)
    {
        // Only return true if currency is in opening balances AND has credit limit
        if (!$this->hasOpeningBalanceForCurrency($currencyId)) {
            return false;
        }

        return $this->creditLimits()
                    ->where('currency_id', $currencyId)
                    ->active()
                    ->exists();
    }

    public function getTotalCreditLimit($currencyId = null)
    {
        $query = $this->creditLimits()->active();

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->sum('credit_limit');
    }

    public function getTotalUsedCredit($currencyId = null)
    {
        $query = $this->creditLimits()->active();

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->sum('used_credit');
    }

    public function getTotalAvailableCredit($currencyId = null)
    {
        $query = $this->creditLimits()->active();

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->sum('available_credit');
    }

    public function canExceedCreditLimit($amount, $currencyId)
    {
        $creditLimit = $this->getCreditLimitForCurrency($currencyId);

        if (!$creditLimit) {
            return false;
        }

        return $creditLimit->hasAvailableCredit($amount);
    }

    // Additional helper methods for credit management
    public function setCreditLimitForCurrency($currencyId, $amount, $notes = null)
    {
        // Only allow setting credit limit if currency is in opening balances
        if (!$this->hasOpeningBalanceForCurrency($currencyId)) {
            throw new \Exception("Cannot set credit limit for currency that is not in opening balances");
        }

        $creditLimit = $this->getCreditLimitForCurrency($currencyId);

        if ($creditLimit) {
            $creditLimit->update([
                'credit_limit' => $amount,
                'notes' => $notes,
            ]);
        } else {
            $this->creditLimits()->create([
                'currency_id' => $currencyId,
                'credit_limit' => $amount,
                'used_credit' => 0,
                'available_credit' => $amount,
                'notes' => $notes,
                'is_active' => true,
            ]);
        }
    }

    public function increaseUsedCredit($currencyId, $amount)
    {
        $creditLimit = $this->getCreditLimitForCurrency($currencyId);

        if ($creditLimit) {
            $creditLimit->increment('used_credit', $amount);
            $creditLimit->updateAvailableCredit();
        }
    }

    public function decreaseUsedCredit($currencyId, $amount)
    {
        $creditLimit = $this->getCreditLimitForCurrency($currencyId);

        if ($creditLimit) {
            $creditLimit->decrement('used_credit', $amount);
            $creditLimit->updateAvailableCredit();
        }
    }

    public function getCreditUtilizationSummary()
    {
        return $this->creditLimits()
            ->with('currency')
            ->active()
            ->get()
            ->map(function ($creditLimit) {
                return [
                    'currency' => $creditLimit->currency,
                    'credit_limit' => $creditLimit->credit_limit,
                    'used_credit' => $creditLimit->used_credit,
                    'available_credit' => $creditLimit->available_credit,
                    'utilization_percentage' => $creditLimit->getUtilizationPercentage(),
                    'is_over_limit' => $creditLimit->used_credit > $creditLimit->credit_limit,
                ];
            });
    }

    public function hasAnyCreditLimits()
    {
        return $this->creditLimits()->active()->exists();
    }

    public function getActiveCreditCurrencies()
    {
        return $this->creditLimits()
            ->active()
            ->get()
            ->pluck('currency');
    }

    // Cheque limit helper methods (Legacy - kept for backward compatibility)
    public function hasChequeLimit()
    {
        return $this->accept_cheques && !is_null($this->max_cheques) && $this->max_cheques > 0;
    }

    public function getChequeLimitInfo()
    {
        if (!$this->hasChequeLimit()) {
            return null;
        }

        return [
            'max_cheques' => $this->max_cheques,
            'accept_cheques' => $this->accept_cheques,
            'has_cheque_limit' => true,
        ];
    }

    public function setChequeLimit($maxCheques)
    {
        $this->update([
            'accept_cheques' => true,
            'max_cheques' => $maxCheques,
        ]);
    }

    // Multi-currency cheque limit methods
    public function getChequeLimitForCurrency($currencyId)
    {
        // Only return cheque limit if currency is in opening balances
        if (!$this->hasOpeningBalanceForCurrency($currencyId)) {
            return null;
        }

        return $this->chequeLimits()
                    ->where('currency_id', $currencyId)
                    ->active()
                    ->first();
    }

    public function hasChequeLimitForCurrency($currencyId)
    {
        // Only return true if currency is in opening balances AND has cheque limit
        if (!$this->hasOpeningBalanceForCurrency($currencyId)) {
            return false;
        }

        return $this->chequeLimits()
                    ->where('currency_id', $currencyId)
                    ->active()
                    ->exists();
    }

    public function getTotalMaxCheques($currencyId = null)
    {
        $query = $this->chequeLimits()->active();

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->sum('max_cheques');
    }

    public function getTotalUsedCheques($currencyId = null)
    {
        $query = $this->chequeLimits()->active();

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->sum('used_cheques');
    }

    public function getTotalAvailableCheques($currencyId = null)
    {
        $query = $this->chequeLimits()->active();

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->sum('available_cheques');
    }

    public function canAcceptCheque($currencyId, $count = 1)
    {
        if (!$this->accept_cheques) {
            return false;
        }

        $chequeLimit = $this->getChequeLimitForCurrency($currencyId);

        if (!$chequeLimit) {
            return false;
        }

        return $chequeLimit->hasAvailableCheques($count);
    }

    // Additional helper methods for cheque management
    public function setChequeLimitForCurrency($currencyId, $maxCheques, $notes = null)
    {
        // Only allow setting cheque limit if currency is in opening balances
        if (!$this->hasOpeningBalanceForCurrency($currencyId)) {
            throw new \Exception("Cannot set cheque limit for currency that is not in opening balances");
        }

        $chequeLimit = $this->getChequeLimitForCurrency($currencyId);

        if ($chequeLimit) {
            $chequeLimit->update([
                'max_cheques' => $maxCheques,
                'notes' => $notes,
            ]);
        } else {
            $this->chequeLimits()->create([
                'currency_id' => $currencyId,
                'max_cheques' => $maxCheques,
                'used_cheques' => 0,
                'available_cheques' => $maxCheques,
                'notes' => $notes,
                'is_active' => true,
            ]);
        }
    }

    public function increaseUsedCheques($currencyId, $count = 1)
    {
        $chequeLimit = $this->getChequeLimitForCurrency($currencyId);

        if ($chequeLimit) {
            $chequeLimit->increment('used_cheques', $count);
            $chequeLimit->updateAvailableCheques();
        }
    }

    public function decreaseUsedCheques($currencyId, $count = 1)
    {
        $chequeLimit = $this->getChequeLimitForCurrency($currencyId);

        if ($chequeLimit) {
            $chequeLimit->decrement('used_cheques', $count);
            $chequeLimit->updateAvailableCheques();
        }
    }

    public function getChequeUtilizationSummary()
    {
        return $this->chequeLimits()
            ->with('currency')
            ->active()
            ->get()
            ->map(function ($chequeLimit) {
                return [
                    'currency' => $chequeLimit->currency,
                    'max_cheques' => $chequeLimit->max_cheques,
                    'used_cheques' => $chequeLimit->used_cheques,
                    'available_cheques' => $chequeLimit->available_cheques,
                    'utilization_percentage' => $chequeLimit->getUtilizationPercentage(),
                    'is_over_limit' => $chequeLimit->isOverLimit(),
                    'remaining_cheques' => $chequeLimit->getRemainingCheques(),
                ];
            });
    }

    public function hasAnyChequeLimits()
    {
        return $this->chequeLimits()->active()->exists();
    }

    public function getActiveChequeCurrencies()
    {
        return $this->chequeLimits()
            ->active()
            ->get()
            ->pluck('currency');
    }

    // Message functionality helper methods
    public function hasMessage()
    {
        return $this->add_message && !empty($this->message);
    }

    public function getMessage()
    {
        return $this->hasMessage() ? $this->message : null;
    }

    public function setMessage($message, $enabled = true)
    {
        $this->update([
            'add_message' => $enabled,
            'message' => $enabled ? $message : null,
        ]);
    }

    public function disableMessage()
    {
        $this->update([
            'add_message' => false,
            'message' => null,
        ]);
    }

    // Primary contact helper methods
    public function setPrimaryContact($contactId)
    {
        $this->update(['contacts_id' => $contactId]);
    }

    public function removePrimaryContact()
    {
        $this->update(['contacts_id' => null]);
    }

    public function hasPrimaryContact()
    {
        return !is_null($this->contacts_id);
    }

    public function getPrimaryContactInfo()
    {
        if (!$this->hasPrimaryContact()) {
            return null;
        }

        $contact = $this->primaryContact;
        return [
            'id' => $contact->id,
            'name' => $contact->getFullNameAttribute(),
            'phone' => $contact->getPrimaryPhoneAttribute(),
            'position' => $contact->position,
        ];
    }

    // Attachment helper methods
    public function getAttachmentsByCategory($category = null)
    {
        $query = $this->attachments();
        if ($category) {
            $query->byCategory($category);
        }
        return $query->get();
    }

    public function getPublicAttachments()
    {
        return $this->attachments()->public()->get();
    }

    public function getAttachmentCount()
    {
        return $this->attachments()->count();
    }

    public function getAttachmentSize()
    {
        return $this->attachments()->sum('file_size');
    }

    public function getFormattedAttachmentSize()
    {
        $size = $this->getAttachmentSize();
        if ($size == 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }

    public function hasAttachments()
    {
        return $this->attachments()->exists();
    }

    // Search helper methods
    public function hasSearchTerms()
    {
        return !empty($this->search_terms) && is_array($this->search_terms);
    }

    public function getSearchTerms()
    {
        return $this->hasSearchTerms() ? $this->search_terms : [];
    }

    public function addSearchTerm($term)
    {
        $terms = $this->getSearchTerms();
        if (!in_array($term, $terms)) {
            $terms[] = $term;
            $this->update(['search_terms' => $terms]);
        }
    }

    public function removeSearchTerm($term)
    {
        $terms = $this->getSearchTerms();
        $terms = array_diff($terms, [$term]);
        $this->update(['search_terms' => array_values($terms)]);
    }

    // Status helper methods
    public function isActive()
    {
        return $this->active;
    }

    public function isForeign()
    {
        return $this->is_foreign;
    }

    public function activate()
    {
        $this->update(['active' => true]);
    }

    public function deactivate()
    {
        $this->update(['active' => false]);
    }

    public function setForeignStatus($isForeign)
    {
        $this->update(['is_foreign' => $isForeign]);
    }

    // Display name helper
    public function getDisplayNameAttribute()
    {
        if (isset($this->attributes['display_name']) && $this->attributes['display_name']) {
            return $this->attributes['display_name'];
        }

        if ($this->company_name) {
            return $this->company_name;
        }

        $parts = array_filter([$this->first_name, $this->middle_name, $this->last_name]);
        return implode(' ', $parts);
    }

    // Full name helper
    public function getFullNameAttribute()
    {
        $parts = array_filter([$this->title, $this->first_name, $this->middle_name, $this->last_name]);
        return implode(' ', $parts);
    }

    // Phone helper
    public function getPrimaryPhoneAttribute()
    {
        return $this->phone1 ?: $this->phone2 ?: $this->phone3;
    }

    public function hasPhone()
    {
        return !empty($this->phone1) || !empty($this->phone2) || !empty($this->phone3);
    }
}
