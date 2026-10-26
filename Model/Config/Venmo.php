<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Model\Config;

use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;

/**
 * Client config of the braintree_venmo renderer (Components/Venmo.vue)
 */
class Venmo implements PaymentMethodRendererConfigInterface
{
    /**
     * Get Config
     *
     * @return array
     */
    public function getConfig(): array
    {
        // Every string the renderer shows, translated here: the storefront's language is a server-side matter
        return ['labels' => $this->labels()];
    }

    /**
     * Labels
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'noToken'            => (string) __('Something went wrong with your request. Please try again later.'),
            'loadFailed'         => (string) __('Something went wrong with your request. Please try again later.'),
            'paymentFailed'      => (string) __('Error processing payment. Please try again later.'),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'unavailable'        => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
            'waiting'            => (string) __('Waiting for Venmo…'),
            'payWith'            => (string) __('Pay with Venmo'),
        ];
    }
}
