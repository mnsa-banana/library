import { useEffect } from 'react'
import { BrowserRouter } from 'react-router-dom'
import { AuthProvider, useAuth } from './shared/auth-context'
import { setApiToken, setOnUnauthorized, fetchMe } from './shared/api'
import { AppRouter } from './router'

import './theme.css'

setApiToken(localStorage.getItem('auth_token'))

function AuthSync({ children }: { children: React.ReactNode }) {
  const { token, logout, setSubscribed } = useAuth()

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
    document.title = 'Sponge Kids'
  }, [])

  return (
    <AuthProvider>
      <AuthSync>
        <BrowserRouter>
          <AppRouter />
        </BrowserRouter>
      </AuthSync>
    </AuthProvider>
  )
}
