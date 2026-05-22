import { useState, FormEvent } from 'react'
import { useNavigate, useSearchParams, Link } from 'react-router-dom'
import { apiResetPassword, fetchMe, setApiToken } from '../api'
import { useAuth } from '../auth-context'
import { useBrand, isMnsa } from '../brand-context'

export function Reset() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { login } = useAuth()
  const brand = useBrand()
  const email = params.get('email') ?? ''
  const token = params.get('token') ?? ''

  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const data = await apiResetPassword({
        email,
        token,
        password,
        password_confirmation: confirm,
      })
      setApiToken(data.token)
      // Pull subscribed flag so previously-subscribed users don't transit /subscribe.
      let subscribed: boolean | undefined
      try {
        const me = await fetchMe()
        subscribed = me.subscribed
      } catch {}
      login(data.token, data.user, subscribed)
      navigate(brand.postAuthRoute, { replace: true })
    } catch (err: any) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  if (!email || !token) {
    return (
      <div className="page-center">
        <div className="auth-card">
          <p className="auth-error">Bad reset link. <Link to="/forgot">Request a new one.</Link></p>
        </div>
      </div>
    )
  }

  const form = (
    <>
      {error && <p className="auth-error">{error}</p>}
      <form onSubmit={handleSubmit}>
        <label className="form-label">
          New password
          <input type="password" className="form-input" required value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        <label className="form-label">
          Confirm password
          <input type="password" className="form-input" required value={confirm} onChange={(e) => setConfirm(e.target.value)} />
        </label>
        <button type="submit" className="btn-primary" disabled={loading}>
          {loading ? 'Resetting…' : 'Reset password'}
        </button>
      </form>
    </>
  )

  if (isMnsa(brand.key)) {
    return (
      <div className="page-center mnsa-auth-page">
        <div className="auth-shell">
          <div className="auth-card auth-card--bare">{form}</div>
        </div>
      </div>
    )
  }
  return (
    <div className="page-center">
      <div className="auth-card">
        <h1 className="auth-title">Set a new password</h1>
        {form}
      </div>
    </div>
  )
}
