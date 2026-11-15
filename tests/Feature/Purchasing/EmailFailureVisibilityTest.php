<?php

namespace Tests\Feature\Purchasing;

use App\Authorization\Permissions;
use App\Jobs\SendPurchaseOrderEmail;
use App\Mail\PurchaseOrderMail;
use App\Mail\TestMail;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderEmail;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderPdf;
use App\Services\Purchasing\PurchaseOrderService;
use Database\Factories\ProductFactory;
use Database\Seeders\CompanySettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\StockFixtures;
use Tests\TestCase;

/** Email failures are never silent: on the PO, via a test email, and as a warning for a non-delivering mailer. */
class EmailFailureVisibilityTest extends TestCase
{
    use RefreshDatabase, StockFixtures;

    private User $buyer;

    private PurchaseOrder $po;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStockReferenceData();
        $this->seed(CompanySettingsSeeder::class);
        $this->buyer = $this->userWithRole(Permissions::ROLE_PURCHASING);
        $product = Product::factory()->withPack('Carton', 12)->create();
        $this->po = app(PurchaseOrderService::class)->saveDraft(null, [
            'supplier_id' => Supplier::factory()->create()->id, 'site_id' => $this->site()->id, 'order_date' => '2026-11-13',
            'lines' => [['product_id' => $product->id, 'product_unit_id' => ProductFactory::unitOf($product, 12)->id, 'qty_ordered' => 1, 'unit_cost' => '5.00']],
        ], $this->buyer);

        // A mail server that refuses everything.
        Mail::extend('refusing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Expected response code "250" but got code "535", with message "535 5.7.8 Authentication failed".');
            }

            public function __toString(): string
            {
                return 'refusing://';
            }
        });
        config(['mail.mailers.refusing' => ['transport' => 'refusing']]);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = 'testing';
        parent::tearDown();
    }

    private function email(): PurchaseOrderEmail
    {
        return $this->po->emails()->create(['to' => 'orders@supplier.test', 'subject' => "Purchase order {$this->po->number}", 'status' => 'queued']);
    }

    public function test_a_failed_send_shows_its_reason_on_the_po_straight_away_and_after_the_last_try()
    {
        config(['mail.default' => 'refusing']);
        $email = $this->email();
        $job = new SendPurchaseOrderEmail($email);

        try {
            $job->handle(app(PurchaseOrderPdf::class));
            $this->fail('The send should have thrown so the queue retries it.');
        } catch (TransportException) {
        }
        $this->assertSame('queued', $email->fresh()->status, 'still being retried');
        $this->assertStringContainsString('535 5.7.8 Authentication failed', $email->fresh()->error);

        $this->actingAs($this->buyer)->get(route('purchase-orders.index'))
            ->assertInertia(fn ($p) => $p->where('orders.data.0.email_error', fn ($e) => str_contains($e, 'Authentication failed')));

        $job->failed(new TransportException('Connection could not be established with host "smtp.example.test:587"'));
        $this->actingAs($this->buyer)->get(route('purchase-orders.show', $this->po))
            ->assertInertia(fn ($p) => $p->where('po.emails.0.status', 'failed')
                ->where('po.emails.0.error', 'Connection could not be established with host "smtp.example.test:587"'));
    }

    public function test_a_later_successful_send_clears_the_warning()
    {
        $this->email()->forceFill(['status' => 'failed', 'error' => 'boom'])->save();
        Mail::fake();
        (new SendPurchaseOrderEmail($this->email()))->handle(app(PurchaseOrderPdf::class));
        Mail::assertSent(PurchaseOrderMail::class);

        $this->actingAs($this->buyer)->get(route('purchase-orders.index'))->assertInertia(fn ($p) => $p->where('orders.data.0.email_error', null));
    }

    public function test_in_production_a_log_mailer_fails_the_email_instead_of_pretending()
    {
        $this->app['env'] = 'production';
        config(['mail.default' => 'log']);
        Mail::fake();
        $email = $this->email();

        (new SendPurchaseOrderEmail($email))->handle(app(PurchaseOrderPdf::class));

        Mail::assertNothingSent();
        $this->assertSame('failed', $email->fresh()->status);
        $this->assertStringContainsString('MAIL_MAILER is "log"', $email->fresh()->error);
    }

    public function test_send_test_email_reports_success_to_the_logged_in_user()
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->actingAs($admin)->from(route('admin.settings.edit'))->followingRedirects()->post(route('admin.settings.test-email'))
            ->assertInertia(fn ($p) => $p->where('testEmail.ok', true)->where('testEmail.to', $admin->email)
                ->where('testEmail.message', fn ($m) => str_contains($m, "sent to {$admin->email}")));
        Mail::assertSent(TestMail::class, fn (TestMail $m) => $m->hasTo($admin->email));
    }

    public function test_send_test_email_shows_the_exact_error()
    {
        config(['mail.default' => 'refusing']);
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);

        $this->actingAs($admin)->from(route('admin.settings.edit'))->followingRedirects()->post(route('admin.settings.test-email'))
            ->assertInertia(fn ($p) => $p->where('testEmail.ok', false)
                ->where('testEmail.message', 'Expected response code "250" but got code "535", with message "535 5.7.8 Authentication failed".'));

        config(['mail.default' => 'log']);
        $this->actingAs($admin)->from(route('admin.settings.edit'))->followingRedirects()->post(route('admin.settings.test-email'))
            ->assertInertia(fn ($p) => $p->where('testEmail.ok', false)->where('testEmail.message', fn ($m) => str_contains($m, 'MAIL_MAILER is "log"')));

        $this->actingAs($this->buyer)->post(route('admin.settings.test-email'))->assertForbidden();
    }

    public function test_sync_and_settings_warn_about_a_non_delivering_mailer_in_production_only()
    {
        $admin = $this->userWithRole(Permissions::ROLE_ADMIN);
        config(['mail.default' => 'array']);
        $this->actingAs($admin)->get(route('sync.index'))->assertInertia(fn ($p) => $p->where('switches.mail_problem', null));

        $this->app['env'] = 'production';
        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->actingAs($admin)->get(route('sync.index'))
                ->assertInertia(fn ($p) => $p->where('switches.mail_problem', fn ($w) => str_contains($w, "MAIL_MAILER is \"{$mailer}\"")));
            $this->actingAs($admin)->get(route('admin.settings.edit'))
                ->assertInertia(fn ($p) => $p->where('mail.problem', fn ($w) => str_contains($w, "MAIL_MAILER is \"{$mailer}\"")));
        }

        config(['mail.default' => 'smtp']);
        $this->actingAs($admin)->get(route('sync.index'))->assertInertia(fn ($p) => $p->where('switches.mail_problem', null));
    }
}
