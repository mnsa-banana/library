import { useEffect } from 'react'
import { BrowserRouter } from 'react-router-dom'
import { BrandContext, detectBrand } from './shared/brand-context'
import { AuthProvider, useAuth } from './shared/auth-context'
import { setApiToken, setApiBrand, setOnUnauthorized, fetchMe } from './shared/api'
import { AppRouter } from './router'

import './brands/mnsa/theme.css'
import './brands/mnsa/app.css'
import './brands/sponge-kids/theme.css'

const brand = detectBrand()
setApiBrand(brand.key)
// Bootstrap the API token synchronously from localStorage so any useEffect that
// fires on first mount (e.g. fetchBillingStatus on /account) has the bearer
// token available — without this, child effects fire before AuthSync's effect
// has had a chance to sync _token, causing a 401 → auto-logout → redirect.
setApiToken(localStorage.getItem('auth_token'))

function AuthSync({ children }: { children: React.ReactNode }) {
  const { token, subscribed, logout, setSubscribed } = useAuth()

  useEffect(() => {
    setApiToken(token)
    setOnUnauthorized(() => logout())
  }, [token, logout])

  useEffect(() => {
    if (!token) return
    fetchMe()
      .then((me) => setSubscribed(me.subscribed))
      .catch(() => {})
  }, [token])

  return <>{children}</>
}

export function App() {
  useEffect(() => {
    document.documentElement.setAttribute('data-brand', brand.key)
    document.title = brand.name
  }, [])

  return (
    <BrandContext.Provider value={brand}>
      <AuthProvider>
        <AuthSync>
          <BrowserRouter>
            <AppRouter />
          </BrowserRouter>
        </AuthSync>
      </AuthProvider>
    </BrandContext.Provider>
  )
}
