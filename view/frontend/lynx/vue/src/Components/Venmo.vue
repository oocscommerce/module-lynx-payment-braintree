<script setup lang="ts">
import { onMounted, ref } from 'vue'
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btVenmo from 'braintree-web/venmo'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useMessagesStore } from 'OOCS_LynxTheme/stores/global/messagesStore'

// Renderer of braintree_venmo

const props = defineProps<{
    // The client token is minted at mount below; the config carries the strings.
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
    }
    placeOrder: (additionalData: Record<string, unknown>) => Promise<boolean>
    validate: () => boolean
}>()

defineExpose({ ownsAction: true })

const messages = useMessagesStore()

const setupError = ref('')
const unavailable = ref(false)
const busy = ref(false)
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let venmo: any = null
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
    try {
        const client = await btClient.create({ authorization: await fetchClientToken() })
        // The gateway's fraud signal (device fingerprint), collected the way Luma always does
        btDataCollector
            .create({ client })
            .then((collector: { deviceData: string }) => {
                deviceData = collector.deviceData
            })
            .catch(() => {})
        // The same posture as the native checkout: desktop allowed (QR / web login)
        venmo = await btVenmo.create({
            client,
            allowDesktop: true,
            allowDesktopWebLogin: true,
            mobileWebFallBack: true,
            allowNewBrowserTab: false,
            paymentMethodUsage: 'single_use',
        })

        if (!venmo.isBrowserSupported()) {
            unavailable.value = true
        }
    } catch {
        setupError.value = props.config.labels?.loadFailed ?? ''
    }
})

async function pay() {
    // The hand-off only starts over a form the checkout would accept.
    if (!venmo || busy.value || !props.validate()) return

    busy.value = true
    try {
        const { nonce } = await venmo.tokenize()
        await props.placeOrder({
            payment_method_nonce: nonce,
            ...(deviceData ? { device_data: deviceData } : {}),
        })
    } catch (error) {
        // Backing out of the app or closing the flow is a choice, not a failure.
        const code = (error as { code?: string }).code ?? ''
        if (!code.includes('CANCELED') && !code.includes('CLOSED')) {
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
        <p v-else-if="unavailable" class="text-sm text-base-content/70">
            {{ config.labels?.unavailable }}
        </p>
        <!-- Venmo brand blue; the wordmark is text, kept simple. -->
        <button
            v-else
            type="button"
            :disabled="busy"
            class="w-full max-w-xs h-11 rounded-button border-none cursor-pointer bg-[#008CFF] text-white text-base font-bold transition hover:bg-[#0074D4] disabled:opacity-50 disabled:pointer-events-none"
            @click="pay"
        >
            {{ busy ? config.labels?.waiting : config.labels?.payWith }}
        </button>
    </div>
</template>
