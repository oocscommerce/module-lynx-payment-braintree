<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Model\Config;

use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use PayPal\Braintree\Gateway\Config\Config as BraintreeConfig;

/**
 * Client config of the braintree card renderer (Components/Cc.vue)
 */
class Cc implements PaymentMethodRendererConfigInterface
{
    /**
     * Config
     *
     * @param BraintreeConfig $config
     */
    public function __construct(
        private readonly BraintreeConfig $config,
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
            'useCvv' => $this->config->isCvvEnabled(),
            'threeDSecure' => [
                'enabled'            => $this->config->isVerify3DSecure(),
                'challengeRequested' => $this->config->is3DSAlwaysRequested(),
                'thresholdAmount'    => $this->config->getThresholdAmount(),
                'specificCountries'  => $this->config->get3DSecureSpecificCountries(),
            ],
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
            'cardNumber'         => (string) __('Card Number'),
            'expiration'         => (string) __('Expiration Date'),
            'cvv'                => (string) __('Card Verification Number'),
            'loadFailed'         => (string) __('Something went wrong with your request. Please try again later.'),
            'checkDetails'       => (string) __('Transaction has been declined. Please try again later.'),
            'tryAnother'         => (string) __('Please try again with another form of payment.'),
            'stillLoading'       => (string) __('The payment form is still loading. Please try again in a moment.'),
        ];
    }
}
