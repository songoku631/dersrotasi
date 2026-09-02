const authOnlyPaths = new Set(['/giris', '/login', '/kayit', '/kullanici-adi'])

export function postLoginPath(profile, requestedPath = '') {
  if (!profile?.username?.trim()) return '/kullanici-adi'
  if (requestedPath && !authOnlyPaths.has(requestedPath)) return requestedPath
  return '/'
}
