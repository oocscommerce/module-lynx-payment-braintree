<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btPayPalCheckout from 'braintree-web/paypal-checkout'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useCustomerDataStore } from 'OOCS_LynxTheme/stores/global/customerDataStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'
import { dispatch } from 'OOCS_LynxTheme/utils/dispatch'

// The Braintree PayPal express buttons, the nodes an admin puts in the checkout's `express` slot above the steps

const props = withDefaults(
    defineProps<Lynx.BraintreeExpressPaypal | Lynx.BraintreeExpressPaylater | Lynx.BraintreeExpressCredit>(),
    {
        enabled: false,
        labels: () => ({}),
        intent: 'authorize',
        displayName: '',
        buttonStyle: () => ({}),
        funding: 'paypal',
    }
)

const checkout = useCheckoutStore()
const customerData = useCustomerDataStore()
const messages = useMessagesStore()

const buttonEl = ref<HTMLElement | null>(null)
const setupError = ref('')
// False until PayPal's own JS has loaded and drawn the button
const ready = ref(false)
// PayPal decides whether it may offer this funding source to this browser
const eligible = ref(true)

/** The method this button pays with, server-side: the pool entry of the step renderer. */
const METHOD = 'braintree_paypal'

const NO_PHONE = '00000000000'

/** The SDK only accepts its own vocabulary; a stray admin value falls to the default. */
const oneOf = (value: string | undefined, allowed: string[], fallback: string) =>
    value && allowed.includes(value) ? value : fallback

/** A rate as estimate-shipping-methods returns it. */
type Rate = {
    carrier_code: string
    method_code: string
    method_title: string
    price_excl_tax: number
}

/** What the quote answers a written address with; only the totals are read here. */
type Totals = { grand_total: number; shipping_amount: number }

// The rate the popup is showing as selected: onApprove writes it on the quote together with the full address
let chosenRate: Rate | null = null

let buttons: { close?: () => void; render: (el: HTMLElement) => Promise<void>; isEligible?: () => boolean } | null =
    null
let deviceData = ''

/**
 * Minted here rather than carried in the props: generating a client token is a Braintree round trip
 */
function fetchClientToken(): Promise<string> {
    const holder = window as unknown as { __lynxBraintreeClientToken?: Promise<string> }
    holder.__lynxBraintreeClientToken ??= (async () => {
        // The Store header is what scopes a GraphQL request to the current store view
        const storeCode = storeConfig<string>('storeCode', '')
        const currencyCode = storeConfig<string>('currencyCode', '')
        const response = await fetch('/graphql', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...(storeCode ? { Store: storeCode } : {}),
                ...(currencyCode ? { 'Content-Currency': currencyCode } : {}),
            },
            body: JSON.stringify({ query: 'mutation { createBraintreeClientToken }' }),
        })
        const { data } = (await response.json()) as { data?: { createBraintreeClientToken?: string } }
        if (!data?.createBraintreeClientToken) {
            throw new Error(props.labels.noToken ?? '')
        }
        return data.createBraintreeClientToken
    })().catch(error => {
        // A failed mint must not poison every later attempt: drop it so the next mount tries again
        holder.__lynxBraintreeClientToken = undefined
        throw error
    })
    return holder.__lynxBraintreeClientToken
}

/**
 * The region as the web API takes it
 */
function regionOf(countryId: string, state: string): Record<string, unknown> {
    const id = checkout.regionIdByCode(countryId, state)
    if (id) return { region_id: Number(id) }
    return state ? { region: state } : {}
}

/** Empty values are dropped rather than sent as "" — the web API rejects those. */
function compact(address: Record<string, unknown>): Record<string, unknown> {
    return Object.fromEntries(Object.entries(address).filter(([, value]) => value !== '' && value !== undefined))
}

/**
 * The shipping address as the popup returns it, in the shape the quote takes
 */
function shippingAddressOf(details: PayPalDetails): Record<string, unknown> {
    const address = details.shippingAddress
    const country = address.countryCode ?? ''
    const recipient = (address.recipientName ?? '').trim().split(' ').filter(Boolean)
    const [firstname, lastname] =
        recipient.length > 1
            ? [recipient[0], recipient.slice(1).join(' ')]
            : [details.firstName ?? '', details.lastName ?? '']

    return compact({
        email: details.email ?? '',
        // Magento wants a phone on an address; PayPal does not always return one.
        telephone: details.phone || NO_PHONE,
        firstname,
        lastname,
        street: [address.line1, address.line2].filter(Boolean),
        city: address.city ?? '',
        country_id: country,
        postcode: address.postalCode ?? '',
        save_in_address_book: 0,
        ...regionOf(country, address.state ?? ''),
    })
}

