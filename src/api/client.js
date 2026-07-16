const apiBaseUrl = String(import.meta.env.VITE_API_BASE_URL || '').trim().replace(/\/+$/, '')

function apiUrl(path) {
  return `${apiBaseUrl}/${String(path).replace(/^\/+/, '')}`
}

function errorMessageForStatus(status, responseMessage) {
  if (status === 401) return responseMessage || 'Oturum doğrulanamadı. Lütfen yeniden giriş yap.'
  if (status === 403) return 'Bu işlem için yetkin bulunmuyor.'
  if (status === 404) return responseMessage || 'İstenen bilgi bulunamadı.'
  if (status === 422) return responseMessage || 'Gönderilen bilgiler geçerli değil.'
  if (status >= 500) return 'İşlem şu anda tamamlanamadı. Lütfen daha sonra tekrar dene.'
  return responseMessage || 'İstek tamamlanamadı. Lütfen tekrar dene.'
}

async function parseResponse(response) {
  const text = await response.text()
  if (!text) return {}
  try {
    return JSON.parse(text)
  } catch {
    throw new Error('Sunucudan beklenmeyen bir yanıt alındı. Lütfen tekrar dene.')
  }
}

export async function apiRequest(
  path,
  { user = null, auth = false, method = 'GET', body, signal, headers = {} } = {},
  forceRefresh = false,
) {
  if (!apiBaseUrl) {
    throw new Error('Bağlantı ayarları eksik. Lütfen site yöneticisine bildir.')
  }
  if (auth && !user) {
    throw new Error('Bu işlem için giriş yapmalısın.')
  }

  let token = null
  if (user) {
    try {
      token = await user.getIdToken(forceRefresh)
    } catch {
      throw new Error('Oturum bilgilerin yenilenemedi. Lütfen yeniden giriş yap.')
    }
  }

  let response
  try {
    response = await fetch(apiUrl(path), {
      method,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
      headers: {
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...headers,
      },
    })
  } catch (error) {
    if (error.name === 'AbortError') throw error
    throw new Error('Sunucuya ulaşılamıyor. İnternet bağlantını kontrol edip tekrar dene.')
  }

  if (response.status === 401 && user && !forceRefresh) {
    return apiRequest(path, { user, auth, method, body, signal, headers }, true)
  }
  const data = await parseResponse(response)
  if (!response.ok) {
    const requestError = new Error(errorMessageForStatus(response.status, data.message))
    requestError.status = response.status
    throw requestError
  }
  return data
}

export function getCurrentUser(user, signal) {
  return apiRequest('/api/me', { user, auth: true, signal })
}

export function getProfile(user, signal) {
  return apiRequest('/api/profile', { user, auth: true, signal })
}

export function saveProfile(user, profile, signal) {
  return apiRequest('/api/profile', { user, auth: true, method: 'PUT', body: profile, signal })
}
