<?php

namespace Dashed\DashedEcommerceMultiSafePay\Classes;

use Exception;
use Dashed\DashedCore\Classes\Sites;
use Illuminate\Support\Facades\Http;
use Dashed\DashedCore\Classes\Locales;
use Illuminate\Support\Facades\Storage;
use Dashed\DashedCore\Models\Customsetting;
use Dashed\DashedTranslations\Models\Translation;
use Dashed\DashedEcommerceCore\Models\OrderPayment;
use Dashed\DashedEcommerceCore\Classes\ShoppingCart;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;
use Dashed\DashedEcommerceCore\Classes\PaymentMethods;

class MultiSafePay
{
    /**
     * Lightweight health check for the IntegrationsDashboard. Verifies the
     * Multisafepay API key is set — does NOT call the Multisafepay API on
     * every dashboard render.
     */
    public static function healthCheck(?string $siteId = null): \Dashed\DashedCore\Integrations\IntegrationHealth
    {
        $apiKey = \Dashed\DashedCore\Models\Customsetting::get('multisafepay_api_key', $siteId);

        if (empty($apiKey)) {
            return \Dashed\DashedCore\Integrations\IntegrationHealth::misconfigured('API key ontbreekt');
        }

        return \Dashed\DashedCore\Integrations\IntegrationHealth::ok();
    }

    public $manager;

    //    public static function initialize($siteId = null)
    //    {
    //        if (! $siteId) {
    //            $siteId = Sites::getActive();
    //        }
    //
    //        \Paynl\Config::setApiToken(Customsetting::get('paynl_at_hash', $siteId));
    //        \Paynl\Config::setServiceId(Customsetting::get('paynl_sl_code', $siteId));
    //    }

    public static function isConnected($siteId = null)
    {
        if (! $siteId) {
            $siteId = Sites::getActive();
        }

        try {
            return Http::get('https://api.multisafepay.com/v1/json/payment-methods', [
                'api_key' => Customsetting::get('multisafepay_api_key', $siteId),
            ])
                ->json()['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function syncPaymentMethods($siteId = null)
    {
        $site = Sites::get($siteId);

        if (! Customsetting::get('multisafepay_connected', $site['id'])) {
            return;
        }

        try {
            $allPaymentMethods = Http::get('https://api.multisafepay.com/v1/json/payment-methods', [
                'api_key' => Customsetting::get('multisafepay_api_key', $site['id']),
            ])
                ->json()['data'] ?? [];
        } catch (Exception $exception) {
            $allPaymentMethods = [];
        }

        foreach ($allPaymentMethods as $allPaymentMethod) {
            if (! PaymentMethod::where('psp', 'multisafepay')->where('psp_id', $allPaymentMethod['id'])->count()) {
                $image = file_get_contents($allPaymentMethod['icon_urls']['large']);
                $imagePath = '/dashed/payment-methods/multisafepay/' . $allPaymentMethod['id'] . '.png';
                Storage::put($imagePath, $image);

                $paymentMethod = new PaymentMethod();
                $paymentMethod->site_id = $site['id'];
                $paymentMethod->available_from_amount = $allPaymentMethod['allowed_amount']['min'] ?? 0;
                $paymentMethod->psp = 'multisafepay';
                $paymentMethod->psp_id = $allPaymentMethod['id'];
                $paymentMethod->image = $imagePath;
                $paymentMethod->active = false;
                foreach (Locales::getLocales() as $locale) {
                    $paymentMethod->setTranslation('name', $locale['id'], $allPaymentMethod['name']);
                }
                $paymentMethod->save();

                PaymentMethods::notifyAdminsOfNewPaymentMethod($paymentMethod);
            }
        }
    }

    public static function startTransaction(OrderPayment $orderPayment)
    {
        $orderPayment->psp = 'multisafepay';
        $orderPayment->save();

        $siteId = Sites::getActive();

        $payload = [
            'type' => 'redirect',
            'gateway' => $orderPayment->paymentMethod->psp_id,
            'order_id' => $orderPayment->order->hash,
            'currency' => 'EUR',
            'amount' => $orderPayment->amount * 100,
            'description' => Translation::get('order-by-store', 'orders', 'Order by :storeName:', 'text', [
                'storeName' => Customsetting::get('site_name'),
            ]),
            'payment_options' => [
                'notification_method' => 'POST',
                'notification_url' => route('dashed.frontend.checkout.exchange'),
                'redirect_url' => url(ShoppingCart::getCompleteUrl()) . '?orderId=' . $orderPayment->order->hash . '&paymentId=' . $orderPayment->hash,
                'cancel_url' => url('/'),
            ],
            'customer' => [
                'ip_address' => request()->ip(),
                'email' => $orderPayment->order->user->email ?? $orderPayment->order->email,
                'first_name' => $orderPayment->order->first_name,
                'last_name' => $orderPayment->order->last_name,
                'address1' => $orderPayment->order->street . ' ' . $orderPayment->order->house_number,
                'zip_code' => $orderPayment->order->zip_code,
                'city' => $orderPayment->order->city,
                'country' => $orderPayment->order->country,
                'phone' => $orderPayment->order->phone_number,
                'user_agent' => request()->userAgent(),
                'company_name' => $orderPayment->order->company_name,
            ],
        ];

        // Bestaat pas vanaf ec-core v4.134.0; dit pakket vereist ec-core niet.
        if (method_exists($orderPayment, 'recordPspRequest')) {
            $orderPayment->recordPspRequest($payload);
        }

        $transaction = Http::withHeaders([
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])
            ->post('https://api.multisafepay.com/v1/json/orders?api_key=' . Customsetting::get('multisafepay_api_key', $siteId), $payload)
            ->json();

        if (! isset($transaction['data']['order_id'])) {
            throw new \RuntimeException('MultiSafePay: ' . ($transaction['error_info'] ?? 'geen order_id in het antwoord') . ' (code ' . ($transaction['error_code'] ?? '-') . ')');
        }

        $orderPayment->psp_id = $transaction['data']['order_id'];
        $orderPayment->save();

        return [
            'transaction' => $transaction,
            'redirectUrl' => $transaction['data']['payment_url'],
        ];
    }

    public static function getOrderStatus(OrderPayment $orderPayment)
    {
        $payment = Http::get('https://api.multisafepay.com/v1/json/orders/' . $orderPayment->psp_id, [
            'api_key' => Customsetting::get('multisafepay_api_key', $orderPayment->order->site_id),
        ])
            ->json();

        $paymentStatus = $payment['data']['status'] ?? 'pending';

        if ($paymentStatus == 'completed') {
            return 'paid';
        } elseif ($paymentStatus == 'refunded') {
            return 'refunded';
        } elseif ($paymentStatus == 'cancelled') {
            return 'cancelled';
        } else {
            return 'pending';
        }
    }
}
