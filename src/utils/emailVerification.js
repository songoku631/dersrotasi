export const EMAIL_VERIFICATION_COOLDOWN_SECONDS = 60

export function requiresEmailVerification(user) {
  if (!user || user.emailVerified) return false

  return Array.isArray(user.providerData)
    && user.providerData.some((provider) => provider?.providerId === 'password')
}

export function emailVerificationActionSettings() {
  if (typeof window === 'undefined') return undefined

  return {
    url: `${window.location.origin}/eposta-dogrula`,
    handleCodeInApp: false,
  }
}
