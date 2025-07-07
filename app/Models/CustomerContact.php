<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'title',
        'name',
        'work_phone',
        'mobile',
        'email',
        'position',
        'extension',
    ];

    /**
     * Get the customer that owns the contact.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get customers that have this contact as their primary contact.
     */
    public function primaryForCustomers()
    {
        return $this->hasMany(Customer::class, 'contacts_id');
    }

    /**
     * Get the full name with title.
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->title . ' ' . $this->name);
    }

    /**
     * Get the primary phone number (mobile preferred, then work phone).
     */
    public function getPrimaryPhoneAttribute(): ?string
    {
        return $this->mobile ?: $this->work_phone;
    }

    /**
     * Check if this contact has any phone number.
     */
    public function hasPhone(): bool
    {
        return !empty($this->mobile) || !empty($this->work_phone);
    }

    /**
     * Check if this contact has email.
     */
    public function hasEmail(): bool
    {
        return !empty($this->email);
    }
}
