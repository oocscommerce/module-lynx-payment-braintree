<?php
/**
 * Copyright © OOCS Commerce (https://www.oocscommerce.com). All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace OOCS\LynxPaymentBraintree\Model\Config;

use OOCS\LynxCheckout\Api\PaymentMethodRendererConfigInterface;
use PayPal\Braintree\Model\Ach\Ui\ConfigProvider;

/**
 * Client config of the braintree_ach_direct_debit renderer (Components/Ach.vue)
 */
class Ach implements PaymentMethodRendererConfigInterface
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
            'storeName' => $this->configProvider->getStoreName(),
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
            'invalidRouting'     => (string) __('Enter the 9-digit routing number'),
            'invalidAccount'     => (string) __('Enter a valid account number'),
            'stillLoading'       => (string) __('The payment form is still loading. Please try again in a moment.'),
            'checkDetails'       => (string) __('Transaction has been declined. Please try again later.'),
            'holderName'         => (string) __('Account holder name'),
            'routingNumber'      => (string) __('Routing number'),
            'accountNumber'      => (string) __('Account Number'),
            'accountType'        => (string) __('Account type'),
            'checking'           => (string) __('Checking'),
            'savings'            => (string) __('Savings'),
            'ownership'          => (string) __('Account ownership'),
            'personal'           => (string) __('Personal'),
            'business'           => (string) __('Business'),
            // phpcs:ignore Generic.Files.LineLength -- core message kept verbatim for translation
            'mandate'            => (string) __('By clicking Place Order, I authorize Braintree, a service of PayPal, on behalf of %1 to verify my bank account information using bank information and consumer reports and I authorize %1 to initiate an ACH/electronic debit to my checking/savings account in the amount of %2 on %3. I agree the ACH transactions I authorize comply with all applicable laws.'),
        ];
    }
}
