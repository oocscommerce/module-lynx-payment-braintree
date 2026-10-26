<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
// Component-level imports: the braintree-web root index would drag every Braintree product into the bundle
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btHostedFields from 'braintree-web/hosted-fields'
import * as btThreeDSecure from 'braintree-web/three-d-secure'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'

// The Lynx renderer of the `braintree` card method

type ThreeDSecureConfig = {
    enabled: boolean
    challengeRequested: boolean
    thresholdAmount: number
    specificCountries: string[]
}

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        useCvv?: boolean
        threeDSecure?: ThreeDSecureConfig
    }
}>()

const checkout = useCheckoutStore()
const { draft } = checkout

const numberEl = ref<HTMLElement | null>(null)
const expiryEl = ref<HTMLElement | null>(null)
const cvvEl = ref<HTMLElement | null>(null)

/** The gateway could not be reached or configured: the form never appears. */
const setupError = ref('')
// False until the hosted-field iframes have loaded into their containers
const ready = ref(false)
/** Per-field messages, keyed by the hosted field names (number, expirationDate, cvv). */
const fieldErrors = reactive<Record<string, string>>({})
const ic = (key: string) => checkout.inputClass(!!fieldErrors[key])

let clientInstance: btClient.Client | null = null
/**
 * The gateway's fraud signal (device fingerprint), collected the way Luma always does
 */
let deviceData = ''
let fields: btHostedFields.HostedFields | null = null

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
        clientInstance = await btClient.create({ authorization: await fetchClientToken() })
        btDataCollector
            .create({ client: clientInstance })
            .then((collector: { deviceData: string }) => {
                deviceData = collector.deviceData
            })
            .catch(() => {})
        // The iframes cannot read the design's CSS variables, so the text takes its container's resolved colour and size
        const { color, fontSize } = getComputedStyle(numberEl.value!)
        fields = await btHostedFields.create({
            client: clientInstance!,
            // Only the text inside the gateway's iframes; the boxes around them are the container divs below
            styles: {
                input: { 'font-size': fontSize, color },
                '::placeholder': { color, opacity: '0.6' },
            },
            fields: {
                number: { container: numberEl.value!, placeholder: props.config.labels?.cardNumber ?? '' },
                expirationDate: { container: expiryEl.value!, placeholder: 'MM/YY' },
                ...(props.config.useCvv ? { cvv: { container: cvvEl.value!, placeholder: 'CVV' } } : {}),
            },
        })
        ready.value = true
    } catch {
        setupError.value = props.config.labels?.loadFailed ?? ''
    }
})

onBeforeUnmount(() => fields?.teardown())

/** Tokenize the fields into a nonce; invalid input marks its fields and throws. */
async function tokenize(): Promise<{ nonce: string; bin: string }> {
    Object.keys(fieldErrors).forEach(key => delete fieldErrors[key])

    try {
        const { nonce, details } = await fields!.tokenize()
        return { nonce, bin: details.bin }
    } catch (error) {
        const err = error as { code?: string; details?: { invalidFieldKeys?: string[] } }
        for (const key of err.details?.invalidFieldKeys ?? []) {
            fieldErrors[key] = 'Invalid'
        }
        if (err.code === 'HOSTED_FIELDS_FIELDS_EMPTY') {
            fieldErrors.number = fieldErrors.expirationDate = 'Required'
            if (props.config.useCvv) fieldErrors.cvv = 'Required'
        }
        throw new Error(props.config.labels?.checkDetails ?? '', { cause: error })
    }
}

/**
 * 3D Secure, under the admin's rules: only when enabled
 */
