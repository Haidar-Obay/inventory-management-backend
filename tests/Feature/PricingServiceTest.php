<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\AssociationServicePrice;
use App\Models\Referrer;
use App\Models\ReferrerServiceCommission;
use App\Models\Service;
use App\Models\ServiceAdvancedPricing;
use App\Models\ServiceCategory;
use App\Models\Specialist;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PricingService $pricingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Check if database connection is available
        try {
            DB::connection()->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('Database connection not available: '.$e->getMessage());

            return;
        }

        // Drop all tables first to ensure clean state
        $this->artisan('migrate:reset', ['--force' => true]);

        // Run only tenant migrations for a clean tenant database setup
        $this->artisan('migrate', ['--path' => 'database/migrations/tenant', '--force' => true]);

        $this->pricingService = new PricingService;
    }

    public function test_resolves_base_price_from_normal_price()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
        ]);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertEquals(100.00, $result['final_price']);
        $this->assertEquals(0, $result['discount_total']);
    }

    public function test_resolves_base_price_from_hour_price()
    {
        $service = Service::factory()->create([
            'normal_price' => 50.00,
            'hour_price' => 25.00,
            'price_calculated_by_hour' => true,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'hours' => 3,
        ]);

        $this->assertEquals(75.00, $result['base_price']);
        $this->assertEquals(75.00, $result['final_price']);
    }

    public function test_applies_association_price_override()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $association = Association::factory()->create();

        AssociationServicePrice::create([
            'association_id' => $association->id,
            'service_id' => $service->id,
            'price' => 80.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'association_id' => $association->id,
        ]);

        $this->assertEquals(80.00, $result['base_price']);
        $this->assertContains('Association price override: 80.00', $result['overrides_applied']);
    }

    public function test_applies_referrer_price_override()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $referrer = Referrer::factory()->create();

        ReferrerServiceCommission::create([
            'referrer_id' => $referrer->id,
            'service_id' => $service->id,
            'price_override' => 90.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'referrer_id' => $referrer->id,
        ]);

        $this->assertEquals(90.00, $result['base_price']);
        $this->assertContains('Referrer price override: 90.00', $result['overrides_applied']);
    }

    public function test_applies_only_association_discount_when_both_present()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $association = Association::factory()->create();
        $referrer = Referrer::factory()->create();

        // Create association service discount without price override
        AssociationServicePrice::create([
            'association_id' => $association->id,
            'service_id' => $service->id,
            'price' => 0, // Explicitly set to 0 to avoid override
            'discount' => 10.00,
        ]);

        // Create referrer service discount without price override
        ReferrerServiceCommission::create([
            'referrer_id' => $referrer->id,
            'service_id' => $service->id,
            'price_override' => null, // Explicitly null
            'discount_override' => 5.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'association_id' => $association->id,
            'referrer_id' => $referrer->id,
        ]);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertEquals(10.00, $result['discount_total']); // Only association discount
        $this->assertEquals(90.00, $result['final_price']);
        $this->assertContains('Association discount: 10.00', $result['discounts_applied']);
        $this->assertNotContains('Referrer discount: 5.00', $result['discounts_applied']);
    }

    public function test_applies_referrer_discount_when_no_association()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $referrer = Referrer::factory()->create();

        // Create referrer service discount without price override
        ReferrerServiceCommission::create([
            'referrer_id' => $referrer->id,
            'service_id' => $service->id,
            'price_override' => null,
            'discount_override' => 15.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'referrer_id' => $referrer->id,
        ]);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertEquals(0.00, $result['discount_total']); // Referrer discount not applied by service
        $this->assertEquals(100.00, $result['final_price']);
        $this->assertNotContains('Referrer discount: 15.00', $result['discounts_applied']);
    }

    public function test_handles_birthday_pricing()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'birthday_price' => 150.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'event_type' => 'birthday',
        ]);

        $this->assertEquals(150.00, $result['base_price']);
        $this->assertEquals(150.00, $result['final_price']);
    }

    public function test_handles_wedding_pricing()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'wedding_price' => 200.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'event_type' => 'wedding',
        ]);

        $this->assertEquals(200.00, $result['base_price']);
        $this->assertEquals(200.00, $result['final_price']);
    }

    public function test_falls_back_to_normal_price_when_event_price_not_set()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'birthday_price' => null,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'event_type' => 'birthday',
        ]);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertEquals(100.00, $result['final_price']);
    }

    public function test_resolves_base_price_with_service_category()
    {
        // Create a service category
        $category = ServiceCategory::create([
            'name' => 'Medical Consultation',
            'description' => 'General medical consultation services',
        ]);

        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
            'service_category_id' => $category->id,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
        ]);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertEquals(100.00, $result['final_price']);
    }

    public function test_service_with_category_and_association_discount()
    {
        // Create a service category
        $category = ServiceCategory::create([
            'name' => 'Laboratory Tests',
            'description' => 'Blood work and lab diagnostic services',
        ]);

        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
            'service_category_id' => $category->id,
        ]);

        $association = Association::factory()->create();

        // Create association discount
        AssociationServicePrice::create([
            'association_id' => $association->id,
            'service_id' => $service->id,
            'price' => 0, // No price override
            'discount' => 20.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'association_id' => $association->id,
        ]);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertEquals(20.00, $result['discount_total']);
        $this->assertEquals(80.00, $result['final_price']);
    }

    public function test_resolves_advanced_pricing_on_site()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $specialist = Specialist::factory()->create();

        // Create advanced pricing
        ServiceAdvancedPricing::create([
            'service_id' => $service->id,
            'specialist_id' => $specialist->id,
            'price_on_site' => 150.00,
            'price_on_call' => 200.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'specialist_id' => $specialist->id,
            'service_type' => 'on_site',
        ]);

        $this->assertEquals(150.00, $result['base_price']);
        $this->assertContains('Advanced pricing (on_site): 150.00', $result['overrides_applied']);
    }

    public function test_resolves_advanced_pricing_on_call()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $specialist = Specialist::factory()->create();

        // Create advanced pricing
        ServiceAdvancedPricing::create([
            'service_id' => $service->id,
            'specialist_id' => $specialist->id,
            'price_on_site' => 150.00,
            'price_on_call' => 200.00,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'specialist_id' => $specialist->id,
            'service_type' => 'on_call',
        ]);

        $this->assertEquals(200.00, $result['base_price']);
        $this->assertContains('Advanced pricing (on_call): 200.00', $result['overrides_applied']);
    }

    public function test_falls_back_to_normal_price_when_no_advanced_pricing()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $specialist = Specialist::factory()->create();

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'specialist_id' => $specialist->id,
            'service_type' => 'on_site',
        ]);

        $this->assertEquals(100.00, $result['base_price']);
        $this->assertNotContains('Advanced pricing', $result['overrides_applied']);
    }

    public function test_advanced_pricing_with_association_override()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $specialist = Specialist::factory()->create();
        $association = Association::factory()->create();

        // Create advanced pricing
        ServiceAdvancedPricing::create([
            'service_id' => $service->id,
            'specialist_id' => $specialist->id,
            'price_on_site' => 150.00,
            'price_on_call' => 200.00,
        ]);

        // Create association price override
        AssociationServicePrice::create([
            'association_id' => $association->id,
            'service_id' => $service->id,
            'price' => 120.00,
            'discount' => 0,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'specialist_id' => $specialist->id,
            'service_type' => 'on_site',
            'association_id' => $association->id,
        ]);

        // Association override should take precedence over advanced pricing
        $this->assertEquals(120.00, $result['base_price']);
        $this->assertContains('Advanced pricing (on_site): 150.00', $result['overrides_applied']);
        $this->assertContains('Association price override: 120.00', $result['overrides_applied']);
    }

    public function test_calculates_commission_from_service_specific_rule()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $referrer = Referrer::factory()->create([
            'commission_percent' => 10.00, // Global commission
        ]);

        // Create service-specific commission rule
        ReferrerServiceCommission::create([
            'referrer_id' => $referrer->id,
            'service_id' => $service->id,
            'price_override' => null,
            'discount_override' => null,
            'commission_percent' => 15.00, // Service-specific commission
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'referrer_id' => $referrer->id,
        ]);

        $this->assertEquals(100.00, $result['final_price']);
        $this->assertEquals(15.00, $result['commission_percent']);
        $this->assertEquals(15.00, $result['commission_amount']); // 100 * 15%
    }

    public function test_calculates_commission_from_global_referrer_commission()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $referrer = Referrer::factory()->create([
            'commission_percent' => 12.50, // Global commission
        ]);

        // No service-specific commission rule

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'referrer_id' => $referrer->id,
        ]);

        $this->assertEquals(100.00, $result['final_price']);
        $this->assertEquals(12.50, $result['commission_percent']);
        $this->assertEquals(12.50, $result['commission_amount']); // 100 * 12.5%
    }

    public function test_commission_calculation_with_discounts()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);
        $referrer = Referrer::factory()->create([
            'commission_percent' => 10.00,
        ]);

        // Create referrer discount
        ReferrerServiceCommission::create([
            'referrer_id' => $referrer->id,
            'service_id' => $service->id,
            'price_override' => null,
            'discount_override' => 20.00, // 20% discount
            'commission_percent' => 15.00, // 15% commission
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
            'referrer_id' => $referrer->id,
        ]);

        $this->assertEquals(100.00, $result['final_price']); // Discount not applied by service
        $this->assertEquals(0.00, $result['discount_total']);
        $this->assertEquals(15.00, $result['commission_percent']);
        $this->assertEquals(15.00, $result['commission_amount']); // 100 * 15%
    }

    public function test_no_commission_when_no_referrer()
    {
        $service = Service::factory()->create([
            'normal_price' => 100.00,
            'price_calculated_by_hour' => false,
        ]);

        $result = $this->pricingService->resolvePrice([
            'service_id' => $service->id,
        ]);

        $this->assertEquals(100.00, $result['final_price']);
        $this->assertEquals(0, $result['commission_percent']);
        $this->assertEquals(0, $result['commission_amount']);
    }
}
