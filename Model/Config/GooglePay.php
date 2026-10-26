<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Model\Config;

use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use PayPal\Braintree\Model\GooglePay\Ui\ConfigProvider;

/**
 * Client config of the braintree_googlepay renderer (Components/GooglePay.vue)
 */
class GooglePay implements PaymentMethodRendererConfigInterface
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
            'environment' => (string) $this->configProvider->getEnvironment(),
            'merchantId'  => (string) $this->configProvider->getMerchantId(),
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
            'sdkFailed'          => (string) __('Something went wrong with your request. Please try again later.'),
            'loadFailed'         => (string) __('Something went wrong with your request. Please try again later.'),
            'paymentFailed'      => (string) __('Error processing payment. Please try again later.'),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'unavailable'        => (string) __("I'm sorry - but we were not able to process your payment. Please try another payment method or contact us so we can assist you."),
        ];
    }
}
