// Tester-env deterministic seed for Paymenter (prospect stage).
// Piped into `php artisan tinker` via stdin by ./tester-env seed.
// Idempotent: converges drift on re-run; deterministic after `down -v`
// (auto-increment ids restart, fixed timestamps/names/prices reused).
// Theme: small hosting shop ("Paymenter demo store").
// No `<?php` execution guards needed; tinker evaluates this file directly.


$tCat = '2026-01-05 10:00:00';
$tUser = '2026-01-06 10:00:00';
$tOrder = '2026-01-10 10:00:00';
$tInv = '2026-01-12 10:00:00';
$tMsg = '2026-01-15 10:00:00';

// No real email delivery in tester-env (rule: no external SaaS).
\App\Models\Setting::updateOrCreate(['key' => 'mail_disable'], ['value' => '1']);
\App\Providers\SettingsProvider::flushCache();

$cloud = \App\Models\Category::updateOrCreate(['slug' => 'cloud-hosting'], ['name' => 'Cloud Hosting', 'description' => 'Scalable virtual servers with NVMe storage.', 'created_at' => $tCat, 'updated_at' => $tCat]);
$web = \App\Models\Category::updateOrCreate(['slug' => 'web-hosting'], ['name' => 'Web Hosting', 'description' => 'Shared hosting for small websites.', 'created_at' => $tCat, 'updated_at' => $tCat]);

$mkProduct = function ($slug, $cat, $name, $desc, $hidden, $sort) use ($tCat) {
    return \App\Models\Product::updateOrCreate(['slug' => $slug], ['category_id' => $cat->id, 'name' => $name, 'description' => $desc, 'hidden' => $hidden, 'sort' => $sort, 'created_at' => $tCat, 'updated_at' => $tCat]);
};
$starterVps = $mkProduct('starter-vps', $cloud, 'Starter VPS', '2 vCPU, 4 GB RAM, 80 GB NVMe. Ideal first server.', false, 1);
$businessVps = $mkProduct('business-vps', $cloud, 'Business VPS', '4 vCPU, 8 GB RAM, 160 GB NVMe for growing workloads.', false, 2);
$sharedStarter = $mkProduct('shared-starter', $web, 'Shared Starter', '10 GB SSD hosting with free SSL for one website.', false, 1);
// Edge case: hidden legacy product (realistic discontinued-plan state).
$mkProduct('shared-legacy', $web, 'Shared Legacy', 'Discontinued 2024 plan, kept for existing customers.', true, 99);

$mkPlan = function ($product, $name, $type, $period, $unit, $amount) {
    $plan = \App\Models\Plan::firstOrNew(['priceable_id' => $product->id, 'priceable_type' => \App\Models\Product::class, 'name' => $name]);
    $plan->forceFill(['priceable_id' => $product->id, 'priceable_type' => \App\Models\Product::class, 'type' => $type, 'billing_period' => $period, 'billing_unit' => $unit])->save();
    $price = \App\Models\Price::firstOrNew(['plan_id' => $plan->id, 'currency_code' => 'USD']);
    $price->fill(['price' => $amount, 'setup_fee' => null])->save();

    return $plan->fresh();
};
$planMonthlyStarter = $mkPlan($starterVps, 'Monthly', 'recurring', 1, 'month', '9.99');
$mkPlan($starterVps, 'Yearly', 'recurring', 1, 'year', '99.99');
$mkPlan($businessVps, 'Monthly', 'recurring', 1, 'month', '24.99');
$planMonthlyShared = $mkPlan($sharedStarter, 'Monthly', 'recurring', 1, 'month', '4.99');

