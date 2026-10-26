<script setup lang="ts">
import { onMounted, ref } from 'vue'
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btLocalPayment from 'braintree-web/local-payment'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// Renderer of braintree_local_payment

type AllowedMethod = { method: string; label: string }

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        merchantAccountId?: string
        allowedMethods?: AllowedMethod[]
        paymentIcons?: Record<string, string>
        fallbackUrl?: string
        fallbackButtonText?: string
    }
    placeOrder: (additionalData: Record<string, unknown>) => Promise<boolean>
    validate: () => boolean
}>()

defineExpose({ ownsAction: true })

const checkout = useCheckoutStore()
const messages = useMessagesStore()
const { draft } = checkout

const setupError = ref('')
const busy = ref(false)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let localPayment: any = null
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
    if (!props.config.allowedMethods?.length) {
        setupError.value = props.config.labels?.noneConfigured ?? ''
        return
    }

    try {
        const client = await btClient.create({ authorization: await fetchClientToken() })
        // The gateway's fraud signal (device fingerprint), collected the way Luma always does
        btDataCollector
            .create({ client })
            .then((collector: { deviceData: string }) => {
                deviceData = collector.deviceData
            })
            .catch(() => {})
        localPayment = await btLocalPayment.create({
            client,
            ...(props.config.merchantAccountId ? { merchantAccountId: props.config.merchantAccountId } : {}),
        })
    } catch {
        setupError.value = props.config.labels?.loadFailed ?? ''
    }
})

async function pay(method: string) {
    // The bank window only opens over a form the checkout would accept.
    if (!localPayment || busy.value || !props.validate()) return

    const shipping = draft.shippingAddr

    busy.value = true
    try {
        const payload = await localPayment.startPayment({
            amount: (checkout.grandTotal?.value ?? 0).toFixed(2),
            currencyCode: storeConfig<string>('currencyCode', ''),
            paymentType: method,
            ...(draft.email ? { email: draft.email } : {}),
            givenName: shipping.firstname,
            surname: shipping.lastname,
            phone: (shipping.telephone ?? '').replace(/\D/g, ''),
            shippingAddressRequired: !checkout.isVirtual,
            address: {
                streetAddress: shipping.street0,
                extendedAddress: shipping.street1,
                locality: shipping.city,
                postalCode: shipping.postcode,
                countryCode: shipping.country_id,
            },
            onPaymentStart: (_data: unknown, start: () => void) => start(),
            // Required by the SDK but never used in this flow; the configured values ride along
            fallback: {
                url: props.config.fallbackUrl || window.location.href,
                buttonText: props.config.fallbackButtonText || 'Complete Payment',
            },
        })

        await props.placeOrder({
            payment_method_nonce: payload.nonce,
            ...(deviceData ? { device_data: deviceData } : {}),
        })
    } catch (error) {
        // Closing the bank window is a choice, not a failure.
        const code = (error as { code?: string }).code ?? ''
        if (!code.includes('CLOSED')) {
            messages.add('error', props.config.labels?.paymentFailed ?? '')
        }
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div>
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>

        <div v-else class="grid gap-2 max-w-xs">
            <button
                v-for="m in config.allowedMethods"
                :key="m.method"
                type="button"
                :disabled="busy"
                class="l-btn l-btn-outline l-btn-secondary gap-2 h-11"
                @click="pay(m.method)"
            >
                <img
                    v-if="config.paymentIcons?.[m.method]"
                    :src="config.paymentIcons[m.method]"
                    :alt="m.label"
                    class="h-5 w-auto"
                />
                {{ (config.labels?.payWith ?? '').replace('%1', m.label) }}
            </button>
        </div>
    </div>
</template>
