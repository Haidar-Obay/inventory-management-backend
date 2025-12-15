<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Customer extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'customers';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $guarded = ['id'];

    protected $casts = [
        'taxable' => 'boolean',
        'taxed_from_date' => 'date',
        'taxed_till_date' => 'date',
        'subjected_to_tax' => 'boolean',
        'added_tax' => 'decimal:2',
        'exempted' => 'boolean',
        'exempted_from_date' => 'date',
        'exempted_till_date' => 'date',
        'showMessageField' => 'boolean',
        'allow_credit' => 'boolean',
        'accept_cheques' => 'boolean',
        'active' => 'boolean',
        'black_listed' => 'boolean',
        'one_time_account' => 'boolean',
        'special_account' => 'boolean',
        'pos_customer' => 'boolean',
        'free_delivery_charge' => 'boolean',
        'global_discount' => 'decimal:2',
        'markup_percentage' => 'decimal:2',
        'markdown_percentage' => 'decimal:2',
        'search_terms' => 'array',
        'date_of_birth' => 'date',
    ];

    // Status constants
    const STATUS_NORMAL = 'Normal';

    const STATUS_VIP = 'VIP';

    // Get all available statuses
    public static function getStatuses()
    {
        return [
            self::STATUS_NORMAL,
            self::STATUS_VIP,
        ];
    }

    // public function projects()
    // {
    //     return $this->hasMany(Project::class);
    // }

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function salesman()
    {
        return $this->belongsTo(Salesman::class, 'salesman_id');
    }

    public function collector()
    {
        return $this->belongsTo(Salesman::class, 'collector_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(Salesman::class, 'supervisor_id');
    }

    public function manager()
    {
        return $this->belongsTo(Salesman::class, 'manager_id');
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function trade()
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    public function companyCode()
    {
        return $this->belongsTo(CompanyCode::class, 'company_code_id');
    }

    public function businessType()
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id');
    }

    public function salesChannel()
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }

    public function distributionChannel()
    {
        return $this->belongsTo(DistributionChannel::class, 'distribution_channel_id');
    }

    public function mediaChannel()
    {
        return $this->belongsTo(MediaChannel::class, 'media_channel_id');
    }

    public function mediaType()
    {
        return $this->belongsTo(MediaType::class, 'media_type_id');
    }

    public function referral()
    {
        return $this->belongsTo(Referrer::class, 'referral_id');
    }

    // Association relationship (many-to-many)
    public function associations()
    {
        return $this->belongsToMany(Association::class, 'customer_association')
            ->withTimestamps();
    }

    // Credit limits relationship
    public function creditLimits()
    {
        return $this->hasMany(CustomerCreditLimit::class);
    }

    public function activeCreditLimits()
    {
        return $this->hasMany(CustomerCreditLimit::class)->active();
    }

    // Cheque limits relationship
    public function chequeLimits()
    {
        return $this->hasMany(CustomerChequeLimit::class);
    }

    public function activeChequeLimits()
    {
        return $this->hasMany(CustomerChequeLimit::class)->active();
    }

    // Opening balances relationship
    public function openingBalances()
    {
        return $this->hasMany(CustomerOpeningBalance::class);
    }

    public function activeOpeningBalances()
    {
        return $this->hasMany(CustomerOpeningBalance::class)->active();
    }

    // Address relationships (many-to-many via customer_addresses pivot table)
    public function addresses()
    {
        return $this->belongsToMany(Address::class, 'customer_addresses')
            ->withPivot('address_type', 'is_primary', 'address_name', 'notes')
            ->withTimestamps();
    }

    public function billingAddresses()
    {
        return $this->belongsToMany(Address::class, 'customer_addresses')
            ->wherePivot('address_type', 'billing')
            ->withPivot('address_type', 'is_primary', 'address_name', 'notes')
            ->withTimestamps();
    }

    public function shippingAddresses()
    {
        return $this->belongsToMany(Address::class, 'customer_addresses')
            ->wherePivot('address_type', 'shipping')
            ->withPivot('address_type', 'is_primary', 'address_name', 'notes')
            ->withTimestamps();
    }

    public function primaryBillingAddress()
    {
        return $this->belongsToMany(Address::class, 'customer_addresses')
            ->wherePivot('address_type', 'billing')
            ->wherePivot('is_primary', true)
            ->withPivot('address_type', 'is_primary', 'address_name', 'notes')
            ->withTimestamps();
    }

    public function primaryShippingAddress()
    {
        return $this->belongsToMany(Address::class, 'customer_addresses')
            ->wherePivot('address_type', 'shipping')
            ->wherePivot('is_primary', true)
            ->withPivot('address_type', 'is_primary', 'address_name', 'notes')
            ->withTimestamps();
    }

    // Legacy methods for backward compatibility (if needed)
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
        return $this->hasMany(CustomerAttachment::class);
    }

    // Contacts relationship
    public function contacts()
    {
        return $this->hasMany(CustomerContact::class);
    }

    // Primary contact relationship
    public function primaryContact()
    {
        return $this->belongsTo(CustomerContact::class, 'contacts_id');
    }

    // Customer routes relationship
    public function routes()
    {
        return $this->hasMany(CustomerRoute::class);
    }

    public function appointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_customer')
            ->withTimestamps();
    }

    public function activeRoute()
    {
        return $this->hasOne(CustomerRoute::class)->active();
    }

    // Credit limit helper methods
    public function getCreditLimitForCurrency($currencyId)
    {
        // Only return credit limit if currency is in opening balances
        if (! $this->hasOpeningBalanceForCurrency($currencyId)) {
            return;
        }

        return $this->creditLimits()
            ->where('currency_id', $currencyId)
            ->active()
            ->first();
    }

    public function hasCreditLimitForCurrency($currencyId)
    {
        // Only return true if currency is in opening balances AND has credit limit
        if (! $this->hasOpeningBalanceForCurrency($currencyId)) {
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
        if (! $this->allow_credit) {
            return false;
        }

        $creditLimit = $this->getCreditLimitForCurrency($currencyId);

        if (! $creditLimit) {
            return false;
        }

        return $creditLimit->hasAvailableCredit($amount);
    }

    // Additional helper methods for credit management
    public function setCreditLimit($currencyId, $amount, $notes = null)
    {
        // Only allow setting credit limit if currency is in opening balances
        if (! $this->hasOpeningBalanceForCurrency($currencyId)) {
            throw new \Exception('Cannot set credit limit for currency that is not in opening balances');
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

    public function getActiveCurrencies()
    {
        return $this->creditLimits()
            ->with('currency')
            ->active()
            ->get()
            ->pluck('currency');
    }

    // Cheque limit helper methods
    public function getChequeLimitForCurrency($currencyId)
    {
        // Only return cheque limit if currency is in opening balances
        if (! $this->hasOpeningBalanceForCurrency($currencyId)) {
            return;
        }

        return $this->chequeLimits()
            ->where('currency_id', $currencyId)
            ->active()
            ->first();
    }

    public function hasChequeLimitForCurrency($currencyId)
    {
        // Only return true if currency is in opening balances AND has cheque limit
        if (! $this->hasOpeningBalanceForCurrency($currencyId)) {
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
        if (! $this->accept_cheques) {
            return false;
        }

        $chequeLimit = $this->getChequeLimitForCurrency($currencyId);

        if (! $chequeLimit) {
            return false;
        }

        return $chequeLimit->hasAvailableCheques($count);
    }

    // Additional helper methods for cheque management
    public function setChequeLimit($currencyId, $maxCheques, $notes = null)
    {
        // Only allow setting cheque limit if currency is in opening balances
        if (! $this->hasOpeningBalanceForCurrency($currencyId)) {
            throw new \Exception('Cannot set cheque limit for currency that is not in opening balances');
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
            ->with('currency')
            ->active()
            ->get()
            ->pluck('currency');
    }

    // Tax-related helper methods
    public function isCurrentlyTaxable()
    {
        if (! $this->taxable) {
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

    public function isCurrentlyExempted()
    {
        if (! $this->exempted) {
            return false;
        }

        $now = now()->toDateString();

        // Check if current date is within exemption period
        if ($this->exempted_from_date && $this->exempted_from_date > $now) {
            return false; // Exemption hasn't started yet
        }

        if ($this->exempted_till_date && $this->exempted_till_date < $now) {
            return false; // Exemption has expired
        }

        return true;
    }

    public function getTaxStatus()
    {
        if ($this->isCurrentlyExempted()) {
            return 'exempted';
        }

        if ($this->isCurrentlyTaxable()) {
            return 'taxable';
        }

        return 'not_taxable';
    }

    public function getExemptionStatus()
    {
        if (! $this->exempted) {
            return 'not_exempted';
        }

        if (! $this->isCurrentlyExempted()) {
            return 'exemption_expired';
        }

        return 'currently_exempted';
    }

    public function getExemptionDaysRemaining()
    {
        if (! $this->isCurrentlyExempted()) {
            return 0;
        }

        if (! $this->exempted_till_date) {
            return; // No end date specified (permanent exemption)
        }

        return now()->diffInDays($this->exempted_till_date, false);
    }

    public function shouldApplyTax()
    {
        // Don't apply tax if customer is currently exempted
        if ($this->isCurrentlyExempted()) {
            return false;
        }

        // Apply tax if customer is currently taxable and subjected to tax
        return $this->isCurrentlyTaxable() && $this->subjected_to_tax;
    }

    public function getTaxInfo()
    {
        if (! $this->shouldApplyTax()) {
            return [
                'should_apply_tax' => false,
                'added_tax' => 0,
                'reason' => $this->isCurrentlyExempted() ? 'Customer is tax exempted' : 'Customer is not taxable or not subjected to tax',
            ];
        }

        return [
            'should_apply_tax' => true,
            'added_tax' => $this->added_tax ?? 0,
            'taxable' => $this->taxable,
            'subjected_to_tax' => $this->subjected_to_tax,
            'exempted' => $this->exempted,
            'exempted_from' => $this->exempted_from,
            'exemption_reference' => $this->exemption_reference,
            'exempted_from_date' => $this->exempted_from_date,
            'exempted_till_date' => $this->exempted_till_date,
            'taxed_from_date' => $this->taxed_from_date,
            'taxed_till_date' => $this->taxed_till_date,
        ];
    }

    /**
     * Calculate tax amount for a given amount
     */
    public function calculateTaxAmount($amount)
    {
        if (! $this->shouldApplyTax()) {
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

    public function setTaxExemption($exemptedFrom, $exemptionReference, $fromDate = null, $tillDate = null)
    {
        $this->update([
            'exempted' => true,
            'exempted_from' => $exemptedFrom,
            'exemption_reference' => $exemptionReference,
            'exempted_from_date' => $fromDate,
            'exempted_till_date' => $tillDate,
        ]);
    }

    public function removeTaxExemption()
    {
        $this->update([
            'exempted' => false,
            'exempted_from' => null,
            'exemption_reference' => null,
            'exempted_from_date' => null,
            'exempted_till_date' => null,
        ]);
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
    public function getOpeningBalanceForCurrency($currencyId)
    {
        return $this->openingBalances()
            ->where('currency_id', $currencyId)
            ->active()
            ->first();
    }

    public function hasOpeningBalanceForCurrency($currencyId)
    {
        return $this->openingBalances()
            ->where('currency_id', $currencyId)
            ->active()
            ->exists();
    }

    public function getOpeningCurrencies()
    {
        return $this->openingBalances()
            ->with('currency')
            ->active()
            ->get()
            ->pluck('currency');
    }

    public function getOpeningCurrencyIds()
    {
        return $this->openingBalances()
            ->active()
            ->pluck('currency_id')
            ->toArray();
    }

    public function getTotalOpeningBalance($currencyId = null)
    {
        $query = $this->openingBalances()->active();

        if ($currencyId) {
            $query->where('currency_id', $currencyId);
        }

        return $query->sum('opening_amount');
    }

    public function getOpeningBalanceSummary()
    {
        return $this->openingBalances()
            ->with('currency')
            ->active()
            ->get()
            ->map(function ($openingBalance) {
                return [
                    'currency' => $openingBalance->currency,
                    'opening_amount' => $openingBalance->opening_amount,
                    'opening_date' => $openingBalance->opening_date,
                    'notes' => $openingBalance->notes,
                    'balance_type' => $openingBalance->getBalanceType(),
                    'is_positive' => $openingBalance->isPositive(),
                    'is_negative' => $openingBalance->isNegative(),
                    'is_zero' => $openingBalance->isZero(),
                ];
            });
    }

    public function setOpeningBalance($currencyId, $amount, $openingDate = null, $notes = null)
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
                'is_active' => true,
            ]);
        }
    }

    public function removeOpeningBalance($currencyId)
    {
        $openingBalance = $this->getOpeningBalanceForCurrency($currencyId);

        if ($openingBalance) {
            $openingBalance->update(['is_active' => false]);
        }
    }

    public function hasAnyOpeningBalances()
    {
        return $this->openingBalances()->active()->exists();
    }

    // Methods to check if currency is available for credit/cheque limits
    public function isCurrencyAvailableForCreditLimit($currencyId)
    {
        return $this->hasOpeningBalanceForCurrency($currencyId);
    }

    public function isCurrencyAvailableForChequeLimit($currencyId)
    {
        return $this->hasOpeningBalanceForCurrency($currencyId);
    }

    public function getAvailableCurrenciesForCreditLimits()
    {
        return $this->getOpeningCurrencies();
    }

    public function getAvailableCurrenciesForChequeLimits()
    {
        return $this->getOpeningCurrencies();
    }

    // Message functionality helper methods
    public function hasInvoiceMessage()
    {
        return $this->showMessageField && ! empty($this->message);
    }

    public function getInvoiceMessage()
    {
        return $this->hasInvoiceMessage() ? $this->message : null;
    }

    public function setInvoiceMessage($message, $enabled = true)
    {
        $this->update([
            'showMessageField' => $enabled,
            'message' => $enabled ? $message : null,
        ]);
    }

    public function disableInvoiceMessage()
    {
        $this->update([
            'showMessageField' => false,
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
        return ! is_null($this->contacts_id);
    }

    public function getPrimaryContactInfo()
    {
        if (! $this->hasPrimaryContact()) {
            return;
        }

        $contact = $this->primaryContact;

        return [
            'id' => $contact->id,
            'name' => $contact->getFullNameAttribute(),
            'phone' => $contact->getPrimaryPhoneAttribute(),
            'email' => $contact->email,
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

        return round($size, 2).' '.$units[$unit];
    }

    public function hasAttachments()
    {
        return $this->attachments()->exists();
    }
}
