import { IYZICO_CHECKOUT_ENDPOINT, PAYMENT_UNAVAILABLE_MESSAGE } from '../config/premium'

export { IYZICO_CHECKOUT_ENDPOINT }

export const iyzicoCheckoutRequest = Object.freeze({
  method: 'POST',
  url: IYZICO_CHECKOUT_ENDPOINT,
})

export async function startIyzicoCheckout() {
  // The future authenticated POST endpoint is intentionally not called until Iyzico is configured.
  // No payment or card data is collected or stored by this application in the meantime.
  throw new Error(PAYMENT_UNAVAILABLE_MESSAGE)
}
