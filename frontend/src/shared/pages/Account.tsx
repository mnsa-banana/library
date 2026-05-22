import { useEffect, useState, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth-context'
import { useBrand, isMnsa } from '../brand-context'
import {
  apiChangePassword,
  apiRequestEmailChange,
  apiDeleteAccount,
  apiLogout,
  apiManageUrl,
  fetchBillingStatus,
  setApiToken,
  BillingStatus,
} from '../api'

export function Account() {
  const { user, token, logout } = useAuth()
  const brand = useBrand()
  const navigate = useNavigate()
  const [billing, setBilling] = useState<BillingStatus | null>(null)
  const [billingError, setBillingError] = useState<string | null>(null)

  useEffect(() => {
    fetchBillingStatus({ silentOn401: true })
      .then(setBilling)
      .catch((err: any) => setBillingError(err.message || 'Could not load subscription status.'))
  }, [])

  if (!user) return null

  const backLabel = brand.hasSearch ? '← Back to home' : '← Back to extension'

  const sections = (
    <>
      <section style={{ marginTop: '2rem' }}>
        <h2>Profile</h2>
        <p>Email: <strong>{user.email}</strong></p>
        <ChangeEmail />
      </section>

      <section style={{ marginTop: '2rem' }}>
        <h2>Password</h2>
        <ChangePassword />
      </section>

      <section style={{ marginTop: '2rem' }}>
        <h2>Subscription</h2>
        {billingError && <p className="auth-error">{billingError}</p>}
        {billing?.subscribed
          ? <p>Active — {billing.entitlement?.product_identifier}</p>
          : <p>No active subscription.</p>}
        <button className="btn-secondary" onClick={async () => {
          try {
            const { manage_url } = await apiManageUrl()
            window.open(manage_url, '_blank')
          } catch {}
        }}>Manage subscription</button>
      </section>

      <section style={{ marginTop: '3rem', borderTop: '1px solid #ddd', paddingTop: '1.5rem' }}>
        <h2 style={{ color: '#b00020' }}>Danger zone</h2>
        <DeleteAccount onDeleted={() => {
          setApiToken(null)
          logout()
          navigate('/', { replace: true })
        }} />
      </section>

      <p style={{ marginTop: '2rem' }}>
        <button className="btn-link" onClick={() => { if (token) apiLogout(token).catch(() => {}); setApiToken(null); logout(); navigate('/login', { replace: true }) }}>Sign out</button>
      </p>
    </>
  )

  if (isMnsa(brand.key)) {
    return (
      <div className="mnsa-auth-page" style={{ minHeight: '100vh', padding: '2rem 1rem' }}>
        <div className="auth-shell" style={{ maxWidth: 640, margin: '0 auto' }}>
          <p style={{ marginBottom: '1rem' }}>
            <Link to={brand.postAuthRoute} className="btn-link">{backLabel}</Link>
          </p>
          <header className="auth-hero">
            <p className="auth-eyebrow">Settings</p>
            <h1 className="auth-display-title"><span>Your account</span></h1>
          </header>
          <div className="auth-card auth-card--bare">
            {sections}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div style={{ background: 'var(--color-bg)', minHeight: '100vh', color: 'var(--color-text)' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '1rem 1.5rem' }}>
        <h1 style={{ fontSize: '1.25rem', fontWeight: 800, fontFamily: 'var(--font-heading)' }}>
          Sponge<span style={{ color: 'var(--color-accent-secondary)' }}>.kids</span>
        </h1>
        <Link to={brand.postAuthRoute} className="btn-link">{backLabel}</Link>
      </header>
      <div className="account-page" style={{ maxWidth: 640, margin: '0 auto', padding: '1rem 1.5rem 3rem' }}>
        <h1>Your account</h1>
        {sections}
      </div>
    </div>
  )
}

function ChangePassword() {
  const [current, setCurrent] = useState('')
  const [pw, setPw] = useState('')
  const [confirm, setConfirm] = useState('')
  const [msg, setMsg] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErr(null); setMsg(null); setLoading(true)
    try {
      await apiChangePassword({ current_password: current, password: pw, password_confirmation: confirm })
      setMsg('Password updated.')
      setCurrent(''); setPw(''); setConfirm('')
    } catch (e: any) { setErr(e.message) } finally { setLoading(false) }
  }

  return (
    <form onSubmit={handleSubmit}>
      {err && <p className="auth-error">{err}</p>}
      {msg && <p style={{ color: 'green' }}>{msg}</p>}
      <label className="form-label">Current password
        <input type="password" className="form-input" required value={current} onChange={(e) => setCurrent(e.target.value)} />
      </label>
      <label className="form-label">New password
        <input type="password" className="form-input" required value={pw} onChange={(e) => setPw(e.target.value)} />
      </label>
      <label className="form-label">Confirm new password
        <input type="password" className="form-input" required value={confirm} onChange={(e) => setConfirm(e.target.value)} />
      </label>
      <button className="btn-primary" disabled={loading}>{loading ? 'Saving…' : 'Change password'}</button>
    </form>
  )
}

function ChangeEmail() {
  const [open, setOpen] = useState(false)
  const [current, setCurrent] = useState('')
  const [newEmail, setNewEmail] = useState('')
  const [msg, setMsg] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  if (!open) {
    return <button className="btn-secondary" onClick={() => setOpen(true)}>Change email</button>
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErr(null); setMsg(null); setLoading(true)
    try {
      await apiRequestEmailChange({ current_password: current, new_email: newEmail })
      setMsg(`Check your inbox at ${newEmail} for a confirmation link.`)
      setCurrent(''); setNewEmail('')
    } catch (e: any) { setErr(e.message) } finally { setLoading(false) }
  }

  return (
    <form onSubmit={handleSubmit} style={{ marginTop: '0.5rem' }}>
      {err && <p className="auth-error">{err}</p>}
      {msg && <p style={{ color: 'green' }}>{msg}</p>}
      <label className="form-label">New email
        <input type="email" className="form-input" required value={newEmail} onChange={(e) => setNewEmail(e.target.value)} />
      </label>
      <label className="form-label">Current password
        <input type="password" className="form-input" required value={current} onChange={(e) => setCurrent(e.target.value)} />
      </label>
      <button className="btn-primary" disabled={loading}>{loading ? 'Sending…' : 'Send confirmation'}</button>
      <button type="button" className="btn-link" onClick={() => setOpen(false)} style={{ marginLeft: '0.5rem' }}>Cancel</button>
    </form>
  )
}

function DeleteAccount({ onDeleted }: { onDeleted: () => void }) {
  const [open, setOpen] = useState(false)
  const [current, setCurrent] = useState('')
  const [err, setErr] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  if (!open) {
    return <button className="btn-secondary" onClick={() => setOpen(true)} style={{ color: '#b00020' }}>Delete my account</button>
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setErr(null); setLoading(true)
    try {
      await apiDeleteAccount(current)
      onDeleted()
    } catch (e: any) { setErr(e.message); setLoading(false) }
  }

  return (
    <form onSubmit={handleSubmit} style={{ marginTop: '0.5rem' }}>
      {err && <p className="auth-error">{err}</p>}
      <p>This permanently removes your account. Your subscription on RevenueCat is <em>not</em> cancelled — manage that separately.</p>
      <label className="form-label">Confirm with your password
        <input type="password" className="form-input" required value={current} onChange={(e) => setCurrent(e.target.value)} />
      </label>
      <button className="btn-primary" disabled={loading} style={{ background: '#b00020' }}>
        {loading ? 'Deleting…' : 'Permanently delete'}
      </button>
      <button type="button" className="btn-link" onClick={() => setOpen(false)} style={{ marginLeft: '0.5rem' }}>Cancel</button>
    </form>
  )
}