$mkClient = function ($email, $first, $last) use ($tUser) {
    $user = \App\Models\User::firstOrCreate(['email' => $email], ['first_name' => $first, 'last_name' => $last, 'password' => \Illuminate\Support\Facades\Hash::make('ClientPass123!'), 'role_id' => null, 'email_verified_at' => $tUser, 'created_at' => $tUser, 'updated_at' => $tUser]);
    $user->forceFill(['first_name' => $first, 'last_name' => $last, 'role_id' => null, 'email_verified_at' => $tUser, 'created_at' => $tUser, 'updated_at' => $tUser])->save();

    return $user->fresh();
};
$admin = \App\Models\User::where('email', 'admin@example.com')->first();
$amelia = $mkClient('amelia.hart@example.com', 'Amelia', 'Hart');
$daniel = $mkClient('daniel.okafor@example.com', 'Daniel', 'Okafor');

$order1 = \App\Models\Order::firstOrCreate(['user_id' => $amelia->id, 'currency_code' => 'USD'], ['created_at' => $tOrder, 'updated_at' => $tOrder]);
$order1->update(['created_at' => $tOrder, 'updated_at' => $tOrder]);
$order2 = \App\Models\Order::firstOrCreate(['user_id' => $daniel->id, 'currency_code' => 'USD'], ['created_at' => $tOrder, 'updated_at' => $tOrder]);
$order2->update(['created_at' => $tOrder, 'updated_at' => $tOrder]);

$svc1 = \App\Models\Service::firstOrCreate(['user_id' => $amelia->id, 'product_id' => $starterVps->id], ['order_id' => $order1->id, 'plan_id' => $planMonthlyStarter->id, 'quantity' => 1, 'price' => 9.99, 'currency_code' => 'USD', 'status' => \App\Models\Service::STATUS_PENDING, 'created_at' => $tOrder, 'updated_at' => $tOrder]);
$svc2 = \App\Models\Service::firstOrCreate(['user_id' => $daniel->id, 'product_id' => $sharedStarter->id], ['order_id' => $order2->id, 'plan_id' => $planMonthlyShared->id, 'quantity' => 1, 'price' => 4.99, 'currency_code' => 'USD', 'status' => \App\Models\Service::STATUS_PENDING, 'created_at' => $tOrder, 'updated_at' => $tOrder]);

// Paid invoice for Amelia, linked to her service (paid transition activates it).
$inv1 = \App\Models\Invoice::firstOrCreate(['user_id' => $amelia->id, 'status' => \App\Models\Invoice::STATUS_PAID], ['currency_code' => 'USD', 'due_at' => '2026-02-01', 'created_at' => $tInv, 'updated_at' => $tInv]);
$inv1->send_create_email = false;
$inv1->fill(['currency_code' => 'USD', 'due_at' => '2026-02-01', 'created_at' => $tInv, 'updated_at' => $tInv])->save();
$inv1->items()->delete();
$inv1->items()->create(['quantity' => 1, 'price' => 9.99, 'description' => 'Starter VPS - Monthly (January 2026)', 'reference_id' => $svc1->id, 'reference_type' => \App\Models\Service::class]);
$inv1->transactions()->delete();
$inv1->transactions()->create(['amount' => 9.99, 'status' => \App\Enums\InvoiceTransactionStatus::Succeeded, 'transaction_id' => 'MANUAL-2026-0001']);
if ($inv1->status !== \App\Models\Invoice::STATUS_PAID) {
    $inv1->status = \App\Models\Invoice::STATUS_PAID;
    $inv1->send_create_email = false;
    $inv1->save();
}

// Unpaid invoice for Daniel (visible outstanding balance).
$inv2 = \App\Models\Invoice::firstOrCreate(['user_id' => $daniel->id, 'status' => \App\Models\Invoice::STATUS_PENDING], ['currency_code' => 'USD', 'due_at' => '2027-02-10', 'created_at' => $tInv, 'updated_at' => $tInv]);
$inv2->send_create_email = false;
$inv2->fill(['currency_code' => 'USD', 'due_at' => '2027-02-10', 'created_at' => $tInv, 'updated_at' => $tInv])->save();
$inv2->items()->delete();
$inv2->items()->create(['quantity' => 1, 'price' => 4.99, 'description' => 'Shared Starter - Monthly (January 2026)', 'reference_id' => $svc2->id, 'reference_type' => \App\Models\Service::class]);
$inv2->transactions()->delete();

