<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use PayPal\Braintree\Gateway\Config\PayPal\Config as PayPalGatewayConfig;

/**
 * Client config of the braintree_paypal renderer (Components/PayPal.vue)
 */
class PayPal implements PaymentMethodRendererConfigInterface
{
    /**
     * Config, scope config
     *
     * @param PayPalGatewayConfig $config
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly PayPalGatewayConfig $config,
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Get Config
     *
     * @return array
     */
    public function getConfig(): array
    {
        $paymentAction = (string) $this->scopeConfig->getValue(
            'payment/braintree_paypal/payment_action',
            ScopeInterface::SCOPE_STORE
        );

        return [
            'intent'      => $paymentAction === 'authorize_capture' ? 'capture' : 'authorize',
            'displayName' => (string) ($this->config->getMerchantName() ?? ''),
            'buttonStyle' => [
                'color' => (string) ($this->config->getButtonColor(PayPalGatewayConfig::BUTTON_AREA_CHECKOUT) ?? ''),
                'shape' => (string) $this->config->getButtonShape(PayPalGatewayConfig::BUTTON_AREA_CHECKOUT),
                'label' => (string) ($this->config->getButtonLabel(PayPalGatewayConfig::BUTTON_AREA_CHECKOUT) ?? ''),
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
            'paymentFailed'      => (string) __('Error processing payment. Please try again later.'),
            'loadFailed'         => (string) __('Something went wrong with your request. Please try again later.'),
        ];
    }
}
