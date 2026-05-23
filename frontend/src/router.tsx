import { Routes, Route, Navigate } from 'react-router-dom'
import { useAuth } from './shared/auth-context'
import { NotFound } from './shared/pages/NotFound'

import { Login } from './shared/pages/Login'
import { Register } from './shared/pages/Register'
import { Subscribe } from './shared/pages/Subscribe'
import { Forgot } from './shared/pages/Forgot'
import { Reset } from './shared/pages/Reset'
import { EmailConfirm } from './shared/pages/EmailConfirm'
import { Account } from './shared/pages/Account'

import { Splash } from './pages/Splash'
import { Home } from './pages/Home'
import { Report } from './pages/Report'

function RequireAuth({ children }: { children: React.ReactNode }) {
  const { token, isLoading } = useAuth()
  if (isLoading) return null
  if (!token) return <Navigate to="/login" replace />
  return <>{children}</>
}

function RequireSubscription({ children }: { children: React.ReactNode }) {
  const { subscribed, isLoading } = useAuth()
  if (isLoading) return null
  if (subscribed === false) return <Navigate to="/subscribe" replace />
  return <>{children}</>
}

function RedirectIfAuthenticated({ children }: { children: React.ReactNode }) {
  const { token, isLoading } = useAuth()
  if (isLoading) return null
  if (token) return <Navigate to="/home" replace />
  return <>{children}</>
}

export function AppRouter() {
  return (
    <Routes>
      <Route path="/" element={<Splash />} />

      <Route path="/login" element={<RedirectIfAuthenticated><Login /></RedirectIfAuthenticated>} />
      <Route path="/register" element={<RedirectIfAuthenticated><Register /></RedirectIfAuthenticated>} />
      <Route path="/forgot" element={<Forgot />} />
      <Route path="/reset" element={<Reset />} />
      <Route path="/email/confirm" element={<EmailConfirm />} />
      <Route path="/account" element={<RequireAuth><Account /></RequireAuth>} />

      <Route path="/subscribe" element={<RequireAuth><Subscribe /></RequireAuth>} />

      <Route path="/home" element={
        <RequireAuth><RequireSubscription><Home /></RequireSubscription></RequireAuth>
      } />
      <Route path="/reports/:id" element={
        <RequireAuth><RequireSubscription><Report /></RequireSubscription></RequireAuth>
      } />

      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