// Pin service states with fixed timestamps (bypass events: the paid-invoice
// renewal uses now(), which would drift expires_at between runs).
\Illuminate\Support\Facades\DB::table('services')->where('id', $svc1->id)->update(['order_id' => $order1->id, 'plan_id' => $planMonthlyStarter->id, 'quantity' => 1, 'price' => 9.99, 'currency_code' => 'USD', 'status' => \App\Models\Service::STATUS_ACTIVE, 'expires_at' => '2027-02-01 10:00:00', 'created_at' => $tOrder, 'updated_at' => $tInv]);
\Illuminate\Support\Facades\DB::table('services')->where('id', $svc2->id)->update(['order_id' => $order2->id, 'plan_id' => $planMonthlyShared->id, 'quantity' => 1, 'price' => 4.99, 'currency_code' => 'USD', 'status' => \App\Models\Service::STATUS_PENDING, 'expires_at' => null, 'created_at' => $tOrder, 'updated_at' => $tOrder]);

$t1 = \App\Models\Ticket::updateOrCreate(['subject' => 'Cannot SSH into my VPS'], ['user_id' => $amelia->id, 'status' => 'open', 'priority' => 'high', 'department' => 'Technical Support', 'service_id' => $svc1->id, 'created_at' => $tMsg, 'updated_at' => $tMsg]);
$t1->messages()->delete();
$t1->messages()->create(['user_id' => $amelia->id, 'message' => 'Hello, I cannot SSH into my Starter VPS since this morning. Port 22 times out. Can you check the firewall?', 'created_at' => $tMsg, 'updated_at' => $tMsg]);
$t1->messages()->create(['user_id' => ($admin ? $admin->id : $amelia->id), 'message' => 'Hi Amelia, we found a stale firewall rule on your node and cleared it. Please try again and let us know.', 'created_at' => '2026-01-16 10:00:00', 'updated_at' => '2026-01-16 10:00:00']);
// Message listeners flip status; pin the intended end state quietly.
\Illuminate\Support\Facades\DB::table('tickets')->where('id', $t1->id)->update(['status' => 'replied', 'updated_at' => '2026-01-16 10:00:00']);

$t2 = \App\Models\Ticket::updateOrCreate(['subject' => 'Question about renewal date'], ['user_id' => $daniel->id, 'status' => 'open', 'priority' => 'low', 'department' => 'Billing', 'service_id' => null, 'created_at' => $tMsg, 'updated_at' => $tMsg]);
$t2->messages()->delete();
$t2->messages()->create(['user_id' => $daniel->id, 'message' => 'Hi, when does my Shared Starter renew? I want to align it with payday.', 'created_at' => $tMsg, 'updated_at' => $tMsg]);
$t2->messages()->create(['user_id' => ($admin ? $admin->id : $daniel->id), 'message' => 'Hi Daniel, your plan renews monthly on the 10th. We noted your preference on the account.', 'created_at' => '2026-01-17 10:00:00', 'updated_at' => '2026-01-17 10:00:00']);
\Illuminate\Support\Facades\DB::table('tickets')->where('id', $t2->id)->update(['status' => 'closed', 'updated_at' => '2026-01-17 10:00:00']);

\App\Models\Coupon::updateOrCreate(['code' => 'WELCOME10'], ['type' => 'percentage', 'applies_to' => 'all', 'value' => 10, 'created_at' => $tCat, 'updated_at' => $tCat]);

echo 'seed-ok categories='.\App\Models\Category::count().' products='.\App\Models\Product::count().' users='.\App\Models\User::count().' orders='.\App\Models\Order::count().' services='.\App\Models\Service::count().' invoices='.\App\Models\Invoice::count().' tickets='.\App\Models\Ticket::count().' coupons='.\App\Models\Coupon::count().PHP_EOL;
