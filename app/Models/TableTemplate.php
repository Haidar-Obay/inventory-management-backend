<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TableTemplate extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'table_name',
        'visible_columns',
        'column_widths',
        'column_order',
        'headerColor',
        'showHeaderSeparator',
        'showHeaderColSeparator',
        'showBodyColSeparator',
    ];

    protected $casts = [
        'visible_columns' => 'array',
        'column_widths' => 'array',
        'column_order' => 'array',
        'showHeaderSeparator' => 'boolean',
        'showHeaderColSeparator' => 'boolean',
        'showBodyColSeparator' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
        });
    }
}
