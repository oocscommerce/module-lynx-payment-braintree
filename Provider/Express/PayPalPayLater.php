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
use PayPal\Braintree\Gateway\Config\PayPalPayLater\Config as PayLaterConfig;

/**
 * The Pay Later button, the second of PayPal's three funding sources on the checkout
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class PayPalPayLater extends PayPal
{
    /**
     * Config, scope config, checkout session, payment helper, pay later config
     *
     * @param PayPalGatewayConfig $config
     * @param ScopeConfigInterface $scopeConfig
     * @param CheckoutSession $checkoutSession
     * @param PaymentHelper $paymentHelper
     * @param PayLaterConfig $payLaterConfig
     */
    public function __construct(
        PayPalGatewayConfig $config,
        ScopeConfigInterface $scopeConfig,
        CheckoutSession $checkoutSession,
        PaymentHelper $paymentHelper,
        private readonly PayLaterConfig $payLaterConfig,
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
        return 'paylater';
    }

    /**
     * Is Funding Active
     *
     * @return bool
     */
    protected function isFundingActive(): bool
    {
        return $this->payLaterConfig->isButtonActive(PayPalGatewayConfig::BUTTON_AREA_CHECKOUT);
    }
}
