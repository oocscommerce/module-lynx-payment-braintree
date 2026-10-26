<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import * as btClient from 'braintree-web/client'
import * as btDataCollector from 'braintree-web/data-collector'
import * as btUsBankAccount from 'braintree-web/us-bank-account'
import { config as storeConfig } from 'OOCS_LynxTheme/utils/storeConfig'
import { useCheckoutStore } from 'OOCS_LynxCheckout/stores/checkoutStore'
import { formatPrice } from 'OOCS_LynxTheme/utils/price'

// Renderer of braintree_ach_direct_debit

const props = defineProps<{
    config: {
        // Every string the renderer shows, translated by the config class on the server.
        labels?: Record<string, string>
        storeName?: string
    }
}>()

const checkout = useCheckoutStore()
const { draft } = checkout

const setupError = ref('')
// eslint-disable-next-line @typescript-eslint/no-explicit-any
let usBankAccount: any = null
let deviceData = ''

const form = reactive({
    accountHolder: '',
    routingNumber: '',
    accountNumber: '',
    accountType: 'checking',
    ownershipType: 'personal',
})
const errors = reactive<Record<string, string>>({})
const ic = (key: string) => checkout.inputClass(!!errors[key])

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
        usBankAccount = await btUsBankAccount.create({ client })
    } catch {
        setupError.value = 'ACH payment could not be loaded. Please try again or choose another method.'
    }
})

/** The debit authorization the shopper agrees to; tokenized along with the details. */
const mandateText = computed(() => {
    const store = props.config.storeName || ''
    const amount = formatPrice(checkout.grandTotal?.value ?? 0)
    const date = new Date().toLocaleDateString()

    return (props.config.labels?.mandate ?? '').replaceAll('%1', store).replace('%2', amount).replace('%3', date)
})

function validateForm(): boolean {
    Object.keys(errors).forEach(key => delete errors[key])

    if (!form.accountHolder.trim()) errors.accountHolder = 'Required'
    if (!/^\d{9}$/.test(form.routingNumber)) errors.routingNumber = props.config.labels?.invalidRouting ?? ''
    if (!/^\d{4,17}$/.test(form.accountNumber)) errors.accountNumber = props.config.labels?.invalidAccount ?? ''

    return !Object.keys(errors).length
}

defineExpose({
    /** Form shape of the renderer contract: resolve additional_data or throw. */
    async preparePayment(): Promise<Record<string, unknown>> {
        if (!usBankAccount) throw new Error(props.config.labels?.stillLoading ?? '')
        if (!validateForm()) throw new Error(props.config.labels?.checkDetails ?? '')

        const billing = draft.sameAsBilling ? draft.shippingAddr : draft.billingAddr

        // A personal account is held by a person (first/last name), a business one by the business
        const holder = form.accountHolder.trim()
        const [firstName, ...rest] = holder.split(/\s+/)
        const ownership =
            form.ownershipType === 'business'
                ? { ownershipType: 'business', businessName: holder }
                : { ownershipType: 'personal', firstName, lastName: rest.join(' ') || firstName }

        const { nonce } = await usBankAccount.tokenize({
            bankDetails: {
                routingNumber: form.routingNumber,
                accountNumber: form.accountNumber,
                accountType: form.accountType,
                ...ownership,
                billingAddress: {
                    streetAddress: billing.street0,
                    extendedAddress: billing.street1,
                    locality: billing.city,
                    region: billing.region,
                    postalCode: billing.postcode,
                },
            },
            mandateText: mandateText.value,
        })

        return {
            payment_method_nonce: nonce,
            ...(deviceData ? { device_data: deviceData } : {}),
        }
    },
})
</script>

<template>
    <div class="grid gap-3">
        <p v-if="setupError" class="text-sm text-error">{{ setupError }}</p>

        <template v-else>
            <div class="grid gap-1.5">
                <label for="braintree-ach-holder" class="w-fit text-sm text-base-content"
                    >{{ config.labels?.holderName }} <span class="text-error">*</span></label
                >
                <input
                    id="braintree-ach-holder"
                    v-model="form.accountHolder"
                    type="text"
                    autocomplete="off"
                    :class="ic('accountHolder')"
                />
                <p v-if="errors.accountHolder" data-checkout-error class="text-sm text-error">
                    {{ errors.accountHolder }}
                </p>
            </div>

            <div class="grid grid-cols-2 gap-3 items-start">
                <div class="grid gap-1.5">
                    <label for="braintree-ach-routing" class="w-fit text-sm text-base-content"
                        >{{ config.labels?.routingNumber }} <span class="text-error">*</span></label
                    >
                    <input
                        id="braintree-ach-routing"
                        v-model="form.routingNumber"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        :class="ic('routingNumber')"
                    />
                    <p v-if="errors.routingNumber" data-checkout-error class="text-sm text-error">
                        {{ errors.routingNumber }}
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <label for="braintree-ach-account" class="w-fit text-sm text-base-content"
                        >{{ config.labels?.accountNumber }} <span class="text-error">*</span></label
                    >
                    <input
                        id="braintree-ach-account"
                        v-model="form.accountNumber"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        :class="ic('accountNumber')"
                    />
                    <p v-if="errors.accountNumber" data-checkout-error class="text-sm text-error">
                        {{ errors.accountNumber }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 items-start">
                <div class="grid gap-1.5">
                    <label for="braintree-ach-type" class="w-fit text-sm text-base-content">{{
                        config.labels?.accountType
                    }}</label>
                    <select id="braintree-ach-type" v-model="form.accountType" :class="ic('')">
                        <option value="checking">{{ config.labels?.checking }}</option>
                        <option value="savings">{{ config.labels?.savings }}</option>
                    </select>
                </div>
                <div class="grid gap-1.5">
                    <label for="braintree-ach-ownership" class="w-fit text-sm text-base-content">{{
                        config.labels?.ownership
                    }}</label>
                    <select id="braintree-ach-ownership" v-model="form.ownershipType" :class="ic('')">
                        <option value="personal">{{ config.labels?.personal }}</option>
                        <option value="business">{{ config.labels?.business }}</option>
                    </select>
                </div>
            </div>

            <p class="text-sm leading-relaxed text-base-content/70">{{ mandateText }}</p>
        </template>
    </div>
</template>
