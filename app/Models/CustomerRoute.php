<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'salesman_id',
        'frequency',
        'day_value',
        'active',
        'start_date',
        'end_date',
        'notes',
    ];

    protected $casts = [
        'active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'day_value' => 'integer',
    ];

    /**
     * Get the customer that owns the route.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the salesman assigned to this route.
     */
    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /**
     * Scope a query to only include active routes.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope a query to only include routes for a specific frequency.
     */
    public function scopeFrequency($query, $frequency)
    {
        return $query->where('frequency', $frequency);
    }

    /**
     * Scope a query to only include routes for a specific day value.
     */
    public function scopeDayValue($query, $dayValue)
    {
        return $query->where('day_value', $dayValue);
    }

    /**
     * Scope a query to only include routes for a specific salesman.
     */
    public function scopeSalesman($query, $salesmanId)
    {
        return $query->where('salesman_id', $salesmanId);
    }

    /**
     * Scope a query to only include routes for a specific customer.
     */
    public function scopeCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Get the day name for weekly frequency.
     */
    public function getDayNameAttribute(): string
    {
        if ($this->frequency === 'weekly') {
            $dayNames = [
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
                7 => 'Sunday',
            ];

            return $dayNames[$this->day_value] ?? 'Unknown';
        }

        return $this->day_value.($this->frequency === 'monthly' ? 'th' : '');
    }

    /**
     * Get the frequency description.
     */
    public function getFrequencyDescriptionAttribute(): string
    {
        $descriptions = [
            'weekly' => 'Every week',
            'biweekly' => 'Every 2 weeks',
            'monthly' => 'Every month',
        ];

        return $descriptions[$this->frequency] ?? 'Unknown';
    }

    /**
     * Get the schedule description.
     */
    public function getScheduleDescriptionAttribute(): string
    {
        if ($this->frequency === 'weekly') {
            return "Every {$this->day_name}";
        } elseif ($this->frequency === 'biweekly') {
            return "Every 2 weeks on day {$this->day_value}";
        } else {
            return "Every month on day {$this->day_value}";
        }
    }

    /**
     * Check if the route is currently active based on dates.
     */
    public function isCurrentlyActive(): bool
    {
        if (! $this->active) {
            return false;
        }

        $now = Carbon::now();

        if ($this->start_date && $now->lt($this->start_date)) {
            return false;
        }

        if ($this->end_date && $now->gt($this->end_date)) {
            return false;
        }

        return true;
    }

    /**
     * Get the next visit date for this route.
     */
    public function getNextVisitDate(): ?Carbon
    {
        if (! $this->isCurrentlyActive()) {
            return null;
        }

        $now = Carbon::now();
        $startDate = $this->start_date ? Carbon::parse($this->start_date) : $now;

        if ($this->frequency === 'weekly') {
            $nextDate = $startDate->copy()->next($this->day_name);
            while ($nextDate->lt($now)) {
                $nextDate->addWeek();
            }

            return $nextDate;
        }

        if ($this->frequency === 'biweekly') {
            $nextDate = $startDate->copy()->startOfMonth()->addDays($this->day_value - 1);
            while ($nextDate->lt($now)) {
                $nextDate->addWeeks(2);
            }

            return $nextDate;
        }

        if ($this->frequency === 'monthly') {
            $nextDate = $startDate->copy()->startOfMonth()->addDays($this->day_value - 1);
            while ($nextDate->lt($now)) {
                $nextDate->addMonth();
            }

            return $nextDate;
        }

        return null;
    }

    /**
     * Get the previous visit date for this route.
     */
    public function getPreviousVisitDate(): ?Carbon
    {
        if (! $this->isCurrentlyActive()) {
            return null;
        }

        $now = Carbon::now();
        $startDate = $this->start_date ? Carbon::parse($this->start_date) : $now;

        if ($this->frequency === 'weekly') {
            $prevDate = $startDate->copy()->previous($this->day_name);
            while ($prevDate->gt($now)) {
                $prevDate->subWeek();
            }

            return $prevDate;
        }

        if ($this->frequency === 'biweekly') {
            $prevDate = $startDate->copy()->startOfMonth()->addDays($this->day_value - 1);
            while ($prevDate->gt($now)) {
                $prevDate->subWeeks(2);
            }

            return $prevDate;
        }

        if ($this->frequency === 'monthly') {
            $prevDate = $startDate->copy()->startOfMonth()->addDays($this->day_value - 1);
            while ($prevDate->gt($now)) {
                $prevDate->subMonth();
            }

            return $prevDate;
        }

        return null;
    }

    /**
     * Check if today is a visit day for this route.
     */
    public function isVisitDayToday(): bool
    {
        if (! $this->isCurrentlyActive()) {
            return false;
        }

        $today = Carbon::today();

        if ($this->frequency === 'weekly') {
            return $today->dayOfWeek === $this->day_value;
        }

        if ($this->frequency === 'biweekly') {
            // Check if today is the correct day and if it's been 2 weeks since start
            if ($today->day === $this->day_value) {
                $startDate = $this->start_date ? Carbon::parse($this->start_date) : $today;
                $weeksDiff = $startDate->diffInWeeks($today);

                return $weeksDiff % 2 === 0;
            }

            return false;
        }

        if ($this->frequency === 'monthly') {
            return $today->day === $this->day_value;
        }

        return false;
    }

    /**
     * Get all visit dates for a given date range.
     */
    public function getVisitDates($startDate, $endDate): array
    {
        if (! $this->isCurrentlyActive()) {
            return [];
        }

        $dates = [];
        $current = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        while ($current->lte($end)) {
            if ($this->isVisitDate($current)) {
                $dates[] = $current->copy();
            }
            $current->addDay();
        }

        return $dates;
    }

    /**
     * Check if a specific date is a visit date for this route.
     */
    private function isVisitDate(Carbon $date): bool
    {
        if ($this->frequency === 'weekly') {
            return $date->dayOfWeek === $this->day_value;
        }

        if ($this->frequency === 'biweekly') {
            if ($date->day === $this->day_value) {
                $startDate = $this->start_date ? Carbon::parse($this->start_date) : $date;
                $weeksDiff = $startDate->diffInWeeks($date);

                return $weeksDiff % 2 === 0;
            }

            return false;
        }

        if ($this->frequency === 'monthly') {
            return $date->day === $this->day_value;
        }

        return false;
    }
}
