import { Routes, Route, Navigate } from 'react-router-dom'
import { useBrand } from './shared/brand-context'
import { useAuth } from './shared/auth-context'
import { NotFound } from './shared/pages/NotFound'

import { Login } from './shared/pages/Login'
import { Register } from './shared/pages/Register'
import { Subscribe } from './shared/pages/Subscribe'
import { Forgot } from './shared/pages/Forgot'
import { Reset } from './shared/pages/Reset'
import { EmailConfirm } from './shared/pages/EmailConfirm'
import { Account } from './shared/pages/Account'

import { MnsaSplash } from './brands/mnsa/pages/Splash'
import { MnsaExtension } from './brands/mnsa/pages/Extension'
import { SkSplash } from './brands/sponge-kids/pages/Splash'
import { SkHome } from './brands/sponge-kids/pages/Home'
import { SkReport } from './brands/sponge-kids/pages/Report'

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
  const brand = useBrand()
  if (isLoading) return null
  if (token) return <Navigate to={brand.postAuthRoute} replace />
  return <>{children}</>
}

export function AppRouter() {
  const brand = useBrand()

  return (
    <Routes>
      {/* Public: splash */}
      <Route path="/" element={brand.hasSearch ? <SkSplash /> : <MnsaSplash />} />

      {/* Auth routes */}
      <Route path="/login" element={<RedirectIfAuthenticated><Login /></RedirectIfAuthenticated>} />
      <Route path="/register" element={<RedirectIfAuthenticated><Register /></RedirectIfAuthenticated>} />
      <Route path="/forgot" element={<Forgot />} />
      <Route path="/reset" element={<Reset />} />
      <Route path="/email/confirm" element={<EmailConfirm />} />
      <Route path="/account" element={<RequireAuth><Account /></RequireAuth>} />

      {/* Subscription */}
      <Route path="/subscribe" element={<RequireAuth><Subscribe /></RequireAuth>} />

      {/* Sponge Kids only */}
      {brand.hasSearch && (
        <Route path="/home" element={
          <RequireAuth><RequireSubscription><SkHome /></RequireSubscription></RequireAuth>
        } />
      )}
      {brand.hasReports && (
        <Route path="/reports/:id" element={
          <RequireAuth><RequireSubscription><SkReport /></RequireSubscription></RequireAuth>
        } />
      )}

      {/* MNSA only */}
      {brand.hasExtensionPage && (
        <Route path="/extension" element={
          <RequireAuth><RequireSubscription><MnsaExtension /></RequireSubscription></RequireAuth>
        } />
      )}

      {/* 404 */}
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
