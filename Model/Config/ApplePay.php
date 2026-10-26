<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Model\Config;

use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use PayPal\Braintree\Model\ApplePay\Ui\ConfigProvider;

/**
 * Client config of the braintree_applepay renderer (Components/ApplePay.vue)
 */
class ApplePay implements PaymentMethodRendererConfigInterface
{
    /**
     * Config provider
     *
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
    ) {
    }

    /**
     * Get Config
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'displayName' => $this->configProvider->getMerchantName(),
            // Every string the renderer shows, translated here
            'labels' => $this->labels(),
        ];
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
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'verifyFailed'       => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'unavailable'        => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
            'payWith'            => (string) __('Pay with Apple Pay'),
        ];
    }
}
