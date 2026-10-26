<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btPayPalCheckout from 'braintree-web/paypal-checkout'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// Renderer of braintree_paypal

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        intent?: string
        displayName?: string
        buttonStyle?: { color?: string; shape?: string; label?: string }
    }
    placeOrder: (additionalData: Record<string, unknown>) => Promise<boolean>
    validate: () => boolean
}>()

const checkout = useCheckoutStore()
const messages = useMessagesStore()

const buttonEl = ref<HTMLElement | null>(null)
const setupError = ref('')
// False until PayPal's own JS has loaded and drawn the button
const ready = ref(false)

// Renderer contract: the button is the action, and `loaded` says when PayPal has drawn it.
defineExpose({ ownsAction: true, loaded: ready })

let buttons: { close?: () => void; render: (el: HTMLElement) => Promise<void> } | null = null
let deviceData = ''

/** The SDK only accepts its own vocabulary; a stray admin value falls to the default. */
const oneOf = (value: string | undefined, allowed: string[], fallback: string) =>
    value && allowed.includes(value) ? value : fallback

/**
 * Minted here rather than carried by the step config: generating a client token is a Braintree round trip
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
            throw new Error(props.config.labels?.noToken ?? '')
        }
        return data.createBraintreeClientToken
    })().catch(error => {
        // A failed mint must not poison every later attempt: drop it so the next mount tries again
        holder.__lynxBraintreeClientToken = undefined
        throw error
    })
    return holder.__lynxBraintreeClientToken
}

onMounted(async () => {
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
        const intent = props.config.intent === 'capture' ? 'capture' : 'authorize'

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
            fundingSource: paypal.FUNDING.PAYPAL,
            style: {
                color: oneOf(props.config.buttonStyle?.color, ['gold', 'blue', 'silver', 'white', 'black'], 'gold'),
                shape: oneOf(props.config.buttonStyle?.shape, ['pill', 'rect'], 'rect'),
                label: oneOf(props.config.buttonStyle?.label, ['paypal', 'checkout', 'buynow', 'pay'], 'paypal'),
            },
            // The popup only opens over a form the checkout would accept
            onClick: (_data: unknown, actions: { resolve: () => void; reject: () => void }) =>
                props.validate() ? actions.resolve() : actions.reject(),
            createOrder: () =>
                paypalCheckout.createPayment({
                    flow: 'checkout' as paypal.FlowType,
                    amount: (checkout.grandTotal?.value ?? 0).toFixed(2),
                    currency,
                    intent: intent as paypal.Intent,
                    // The quote's shipping address stands; the popup is only where the shopper approves the charge
                    enableShippingAddress: false,
                    ...(props.config.displayName ? { displayName: props.config.displayName } : {}),
                }),
            onApprove: async (data: unknown) => {
                const payload = await paypalCheckout.tokenizePayment(
                    data as btPayPalCheckout.PayPalCheckoutTokenizationOptions
                )
                await props.placeOrder({
                    payment_method_nonce: payload.nonce,
                    ...(deviceData ? { device_data: deviceData } : {}),
                })
            },
            onError: () => messages.add('error', props.config.labels?.paymentFailed ?? ''),
        })
        await buttons!.render(buttonEl.value!)
        ready.value = true
    } catch {
        setupError.value = props.config.labels?.loadFailed ?? ''
    }
})

onBeforeUnmount(() => buttons?.close?.())
</script>

<template>
    <div>
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>
        <template v-else>
            <!-- Skeleton the shape of the button until PayPal has drawn it. -->
            <div v-if="!ready" class="l-skeleton h-11 max-w-xs rounded-full" aria-hidden="true"></div>
            <!-- v-show, not v-if: the container must stay in the DOM for PayPal to render its button into it -->
            <div v-show="ready" ref="buttonEl" class="max-w-xs"></div>
        </template>
    </div>
</template>
