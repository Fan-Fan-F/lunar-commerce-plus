<?php

namespace Lunar\PaymentTypes;

use Lunar\Base\DataTransferObjects\PaymentAuthorize;
use Lunar\Base\DataTransferObjects\PaymentCapture;
use Lunar\Base\DataTransferObjects\PaymentCheck;
use Lunar\Base\DataTransferObjects\PaymentChecks;
use Lunar\Base\DataTransferObjects\PaymentRefund;
use Lunar\Events\PaymentAttemptEvent;
use Lunar\Models\Contracts\Transaction as TransactionContract;

class CrossBorderPayment extends AbstractPayment
{
    /**
     * {@inheritDoc}
     */
    public function authorize(): ?PaymentAuthorize
    {
        if (! $this->order) {
            if (! $this->order = $this->cart->draftOrder()->first()) {
                $this->order = $this->cart->createOrder();
            }
        }

        $crossBorderData = [
            'settlement_currency' => $this->data['settlement_currency'] ?? ($this->config['settlement_currency'] ?? $this->order->currency_code),
            'exchange_rate' => $this->data['exchange_rate'] ?? ($this->config['exchange_rate'] ?? $this->order->exchange_rate),
            'fx_fee' => (int) ($this->data['fx_fee'] ?? 0),
            'customs_reference' => $this->data['customs_reference'] ?? null,
            'origin_country' => $this->data['origin_country'] ?? null,
            'destination_country' => $this->data['destination_country'] ?? null,
            'payment_scheme' => $this->data['payment_scheme'] ?? ($this->config['default_scheme'] ?? 'cross_border_standard'),
            'cross_border_tx_id' => $this->data['cross_border_tx_id'] ?? null,
            'compliance_status' => $this->data['compliance_status'] ?? 'verified',
        ];

        $orderMeta = array_merge(
            (array) $this->order->meta,
            $this->data['meta'] ?? [],
            ['cross_border' => $crossBorderData]
        );

        $status = $this->data['authorized'] ?? ($this->config['authorized'] ?? ($this->order->status ?: config('lunar.orders.draft_status', 'payment-received')));

        $this->order->update([
            'status' => $status,
            'meta' => $orderMeta,
            'placed_at' => now(),
        ]);

        $response = new PaymentAuthorize(
            success: true,
            orderId: $this->order->id,
            paymentType: 'cross_border',
        );

        PaymentAttemptEvent::dispatch($response);

        return $response;
    }

    /**
     * {@inheritDoc}
     */
    public function refund(TransactionContract $transaction, int $amount = 0, $notes = null): PaymentRefund
    {
        return new PaymentRefund(
            success: true,
            message: $notes ?? 'Cross-border refund processed'
        );
    }

    /**
     * {@inheritDoc}
     */
    public function capture(TransactionContract $transaction, $amount = 0): PaymentCapture
    {
        return new PaymentCapture(
            success: true,
            message: 'Cross-border transaction captured successfully'
        );
    }

    /**
     * Perform cross-border compliance, country match, and FX checks.
     */
    public function getPaymentChecks(TransactionContract $transaction): PaymentChecks
    {
        $checks = new PaymentChecks;
        $meta = (array) ($this->order?->meta['cross_border'] ?? $this->data);

        // 1. Currency & FX Rate Check
        $settlementCurrency = $meta['settlement_currency'] ?? null;
        $orderCurrency = $this->order?->currency_code;
        $fxRate = $meta['exchange_rate'] ?? null;

        $hasValidFx = ($settlementCurrency === $orderCurrency) || (! empty($fxRate) && (float) $fxRate > 0);
        $checks->addCheck(new PaymentCheck(
            successful: $hasValidFx,
            label: 'Cross-Border FX Alignment',
            message: $hasValidFx
                ? 'Settlement currency and FX rate are properly aligned.'
                : 'Settlement currency differs from order currency without a valid exchange rate.'
        ));

        // 2. Cross-Border Country Risk Check
        $originCountry = $meta['origin_country'] ?? null;
        $destinationCountry = $meta['destination_country'] ?? null;
        $countryCheckPassed = ! empty($originCountry) && ! empty($destinationCountry);

        $checks->addCheck(new PaymentCheck(
            successful: $countryCheckPassed,
            label: 'Cross-Border Country Route',
            message: $countryCheckPassed
                ? "Payer origin ({$originCountry}) and destination ({$destinationCountry}) routes recorded."
                : 'Payer origin or destination country information is incomplete.'
        ));

        // 3. Customs Declaration Tracking Reference Check
        $customsRef = $meta['customs_reference'] ?? null;
        $checks->addCheck(new PaymentCheck(
            successful: ! empty($customsRef),
            label: 'Customs Declaration Reference',
            message: ! empty($customsRef)
                ? "Customs clearance reference ({$customsRef}) attached."
                : 'No customs declaration reference attached for this cross-border transaction.'
        ));

        return $checks;
    }
}
