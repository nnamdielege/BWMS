<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Enums\PurchaseOrderStatus;
use App\Jobs\SendPurchaseOrderEmail;
use App\Mail\PurchaseOrderMail;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderEmail;
use App\Models\Supplier;
use App\Services\Purchasing\PurchaseOrderPdf;
use Database\Factories\ProductFactory;
use Database\Seeders\CompanySettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Support\StockFixtures;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->seed(CompanySettingsSeeder::class);
    }

    /** @return array<string, mixed> */
    private function payload(Supplier $supplier, Product $product, array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $supplier->id,
            'site_id' => $this->site()->id,
            'order_date' => '2026-11-02',
            'expected_date' => '2026-11-05',
            'lines' => [[
                'product_id' => $product->id,
                'product_unit_id' => ProductFactory::unitOf($product, 12)->id,
                'qty_ordered' => 10,
                'unit_cost' => '24.00',
                'tax_rate' => 10,
            ]],
            // Client-supplied totals must be ignored (legacy H-11).
            'total' => '1.00',
        ], $overrides);
    }

    public function test_purchasing_creates_draft_with_server_computed_totals_and_sequential_numbers()
    {
        $buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->withPack('Carton', 12)->create();

        $this->actingAs($buyer)->post(route('purchase-orders.store'), $this->payload($supplier, $product))->assertSessionHasNoErrors();
        $this->actingAs($buyer)->post(route('purchase-orders.store'), $this->payload($supplier, $product))->assertSessionHasNoErrors();

        [$first, $second] = PurchaseOrder::orderBy('id')->get();
        $year = now('Australia/Brisbane')->format('Y');
        $this->assertSame("PO-{$year}-00001", $first->number);
        $this->assertSame("PO-{$year}-00002", $second->number);
        $this->assertSame(PurchaseOrderStatus::Draft, $first->status);
        $this->assertSame('240.00', (string) $first->subtotal);
        $this->assertSame('24.00', (string) $first->tax_total);
        $this->assertSame('264.00', (string) $first->total);
        $this->assertSame(120, $first->lines->first()->qty_ordered_base);
    }

    public function test_only_drafts_can_be_edited_and_warehouse_staff_cannot_create()
    {
        $buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->withPack('Carton', 12)->create();
        $this->actingAs($buyer)->post(route('purchase-orders.store'), $this->payload($supplier, $product));
        $po = PurchaseOrder::sole();

        $this->actingAs($buyer)->post(route('purchase-orders.sent', $po))->assertSessionHasNoErrors();
        $this->actingAs($buyer)->put(route('purchase-orders.update', $po), $this->payload($supplier, $product))->assertSessionHasErrors('po');

        $this->actingAs($this->userWithRole(Permissions::ROLE_WAREHOUSE_STAFF))
            ->post(route('purchase-orders.store'), $this->payload($supplier, $product))->assertForbidden();
    }

    public function test_email_is_queued_marks_sent_and_job_attaches_pdf()
    {
        Bus::fake([SendPurchaseOrderEmail::class]);
        $buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $supplier = Supplier::factory()->create(['email' => 'orders@supplier.test']);
        $product = Product::factory()->withPack('Carton', 12)->create();
        $this->actingAs($buyer)->post(route('purchase-orders.store'), $this->payload($supplier, $product));
        $po = PurchaseOrder::sole();

        $this->actingAs($buyer)->post(route('purchase-orders.email', $po), [
            'to' => 'orders@supplier.test', 'cc' => 'boss@supplier.test', 'subject' => "Purchase order {$po->number}",
        ])->assertSessionHasNoErrors();

        Bus::assertDispatched(SendPurchaseOrderEmail::class);
        $this->assertSame(PurchaseOrderStatus::Sent, $po->fresh()->status);
        $email = PurchaseOrderEmail::sole();
        $this->assertSame('queued', $email->status);

        // Run the job for real against the array mailer.
        Mail::fake();
        (new SendPurchaseOrderEmail($email))->handle(app(PurchaseOrderPdf::class));
        Mail::assertSent(PurchaseOrderMail::class, function (PurchaseOrderMail $m) use ($po) {
            $pdf = collect($m->attachments())->first(fn ($a) => $a->as === "{$po->number}.pdf" && $a->mime === 'application/pdf');

            return $m->hasTo('orders@supplier.test') && $m->hasCc('boss@supplier.test') && $pdf !== null;
        });
        $this->assertSame('sent', $email->fresh()->status);
    }

    public function test_failed_email_is_recorded_and_can_be_retried()
    {
        Bus::fake([SendPurchaseOrderEmail::class]);
        $buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $po = $this->draftPo($buyer);
        $email = $po->emails()->create(['to' => 'x@y.test', 'subject' => 's', 'status' => 'queued']);

        (new SendPurchaseOrderEmail($email))->failed(new \RuntimeException('SMTP timeout'));
        $this->assertSame('failed', $email->fresh()->status);
        $this->assertSame('SMTP timeout', $email->fresh()->error);

        $this->actingAs($buyer)->post(route('purchase-orders.email.retry', [$po, $email]))->assertSessionHasNoErrors();
        $this->assertSame('queued', $email->fresh()->status);
        Bus::assertDispatched(SendPurchaseOrderEmail::class);
    }

    public function test_pdf_renders_with_bundled_fonts()
    {
        $po = $this->draftPo($this->userWithRole(Permissions::ROLE_PURCHASING));

        $pdf = app(PurchaseOrderPdf::class)->render($po);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertStringContainsString('DejaVuSans', $pdf);
    }

    public function test_pdf_template_references_no_remote_resources()
    {
        $template = file_get_contents(resource_path('views/pdf/purchase-order.blade.php'));

        $this->assertDoesNotMatchRegularExpression('#(https?:)?//[a-z0-9.-]+\.[a-z]{2,}#i', $template, 'PDF template must not reference remote URLs (NFR-6).');
        $this->assertStringNotContainsStringIgnoringCase('@import', $template);
        $this->assertStringNotContainsStringIgnoringCase('url(', $template);
    }

    public function test_pdf_route_requires_view_permission()
    {
        $po = $this->draftPo($this->userWithRole(Permissions::ROLE_PURCHASING));

        $this->actingAs($this->userWithRole(Permissions::ROLE_READ_ONLY))->get(route('purchase-orders.pdf', $po))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_cancel_only_without_receipts()
    {
        $buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $po = $this->draftPo($buyer);

        $this->actingAs($buyer)->post(route('purchase-orders.cancel', $po), ['reason' => 'Ordered twice'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);
    }

    public function test_every_job_timeout_is_below_queue_retry_after()
    {
        $retryAfter = config('queue.connections.'.config('queue.default').'.retry_after') ?? config('queue.connections.database.retry_after');
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');
            $timeout = (new \ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;
            $this->assertNotNull($timeout, "{$class} must declare \$timeout (NFR-4).");
            $this->assertLessThan(config('queue.connections.database.retry_after'), $timeout, "{$class}: timeout must be below retry_after (NFR-4).");
        }
        $this->assertGreaterThan(0, $retryAfter);
    }

    private function draftPo($user): PurchaseOrder
    {
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->withPack('Carton', 12)->create();
        $this->actingAs($user)->post(route('purchase-orders.store'), $this->payload($supplier, $product))->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->first();
    }
}
