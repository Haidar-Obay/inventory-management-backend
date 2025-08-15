<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'title',
        'name',
        'work_phone',
        'mobile',
        'position',
        'extension',
        'is_primary',
    ];

    /**
     * Get the supplier that owns the contact.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get suppliers that have this contact as their primary contact.
     */
    public function primaryForSuppliers()
    {
        return $this->hasMany(Supplier::class, 'contacts_id');
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
        return false; // Email field doesn't exist in database
    }
}
