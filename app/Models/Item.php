<?php

namespace App\Models;

use App\Enums\ItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Item extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'items';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'parent_id',
        'trade_id',
        'company_code_id',
        'product_line_id',
        'category_id',
        'brand_id',
        'discount_percent',
        'max_discount',
        'active',
        'allow_discount',
        'allow_credit',
        'allow_return',
        'available_for_sale',
        'raw_material',
        'produced_item',
        // purchase/sales parameters removed
        'purchase_description',
        'purchase_uom_id',
        'sales_description',
        'pos_description',
        'sales_uom_id',
        'type',
        'base_uom_id',
    ];

    protected $casts = [
        'type' => ItemType::class,
        'discount_percent' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'active' => 'boolean',
        'allow_discount' => 'boolean',
        'allow_credit' => 'boolean',
        'allow_return' => 'boolean',
        'available_for_sale' => 'boolean',
        'raw_material' => 'boolean',
        'produced_item' => 'boolean',
    ];

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function parent()
    {
        return $this->belongsTo(Item::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Item::class, 'parent_id');
    }

    public function attachments()
    {
        return $this->hasMany(ItemAttachment::class);
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'item_supplier')
            ->withPivot(['original_code', 'currency', 'cost', 'is_primary'])
            ->withTimestamps();
    }

    public function baseUom()
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'base_uom_id');
    }

    public function purchaseUom()
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'purchase_uom_id');
    }

    public function salesUom()
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'sales_uom_id');
    }

    public function trade()
    {
        return $this->belongsTo(Trade::class, 'trade_id');
    }

    public function companyCode()
    {
        return $this->belongsTo(CompanyCode::class, 'company_code_id');
    }

    public function productLine()
    {
        return $this->belongsTo(ProductLine::class, 'product_line_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function unitOfMeasurements()
    {
        return $this->belongsToMany(UnitOfMeasurement::class, 'item_unit_of_measurement')
            ->using(ItemUnitOfMeasurement::class)
            ->withPivot([
                'operation', 'conversion',
                'barcodes',
                'price_1', 'price_2', 'price_3', 'price_4', 'price_5', 'price_6',
                'gross_volume', 'gross_weight',
                'net_volume', 'net_weight',
            ])
            ->withTimestamps();
    }

    // Many-to-many relationship with customer master lists
    public function customerMasterLists()
    {
        return $this->belongsToMany(CustomerMasterList::class, 'customer_master_list_item')
            ->withTimestamps();
    }

    public function service()
    {
        return $this->hasOne(Service::class, 'item_id');
    }
}
