import { useState, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth-context'
import { useBrand, isMnsa } from '../brand-context'
import { apiRegister, setApiToken } from '../api'

export function Register() {
  const navigate = useNavigate()
  const { login } = useAuth()
  const brand = useBrand()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const data = await apiRegister(name, email, password, passwordConfirmation)
      setApiToken(data.token)
      login(data.token, data.user)
      navigate(brand.postAuthRoute, { replace: true })
    } catch (err: any) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  const formMarkup = (
    <>
      {error && <p className="auth-error">{error}</p>}

      <form onSubmit={handleSubmit}>
        <label className="form-label">
          Name
          <input
            type="text"
            className="form-input"
            placeholder="Your name"
            value={name}
            onChange={(e) => setName(e.target.value)}
            required
          />
        </label>
        <label className="form-label">
          Email
          <input
            type="email"
            className="form-input"
            placeholder="you@email.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </label>
        <label className="form-label">
          Password
          <input
            type="password"
            className="form-input"
            placeholder="••••••••"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        <label className="form-label">
          Confirm password
          <input
            type="password"
            className="form-input"
            placeholder="••••••••"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            required
          />
        </label>
        <button type="submit" className="btn-primary" disabled={loading}>
          {loading ? 'Creating account...' : 'Create Account'}
        </button>
      </form>
    </>
  )

  if (isMnsa(brand.key)) {
    // Split "Make Netflix {Safe|Straight} Again" → lead + 2-word accent.
    const parts = brand.name.split(' ')
    const titleAccent = parts.slice(-2).join(' ')
    const titleLead = parts.slice(0, -2).join(' ')

    return (
      <div className="page-center mnsa-auth-page">
        <div className="auth-shell">
          <header className="auth-hero">
            <p className="auth-eyebrow">Take back control</p>
            <h1 className="auth-display-title">
              {titleLead}<span>{titleAccent}</span>
            </h1>
            <p className="auth-hero__subtitle">
              You're <strong>5 min</strong> away from protecting your family.
            </p>
          </header>

          <div className="auth-card auth-card--bare">
            {formMarkup}
          </div>

          <p className="auth-switch auth-switch--external">
            Already have an account? <Link to="/login">Sign in</Link>
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="page-center">
      <div className="auth-card">
        <h1 className="auth-title">{brand.name}</h1>
        <p className="auth-subtitle">Create account</p>

        {formMarkup}

        <p className="auth-switch">
          Already have an account? <Link to="/login">Sign in</Link>
        </p>
      </div>
    </div>
  )
}
