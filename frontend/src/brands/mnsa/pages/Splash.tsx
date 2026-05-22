import { Navigate } from 'react-router-dom'
import { useAuth } from '../../../shared/auth-context'
import { useBrand } from '../../../shared/brand-context'

// On an MNSA host the initial request to "/" is served by Laravel's Blade landing
// page, not the SPA. This component only renders if something inside the SPA
// client-side-navigates to "/" (nothing currently does) — it just redirects on.
export function MnsaSplash() {
  const { token } = useAuth()
  const brand = useBrand()
  return <Navigate to={token ? brand.postAuthRoute : '/register'} replace />
}
