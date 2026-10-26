<script setup lang="ts">
import { onMounted, ref } from 'vue'
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btGooglePayment from 'braintree-web/google-payment'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// Renderer of braintree_googlepay

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        environment?: string
        merchantId?: string
    }
    placeOrder: (additionalData: Record<string, unknown>) => Promise<boolean>
    validate: () => boolean
}>()

const checkout = useCheckoutStore()
const messages = useMessagesStore()

const buttonEl = ref<HTMLElement | null>(null)
const setupError = ref('')
/** The device/browser has no way to pay: the sheet would be empty, so say so instead. */
const unavailable = ref(false)
// False until Google has drawn its button; a skeleton stands in meanwhile.
const buttonReady = ref(false)

// Renderer contract: the button is the action, and `loaded` says when Google has drawn it.
defineExpose({ ownsAction: true, loaded: buttonReady })

// eslint-disable-next-line @typescript-eslint/no-explicit-any
let googlePayment: any = null
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let paymentsClient: any = null
let deviceData = ''

/** Google's pay.js, fetched once; later mounts find it already on window. */
function loadGooglePayJs(): Promise<void> {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    if ((window as any).google?.payments?.api) return Promise.resolve()

    return new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src = 'https://pay.google.com/gp/p/js/pay.js'
        script.onload = () => resolve()
        script.onerror = () => reject(new Error(props.config.labels?.sdkFailed ?? ''))
        document.head.appendChild(script)
    })
}

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
        await loadGooglePayJs()

        const client = await btClient.create({ authorization: await fetchClientToken() })
        // The gateway's fraud signal (device fingerprint), collected the way Luma always does
        btDataCollector
            .create({ client })
            .then((collector: { deviceData: string }) => {
                deviceData = collector.deviceData
            })
            .catch(() => {})
        googlePayment = await btGooglePayment.create({
            client,
            googlePayVersion: 2,
            ...(props.config.merchantId ? { googleMerchantId: props.config.merchantId } : {}),
        })

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        paymentsClient = new (window as any).google.payments.api.PaymentsClient({
            environment: props.config.environment === 'production' ? 'PRODUCTION' : 'TEST',
        })

        const request = googlePayment.createPaymentDataRequest()
        const ready = await paymentsClient.isReadyToPay({
            apiVersion: 2,
            apiVersionMinor: 0,
            allowedPaymentMethods: request.allowedPaymentMethods,
            existingPaymentMethodRequired: false,
        })
        if (!ready.result) {
            unavailable.value = true
            return
        }

        buttonEl.value!.appendChild(
            paymentsClient.createButton({ buttonColor: 'default', buttonType: 'buy', onClick: pay })
        )
        buttonReady.value = true
    } catch {
        setupError.value = props.config.labels?.loadFailed ?? ''
    }
})

async function pay() {
    // The sheet only opens over a form the checkout would accept.
    if (!props.validate()) return

    try {
        // A fresh request per click: the total may have changed since the mount.
        const request = googlePayment.createPaymentDataRequest({
            transactionInfo: {
                currencyCode: storeConfig<string>('currencyCode', ''),
                totalPriceStatus: 'FINAL',
                totalPrice: (checkout.grandTotal?.value ?? 0).toFixed(2),
            },
        })

        const paymentData = await paymentsClient.loadPaymentData(request)
        const { nonce } = await googlePayment.parseResponse(paymentData)
        await props.placeOrder({
            payment_method_nonce: nonce,
            ...(deviceData ? { device_data: deviceData } : {}),
        })
    } catch (error) {
        // Closing the sheet is a choice, not a failure.
        if ((error as { statusCode?: string }).statusCode !== 'CANCELED') {
            messages.add('error', props.config.labels?.paymentFailed ?? '')
        }
    }
}
</script>

<template>
    <div>
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>
        <p v-else-if="unavailable" class="text-sm text-base-content/70">
            {{ config.labels?.unavailable }}
        </p>
        <template v-else>
            <!-- Skeleton the shape of the button until Google draws it -->
            <div v-if="!buttonReady" class="l-skeleton h-11 max-w-xs" aria-hidden="true"></div>
            <div v-show="buttonReady" ref="buttonEl"></div>
        </template>
    </div>
</template>
