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
use Magento\Store\Model\ScopeInterface;
use OOCS\LynxComponent\Model\AbstractComponentProvider;
use PayPal\Braintree\Gateway\Config\PayPal\Config as PayPalGatewayConfig;
use PayPal\Braintree\Model\Ui\PayPal\ConfigProvider as PayPalUi;

/**
 * Feeds the Braintree PayPal express button (Components/Express/PayPal.vue)
 *
 * @SuppressWarnings(PHPMD.CookieAndSessionMisuse)
 */
class PayPal extends AbstractComponentProvider
{
    /**
     * Which of PayPal's funding sources this node draws, and the name the admin styles it under per location
     *
     * @return string
     */
    protected function buttonType(): string
    {
        return 'paypal';
    }

    /**
     * Whether this funding source is switched on in its own right
     *
     * @return bool
     */
    protected function isFundingActive(): bool
    {
        return true;
    }

    /**
     * The admin's colour for this button
     *
     * @return string
     */
    protected function buttonColor(): string
    {
        $color = $this->config->getButtonColor(PayPalGatewayConfig::BUTTON_AREA_CHECKOUT, $this->buttonType());

        return (string) ($color ?? '');
    }

    /**
     * Config, scope config, checkout session, payment helper
     *
     * @param PayPalGatewayConfig $config
     * @param ScopeConfigInterface $scopeConfig
     * @param CheckoutSession $checkoutSession
     * @param PaymentHelper $paymentHelper
     */
    public function __construct(
        // Protected: PayPalCredit reads its own palette through it.
        protected readonly PayPalGatewayConfig $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession,
        private readonly PaymentHelper $paymentHelper,
    ) {
    }

    /**
     * Resolve
     *
     * @param array $props
     * @return array
     */
    public function resolve(array $props): array
    {
        $area = PayPalGatewayConfig::BUTTON_AREA_CHECKOUT;
        $type = $this->buttonType();
        $props['enabled'] = $this->isEnabled();

        if (!$props['enabled']) {
            return $props;
        }

        $paymentAction = (string) $this->scopeConfig->getValue(
            'payment/braintree_paypal/payment_action',
            ScopeInterface::SCOPE_STORE
        );

        return $props + [
            'intent'      => $paymentAction === 'authorize_capture' ? 'capture' : 'authorize',
            'displayName' => (string) ($this->config->getMerchantName() ?? ''),
            // The admin's styling for the checkout-page button
            'buttonStyle' => [
                'color' => $this->buttonColor(),
                'shape' => (string) $this->config->getButtonShape($area, $type),
                'label' => (string) ($this->config->getButtonLabel($area, $type) ?? ''),
            ],
            // Which funding source the button opens; the component hands it to the SDK.
            'funding' => $this->buttonType(),
            // Every string the button shows, translated here: the storefront's language is a server-side matter
            'labels' => $this->labels(),
        ];
    }

    /**
     * Whether this page gets a button at all
     *
     * @return bool
     */
    private function isEnabled(): bool
    {
        $quote = $this->checkoutSession->getQuote();
        $hasQuote = (bool) $quote->getId();

        try {
            $method = $this->paymentHelper->getMethodInstance(PayPalUi::PAYPAL_CODE);
        } catch (\Exception $e) {
            return false;
        }

        if (!$method->isAvailable($hasQuote ? $quote : null)) {
            return false;
        }

        if (!$this->config->skipOrderReviewStep() || !$this->isFundingActive()) {
            return false;
        }

        if (!$this->config->showPayPalButton($this->buttonType(), PayPalGatewayConfig::BUTTON_AREA_CHECKOUT)) {
            return false;
        }

        return !$hasQuote || !$quote->isVirtual();
    }

    /**
     * Labels
     *
     * @return array<string, string>
     */
    private function labels(): array
    {
        return [
            'noToken'       => (string) __('Something went wrong with your request. Please try again later.'),
            'loadFailed'    => (string) __('Something went wrong with your request. Please try again later.'),
            'paymentFailed' => (string) __('Error processing payment. Please try again later.'),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'noRates'       => (string) __('There are no shipping methods available for you right now. Please try again or use an alternative payment method.'),
            'acceptTerms'   => (string) __('Please agree to all Terms and Conditions before placing the order.'),
        ];
    }
}
