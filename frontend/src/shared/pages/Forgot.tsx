import { useState, FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { apiForgotPassword } from '../api'

export function Forgot() {
  const [email, setEmail] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setLoading(true)
    setError(null)
    try {
      await apiForgotPassword(email)
      setSubmitted(true)
    } catch (err: any) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  const body = submitted ? (
    <p style={{ textAlign: 'center', margin: '1.5rem 0' }}>
      If that email's registered, we just sent you a link.
    </p>
  ) : (
    <form onSubmit={handleSubmit}>
      {error && <p className="auth-error">{error}</p>}
      <label className="form-label">
        Email
        <input type="email" className="form-input" required value={email} onChange={(e) => setEmail(e.target.value)} />
      </label>
      <button type="submit" className="btn-primary" disabled={loading}>
        {loading ? 'Sending…' : 'Send reset link'}
      </button>
    </form>
  )

  const card = (
    <>
      {body}
      <p className="auth-switch"><Link to="/login">Back to sign in</Link></p>
    </>
  )

  return (
    <div className="page-center">
      <div className="auth-card">
        <h1 className="auth-title">Forgot your password?</h1>
        {card}
      </div>
    </div>
  )
}
