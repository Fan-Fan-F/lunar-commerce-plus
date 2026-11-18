<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Base\DataTransferObjects\PaymentChecks;
use Lunar\Facades\Payments;
use Lunar\Models\Cart;
use Lunar\Models\CartAddress;
use Lunar\Models\Country;
use Lunar\Models\Currency;
use Lunar\Models\Order;
use Lunar\Models\Transaction;
use Lunar\Tests\Core\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

test('can authorize cross border payment with fx and compliance metadata', function () {
    $cart = Cart::factory()->create();

    Config::set('lunar.payments.types.cross_border', [
        'authorized' => 'cross-border-paid',
        'settlement_currency' => 'USD',
        'exchange_rate' => 1.25,
    ]);

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'billing',
        'country_id' => Country::factory(),
        'first_name' => 'John',
        'line_one' => '100 Cross Border Way',
        'city' => 'Singapore',
        'postcode' => 'SG1000',
    ]);

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'shipping',
        'country_id' => Country::factory(),
        'first_name' => 'John',
        'line_one' => '100 Cross Border Way',
        'city' => 'Singapore',
        'postcode' => 'SG1000',
    ]);

    $result = Payments::driver('cross_border')->cart($cart->refresh())->withData([
        'settlement_currency' => 'EUR',
        'exchange_rate' => 0.92,
        'fx_fee' => 350,
        'customs_reference' => 'CUST-2026-X889',
        'origin_country' => 'DE',
        'destination_country' => 'US',
        'payment_scheme' => 'alipay_cross_border',
    ])->authorize();

    expect($result)->toBeInstanceOf(PaymentAuthorize::class);
    expect($result->success)->toBeTrue();
    expect($result->paymentType)->toBe('cross_border');

    $order = $cart->refresh()->completedOrder;
    expect($order)->toBeInstanceOf(Order::class);
    expect($order->status)->toBe('cross-border-paid');

    $meta = $order->meta;
    expect($meta)->toHaveKey('cross_border');
    expect($meta['cross_border']['settlement_currency'])->toBe('EUR');
    expect($meta['cross_border']['exchange_rate'])->toBe(0.92);
    expect($meta['cross_border']['fx_fee'])->toBe(350);
    expect($meta['cross_border']['customs_reference'])->toBe('CUST-2026-X889');
    expect($meta['cross_border']['origin_country'])->toBe('DE');
    expect($meta['cross_border']['destination_country'])->toBe('US');
    expect($meta['cross_border']['payment_scheme'])->toBe('alipay_cross_border');
});

test('can perform cross border risk and compliance checks', function () {
    $cart = Cart::factory()->create();

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'billing',
        'country_id' => Country::factory(),
    ]);

    CartAddress::factory()->create([
        'cart_id' => $cart->id,
        'type' => 'shipping',
        'country_id' => Country::factory(),
    ]);

    $payment = Payments::driver('cross_border')->cart($cart->refresh())->withData([
        'settlement_currency' => 'USD',
        'exchange_rate' => 1.0,
        'customs_reference' => 'CUST-REF-99',
        'origin_country' => 'US',
        'destination_country' => 'GB',
    ]);

    $authResult = $payment->authorize();
    expect($authResult->success)->toBeTrue();

    $order = $cart->refresh()->completedOrder;
    $transaction = new Transaction([
        'order_id' => $order->id,
    ]);

    $checks = $payment->getPaymentChecks($transaction);
    expect($checks)->toBeInstanceOf(PaymentChecks::class);

    $checkList = $checks->getChecks();
    expect($checkList)->toHaveCount(3);
    expect($checkList[0]->label)->toBe('Cross-Border FX Alignment');
    expect($checkList[0]->successful)->toBeTrue();
    expect($checkList[1]->label)->toBe('Cross-Border Country Route');
    expect($checkList[1]->successful)->toBeTrue();
    expect($checkList[2]->label)->toBe('Customs Declaration Reference');
    expect($checkList[2]->successful)->toBeTrue();
});
