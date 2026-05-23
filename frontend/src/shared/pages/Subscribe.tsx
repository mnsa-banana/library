import { useState, useEffect, useCallback, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth-context'
import { fetchBillingStatus, fetchCheckoutUrl, apiLogout, setApiToken } from '../api'

export function Subscribe() {
  const navigate = useNavigate()
  const { token, logout, setSubscribed } = useAuth()
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
        navigate('/home', { replace: true })
      }
    } catch {
      failCountRef.current += 1
      if (failCountRef.current >= 3) {
        setAwaitingPurchase(false)
        setError('Could not verify subscription. Please try again.')
      }
    }
  }, [setSubscribed, navigate])

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

  return (
    <div className="page-center">
      <div className="auth-card">
        <h1 className="auth-title">Sponge Kids</h1>
        <p className="auth-subtitle">Subscribe to get started</p>

        {cardBody}

        <p className="auth-switch" style={{ marginTop: '1rem' }}>
          <Link to="/account">Manage your account</Link> · <button className="btn-link" onClick={handleLogout}>Sign out</button>
        </p>
      </div>
    </div>
  )
}