type PayPalAddress = {
    recipientName?: string
    line1?: string
    line2?: string
    city?: string
    state?: string
    countryCode?: string
    postalCode?: string
}

type PayPalDetails = {
    email?: string
    phone?: string
    firstName?: string
    lastName?: string
    shippingAddress: PayPalAddress
}

onMounted(async () => {
    if (!props.enabled) return

    try {
        const client = await btClient.create({ authorization: await fetchClientToken() })
        // The gateway's fraud signal (device fingerprint), collected the way Luma always does
        btDataCollector
            .create({ client })
            .then((collector: { deviceData: string }) => {
                deviceData = collector.deviceData
            })
            .catch(() => {})
        const paypalCheckout = await btPayPalCheckout.create({ client })

        const currency = storeConfig<string>('currencyCode', '')
        const intent = props.intent === 'capture' ? 'capture' : 'authorize'

        // Loaded once for the page, and the promise shared on window
        const sdk = window as unknown as { __lynxBraintreePayPalSdk?: Promise<unknown> }
        sdk.__lynxBraintreePayPalSdk ??= paypalCheckout
            // csp-nonce: the SDK injects inline scripts; on the CSP-enforced checkout they run only with the page's nonce
            .loadPayPalSDK({
                currency,
                intent,
                components: 'buttons',
                ...(checkout.cspNonce ? { dataAttributes: { 'csp-nonce': checkout.cspNonce } } : {}),
            })
            .catch((error: unknown) => {
                sdk.__lynxBraintreePayPalSdk = undefined
                throw error
            })
        await sdk.__lynxBraintreePayPalSdk

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const paypal = (window as any).paypal
        buttons = paypal.Buttons({
            fundingSource: oneOf(props.funding, ['paypal', 'paylater', 'credit'], 'paypal'),
            style: {
                // darkblue is Credit's alone; the provider only ever sends it for that button
                color: oneOf(props.buttonStyle.color, ['gold', 'blue', 'silver', 'white', 'black', 'darkblue'], 'gold'),
                shape: oneOf(props.buttonStyle.shape, ['pill', 'rect'], 'rect'),
                label: oneOf(props.buttonStyle.label, ['paypal', 'checkout', 'buynow', 'pay'], 'paypal'),
            },

            createOrder: () => {
                // The express card closes the buttons until the terms are accepted
                if (!checkout.termsAccepted) throw new Error(props.labels.acceptTerms ?? '')

                const amount = checkout.grandTotal?.value ?? 0
                // The totals are the summary's own read of the quote and arrive with the first step
                if (!amount) throw new Error(props.labels.loadFailed ?? '')

                return paypalCheckout.createPayment({
                    flow: 'checkout' as paypal.FlowType,
                    amount: amount.toFixed(2),
                    currency,
                    intent: intent as paypal.Intent,
                    // Unlike the payment step's renderer, where the quote already holds an address
                    enableShippingAddress: true,
                    shippingOptions: [],
                    ...(props.displayName ? { displayName: props.displayName } : {}),
                })
            },

            /**
             * The address or the picked rate changed
             */
            onShippingChange: async (
                data: {
                    paymentId: string
                    shipping_address: { city?: string; state?: string; country_code?: string; postal_code?: string }
                    selected_shipping_option?: { id?: string }
                },
                actions: { reject: () => void }
            ) => {
                // What PayPal shares before approval: enough for a rate, never the street.
                const country = data.shipping_address.country_code ?? ''
                const partial = compact({
                    city: data.shipping_address.city ?? '',
                    country_id: country,
                    postcode: data.shipping_address.postal_code ?? '',
                    save_in_address_book: 0,
                    ...regionOf(country, data.shipping_address.state ?? ''),
                })

                try {
                    const estimated = await checkout.rest<Rate[]>('estimate-shipping-methods', { address: partial })
                    const available = estimated.filter(rate => typeof rate.method_code === 'string')
                    if (!available.length) {
                        messages.add('error', props.labels.noRates ?? '')
                        actions.reject()
                        return
                    }

                    // The rate the shopper picked in the popup, or the first one on the first pass
                    const picked = data.selected_shipping_option?.id
                    const chosen = available.find(rate => rate.method_code === picked) ?? available[0]
                    chosenRate = chosen

                    const { totals } = await checkout.rest<{ totals: Totals }>('shipping-information', {
                        addressInformation: {
                            shipping_address: partial,
                            shipping_method_code: chosenRate.method_code,
                            shipping_carrier_code: chosenRate.carrier_code,
                        },
                    })

                    await paypalCheckout.updatePayment({
                        paymentId: data.paymentId,
                        amount: totals.grand_total.toFixed(2),
                        currency,
                        shippingOptions: available.map(rate => ({
                            id: rate.method_code,
                            type: 'SHIPPING' as paypal.ShippingOptionType,
                            label: rate.method_title,
                            selected: rate.method_code === chosen.method_code,
                            amount: {
                                // The chosen one at the price the quote actually collected; the rest at what the carrier estimated
                                value: (rate.method_code === chosen.method_code
                                    ? totals.shipping_amount
                                    : rate.price_excl_tax
                                ).toFixed(2),
                                currency,
                            },
                        })),
                    })
                } catch (error) {
                    messages.add('error', (error as Error).message)
                    actions.reject()
                }
            },

            onApprove: async (data: unknown) => {
                try {
                    const payload = (await paypalCheckout.tokenizePayment(
                        data as btPayPalCheckout.PayPalCheckoutTokenizationOptions
                    )) as unknown as { nonce: string; details: PayPalDetails }
                    const address = shippingAddressOf(payload.details)
                    const email = typeof address.email === 'string' ? address.email : ''

                    // The full address now that the shopper has approved
                    if (chosenRate) {
                        await checkout.rest('shipping-information', {
                            addressInformation: {
                                shipping_address: address,
                                shipping_method_code: chosenRate.method_code,
                                shipping_carrier_code: chosenRate.carrier_code,
                            },
                        })
                    }

                    // PayPal exports one address, so it stands as the billing address too
                    const payment: Record<string, unknown> = {
                        paymentMethod: {
                            method: METHOD,
                            additional_data: {
                                payment_method_nonce: payload.nonce,
                                ...(deviceData ? { device_data: deviceData } : {}),
                            },
                            // The terms the card had the shopper accept; core's plugin on payment-information rejects the order
                            ...(checkout.agreementIds.length
                                ? {
                                      extension_attributes: { agreement_ids: checkout.agreementIds },
                                  }
                                : {}),
                        },
                        billingAddress: address,
                    }
                    // The guest endpoint takes the email as a parameter of its own; a customer's is the account's
                    if (!customerData.isLoggedIn && email) payment.email = email

                    // Other modules may add to the payload (extension_attributes…), or refuse the order
                    await dispatch('checkout_submit_before', { method: METHOD, payload: payment })
                    const orderId = await checkout.rest<string>('payment-information', payment)

                    await checkout.orderPlaced()
                    // The order exists: an observer failing here must not hold the shopper on the checkout
                    await dispatch('checkout_submit_all_after', { order_id: String(orderId) }).catch(() => undefined)
                    window.location.assign(checkout.successUrl)
                } catch (error) {
                    messages.add('error', (error as Error).message || (props.labels.paymentFailed ?? ''))
                }
            },

            onError: () => messages.add('error', props.labels.paymentFailed ?? ''),
        })

        if (buttons?.isEligible && !buttons.isEligible()) {
            eligible.value = false
            return
        }

        await buttons!.render(buttonEl.value!)
        ready.value = true
    } catch {
        setupError.value = props.labels.loadFailed ?? ''
    }
})

onBeforeUnmount(() => buttons?.close?.())
</script>

<template>
    <div v-if="enabled && eligible">
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>
        <template v-else>
            <!-- Skeleton the shape of the button until PayPal has drawn it. -->
            <div v-if="!ready" class="l-skeleton h-11 rounded-full" aria-hidden="true"></div>
            <!-- v-show, not v-if: the container must stay in the DOM for PayPal to render its button into it -->
            <div v-show="ready" ref="buttonEl"></div>
        </template>
    </div>
</template>
