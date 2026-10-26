<script setup lang="ts">
import { onMounted, ref } from 'vue'
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btApplePay from 'braintree-web/apple-pay'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// Renderer of braintree_applepay

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        displayName?: string
    }
    placeOrder: (additionalData: Record<string, unknown>) => Promise<boolean>
    validate: () => boolean
}>()

defineExpose({ ownsAction: true })

const checkout = useCheckoutStore()
const messages = useMessagesStore()

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const applePaySession = () => (window as any).ApplePaySession
const supported = ref(false)

const setupError = ref('')
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let applePay: any = null
let deviceData = ''

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
    supported.value = !!applePaySession()?.canMakePayments()
    if (!supported.value) return

    try {
        const client = await btClient.create({ authorization: await fetchClientToken() })
        // The gateway's fraud signal (device fingerprint), collected the way Luma always does
        btDataCollector
            .create({ client })
            .then((collector: { deviceData: string }) => {
                deviceData = collector.deviceData
            })
            .catch(() => {})
        applePay = await btApplePay.create({ client })
    } catch {
        setupError.value = props.config.labels?.loadFailed ?? ''
    }
})

function pay() {
    // The sheet only opens over a form the checkout would accept.
    if (!applePay || !props.validate()) return

    const request = applePay.createPaymentRequest({
        total: {
            label: props.config.displayName || 'Order',
            amount: (checkout.grandTotal?.value ?? 0).toFixed(2),
        },
    })
    const ApplePaySession = applePaySession()
    const session = new ApplePaySession(3, request)

    session.onvalidatemerchant = async (event: { validationURL: string }) => {
        try {
            session.completeMerchantValidation(
                await applePay.performValidation({
                    validationURL: event.validationURL,
                    displayName: props.config.displayName || '',
                })
            )
        } catch {
            messages.add('error', props.config.labels?.verifyFailed ?? '')
            session.abort()
        }
    }

    session.onpaymentauthorized = async (event: { payment: { token: unknown } }) => {
        try {
            const { nonce } = await applePay.tokenize({ token: event.payment.token })
            const placed = await props.placeOrder({
                payment_method_nonce: nonce,
                ...(deviceData ? { device_data: deviceData } : {}),
            })
            session.completePayment(placed ? ApplePaySession.STATUS_SUCCESS : ApplePaySession.STATUS_FAILURE)
        } catch {
            session.completePayment(ApplePaySession.STATUS_FAILURE)
        }
    }

    session.begin()
}
</script>

<template>
    <div>
        <p v-if="!supported" class="text-sm text-base-content/70">
            {{ config.labels?.unavailable }}
        </p>
        <p v-else-if="setupError" class="text-sm text-error">{{ setupError }}</p>
        <!-- The system button, as Apple's guidelines require — WebKit draws it from the appearance alone. -->
        <button
            v-else
            type="button"
            class="[-webkit-appearance:-apple-pay-button] [-apple-pay-button-type:buy] [-apple-pay-button-style:black] w-full max-w-[20rem] h-11 border-0 rounded-button cursor-pointer"
            :aria-label="config.labels?.payWith"
            @click="pay"
        ></button>
    </div>
</template>