async function verify3ds(nonce: string, bin: string): Promise<string> {
    const config = props.config.threeDSecure
    const amount = checkout.grandTotal?.value ?? 0
    const billing = draft.sameAsBilling ? draft.shippingAddr : draft.billingAddr

    if (!config?.enabled) return nonce
    if (amount < config.thresholdAmount) return nonce
    if (config.specificCountries.length && !config.specificCountries.includes(billing.country_id ?? '')) {
        return nonce
    }

    const threeDS = await btThreeDSecure.create({ client: clientInstance!, version: 2 })
    try {
        const response = await threeDS.verifyCard({
            amount: amount.toFixed(2),
            nonce,
            bin,
            challengeRequested: config.challengeRequested,
            ...(draft.email ? { email: draft.email } : {}),
            billingAddress: {
                givenName: billing.firstname,
                surname: billing.lastname,
                phoneNumber: (billing.telephone ?? '').replace(/\D/g, ''),
                streetAddress: billing.street0,
                extendedAddress: billing.street1,
                locality: billing.city,
                postalCode: billing.postcode,
                countryCodeAlpha2: billing.country_id,
            },
            // The verification flow (the bank's challenge) renders in the SDK's own modal; the lookup just proceeds
            onLookupComplete: (_data: unknown, next: () => void) => next(),
        })

        if (response.threeDSecureInfo.liabilityShifted || !response.threeDSecureInfo.liabilityShiftPossible) {
            return response.nonce
        }
        throw new Error(props.config.labels?.tryAnother ?? '')
    } finally {
        void threeDS.teardown()
    }
}

defineExpose({
    /** Renderer contract: the hosted fields are drawn and usable. */
    loaded: ready,
    /** Form shape of the renderer contract: resolve additional_data or throw. */
    async preparePayment(): Promise<Record<string, unknown>> {
        if (!fields) throw new Error(props.config.labels?.stillLoading ?? '')

        const { nonce, bin } = await tokenize()
        return {
            payment_method_nonce: await verify3ds(nonce, bin),
            ...(deviceData ? { device_data: deviceData } : {}),
        }
    },
})
</script>

<template>
    <div class="grid gap-3">
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>

        <!-- relative: a skeleton overlays the field containers while their iframes load -->
        <div v-else class="relative grid gap-3">
            <div v-if="!ready" class="absolute inset-0 z-10 grid gap-3 bg-base-100" aria-hidden="true">
                <div class="grid gap-1.5">
                    <div class="l-skeleton h-4 w-24"></div>
                    <div class="l-skeleton h-10"></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <div class="l-skeleton h-4 w-20"></div>
                        <div class="l-skeleton h-10"></div>
                    </div>
                    <div v-if="config.useCvv" class="grid gap-1.5">
                        <div class="l-skeleton h-4 w-10"></div>
                        <div class="l-skeleton h-10"></div>
                    </div>
                </div>
            </div>

            <div class="grid gap-1.5">
                <label class="w-fit text-sm text-base-content"
                    >{{ config.labels?.cardNumber }} <span class="text-error">*</span></label
                >
                <div ref="numberEl" :class="ic('number')"></div>
                <p v-if="fieldErrors.number" data-checkout-error class="text-sm text-error">{{ fieldErrors.number }}</p>
            </div>

            <div class="grid grid-cols-2 gap-3 items-start">
                <div class="grid gap-1.5">
                    <label class="w-fit text-sm text-base-content"
                        >{{ config.labels?.expiration }} <span class="text-error">*</span></label
                    >
                    <div ref="expiryEl" :class="ic('expirationDate')"></div>
                    <p v-if="fieldErrors.expirationDate" data-checkout-error class="text-sm text-error">
                        {{ fieldErrors.expirationDate }}
                    </p>
                </div>
                <div v-if="config.useCvv" class="grid gap-1.5">
                    <label class="w-fit text-sm text-base-content"
                        >{{ config.labels?.cvv }} <span class="text-error">*</span></label
                    >
                    <div ref="cvvEl" :class="ic('cvv')"></div>
                    <p v-if="fieldErrors.cvv" data-checkout-error class="text-sm text-error">{{ fieldErrors.cvv }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
