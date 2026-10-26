<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Provider\Express;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use PayPal\Braintree\Gateway\Config\PayPal\Config as PayPalGatewayConfig;
use PayPal\Braintree\Gateway\Config\PayPalCredit\Config as CreditConfig;

/**
 * The PayPal Credit button, the third of PayPal's three funding sources on the checkout
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class PayPalCredit extends PayPal
{
    /**
     * Config, scope config, checkout session, payment helper, credit config
     *
     * @param PayPalGatewayConfig $config
     * @param ScopeConfigInterface $scopeConfig
     * @param CheckoutSession $checkoutSession
     * @param PaymentHelper $paymentHelper
     * @param CreditConfig $creditConfig
     */
    public function __construct(
        PayPalGatewayConfig $config,
        ScopeConfigInterface $scopeConfig,
        CheckoutSession $checkoutSession,
        PaymentHelper $paymentHelper,
        private readonly CreditConfig $creditConfig,
    ) {
        parent::__construct($config, $scopeConfig, $checkoutSession, $paymentHelper);
    }

    /**
     * Button Type
     *
     * @return string
     */
    protected function buttonType(): string
    {
        return 'credit';
    }

    /**
     * Is Funding Active
     *
     * @return bool
     */
    protected function isFundingActive(): bool
    {
        return $this->creditConfig->isActive();
    }

    /**
     * Button Color
     *
     * @return string
     */
    protected function buttonColor(): string
    {
        return (string) ($this->config->getCreditButtonColor(
            PayPalGatewayConfig::BUTTON_AREA_CHECKOUT,
            $this->buttonType()
        ) ?? '');
    }
}
