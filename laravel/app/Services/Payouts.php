<?php

namespace App\Services;

use App\Models\Payout;
use App\Models\Sale;
use App\Models\Store;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\Gateways;
use App\Services\Payments\PaystackException;
use App\Services\Payments\TransferRequest;
use App\Support\Phone;
use App\Support\Text;

/**
 * Sending a shop its money after a paid online order. Customers pay Topline's Paystack account
 * (one M-PESA prompt); Topline keeps PLATFORM_COMMISSION_PERCENT and transfers the rest to the
 * shop's till, paybill (+ account), Pochi la Biashara or phone.
 */
class Payouts
{
    private const LABEL = ['till' => 'Till', 'paybill' => 'Paybill', 'pochi' => 'Pochi la Biashara', 'send_money' => 'M-PESA'];

    public static function destination(Store $store): string
    {
        if ($store->payout_method === Store::PAYBILL) {
            return "Paybill {$store->payout_number}, account {$store->payout_account}";
        }
        if (in_array($store->payout_method, [Store::POCHI, Store::SEND_MONEY], true)) {
            return self::LABEL[$store->payout_method].' '.Phone::display($store->payout_number);
        }

        return "Till {$store->payout_number}";
    }

    /** Create and send the payout for one paid order. Never throws: failures are recorded for admins. */
    public static function payShopForOrder(Store $store, Sale $sale): Payout
    {
        $commission = (int) floor($sale->total * (float) config('topline.platform_commission_percent') / 100);
        $payout = Payout::create(['reference' => Text::reference('PO'), 'store_id' => $store->id, 'sale_number' => $sale->number,
            'gross' => $sale->total, 'commission' => $commission, 'amount' => $sale->total - $commission,
            'method' => $store->payout_method, 'destination' => self::destination($store), 'status' => 'pending']);
        if (! $store->canReceivePayouts()) {
            $payout->update(['status' => 'failed', 'failure_reason' => "The shop hasn't set where to receive money (Store settings > Getting paid)."]);
            self::log($store, $sale, $payout);

            return $payout;
        }
        try {
            $result = Gateways::get()->transfer(new TransferRequest($payout->reference, $payout->amount, $store->payout_method,
                $store->payout_number, (string) $store->payout_account, $store->payout_name ?: $store->name, "{$store->name} order {$sale->code}"));
        } catch (PaystackException $e) {
            $payout->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 200)]);
            Monitoring::recordIncident('payout', "Payout to {$store->name} failed", $e->getMessage());
            self::log($store, $sale, $payout);

            return $payout;
        }
        self::applyResult($payout, $result);
        self::log($store, $sale, $payout);

        return $payout;
    }

    public static function applyResult(Payout $payout, ChargeResult $result): void
    {
        $payout->provider_ref = $result->providerRef ?: $payout->provider_ref;
        if ($result->status === ChargeResult::PAID) {
            $payout->status = 'paid';
            $payout->paid_at = now();
        } elseif ($result->status === ChargeResult::FAILED) {
            $payout->status = 'failed';
            $payout->failure_reason = $result->message;
            Monitoring::recordIncident('payout', "Payout {$payout->reference} failed", (string) $result->message);
        } else {
            $payout->status = 'pending';
        }
        $payout->save();
    }

    /** Admin "send again" for a failed payout, e.g. after the shop fixes its till number. */
    public static function retry(Payout $payout, Store $store): Payout
    {
        $payout->method = $store->payout_method;
        $payout->destination = self::destination($store);
        try {
            $result = Gateways::get()->transfer(new TransferRequest(Text::reference('PO'), $payout->amount, $store->payout_method,
                $store->payout_number, (string) $store->payout_account, $store->payout_name ?: $store->name, "{$store->name} payout retry"));
        } catch (PaystackException $e) {
            $payout->failure_reason = mb_substr($e->getMessage(), 0, 200);
            $payout->save();

            return $payout;
        }
        self::applyResult($payout, $result);

        return $payout;
    }

    private static function log(Store $store, Sale $sale, Payout $payout): void
    {
        if ($payout->status === 'failed') {
            Activity::record('payout_failed', "Couldn't send ".Text::kes($payout->amount)." for {$sale->code} to {$payout->destination}: "
                .($payout->failure_reason ?: 'unknown reason'), $store->id, 'System', ['order' => $sale->code]);
        } else {
            Activity::record('payout_sent', 'Sent '.Text::kes($payout->amount)." for {$sale->code} to {$payout->destination}", $store->id,
                'System', ['order' => $sale->code, 'amount' => $payout->amount]);
        }
    }
}
