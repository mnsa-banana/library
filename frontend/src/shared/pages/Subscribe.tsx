import { useState, useEffect, useCallback, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth-context'
import { useBrand, isMnsa } from '../brand-context'
import { fetchBillingStatus, fetchCheckoutUrl, apiLogout, setApiToken } from '../api'

export function Subscribe() {
  const navigate = useNavigate()
  const { token, logout, setSubscribed } = useAuth()
  const brand = useBrand()
  const [loading, setLoading] = useState(false)
  const [awaitingPurchase, setAwaitingPurchase] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const failCountRef = useRef(0)

  const checkSubscription = useCallback(async () => {
    try {
      const status = await fetchBillingStatus()
      failCountRef.current = 0
      if (status.subscribed) {
        setAwaitingPurchase(false)
        setSubscribed(true)
        navigate(brand.postAuthRoute, { replace: true })
      }
    } catch {
      failCountRef.current += 1
      if (failCountRef.current >= 3) {
        setAwaitingPurchase(false)
        setError('Could not verify subscription. Please try again.')
      }
    }
  }, [setSubscribed, navigate, brand.postAuthRoute])

  useEffect(() => {
    if (!awaitingPurchase) return
    checkSubscription()
    const interval = setInterval(checkSubscription, 3000)
    const onVisibilityChange = () => {
      if (document.visibilityState === 'visible') checkSubscription()
    }
    document.addEventListener('visibilitychange', onVisibilityChange)
    return () => {
      clearInterval(interval)
      document.removeEventListener('visibilitychange', onVisibilityChange)
    }
  }, [awaitingPurchase, checkSubscription])

  async function handleSubscribe() {
    setError(null)
    setLoading(true)
    try {
      const { checkout_url } = await fetchCheckoutUrl()
      window.open(checkout_url, '_blank')
      setAwaitingPurchase(true)
    } catch {
      setError('Could not open checkout. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  function handleLogout() {
    if (token) apiLogout(token).catch(() => {})
    setApiToken(null)
    logout()
  }

  const cardBody = (
    <>
      {error && <p className="auth-error">{error}</p>}

      {awaitingPurchase ? (
        <div>
          <p style={{ textAlign: 'center', margin: '1.5rem 0' }}>Waiting for purchase to complete...</p>
          <button className="btn-secondary" onClick={() => setAwaitingPurchase(false)}>
            Cancel
          </button>
        </div>
      ) : (
        <button className="btn-primary" onClick={handleSubscribe} disabled={loading}>
          {loading ? 'Loading...' : 'Subscribe Now'}
        </button>
      )}
    </>
  )

  if (isMnsa(brand.key)) {
    const parts = brand.name.split(' ')
    const titleAccent = parts.slice(-2).join(' ')
    const titleLead = parts.slice(0, -2).join(' ')

    return (
      <div className="page-center mnsa-auth-page">
        <div className="auth-shell">
          <header className="auth-hero">
            <p className="auth-eyebrow">Almost there</p>
            <h1 className="auth-display-title">
              {titleLead}<span>{titleAccent}</span>
            </h1>
            <p className="auth-hero__subtitle">
              One more step to unlock everything {brand.name} can do.
            </p>
          </header>

          <div className="auth-card auth-card--bare">
            {cardBody}
          </div>

          <p className="auth-switch auth-switch--external">
            Already a subscriber? <Link to="/account">Manage account</Link> · <button className="btn-link" onClick={handleLogout}>Sign out</button>
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="page-center">
      <div className="auth-card">
        <h1 className="auth-title">{brand.name}</h1>
        <p className="auth-subtitle">Subscribe to get started</p>

        {cardBody}

        <p className="auth-switch" style={{ marginTop: '1rem' }}>
          <Link to="/account">Manage your account</Link> · <button className="btn-link" onClick={handleLogout}>Sign out</button>
        </p>
      </div>
    </div>
  )
}
