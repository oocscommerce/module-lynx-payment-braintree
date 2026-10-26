<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Model\Config;

use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use PayPal\Braintree\Model\Lpm\Config as LpmConfig;

/**
 * Client config of the braintree_local_payment renderer (Components/Lpm.vue)
 */
class Lpm implements PaymentMethodRendererConfigInterface
{
    /**
     * Config
     *
     * @param LpmConfig $config
     */
    public function __construct(
        private readonly LpmConfig $config,
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
            'merchantAccountId'  => (string) ($this->config->getMerchantAccountId() ?? ''),
            'allowedMethods'     => $this->config->getAllowedMethods(),
            'paymentIcons'       => $this->config->getPaymentIcons(),
            'fallbackUrl'        => $this->config->getFallbackUrl(),
            'fallbackButtonText' => $this->config->getFallbackButtonText(),
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
            'noneConfigured'     => (string) __('No local payment methods are configured.'),
            'loadFailed'         => (string) __('Something went wrong with your request. Please try again later.'),
            'paymentFailed'      => (string) __('Error processing payment. Please try again later.'),
            'payWith'            => (string) __('Pay with %1'),
        ];
    }
}
