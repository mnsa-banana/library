import { useState, FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth-context'
import { apiLogin, setApiToken } from '../api'

export function Login() {
  const navigate = useNavigate()
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const data = await apiLogin(email, password)
      setApiToken(data.token)
      login(data.token, data.user)
      navigate('/home', { replace: true })
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
        <p className="auth-switch" style={{ textAlign: 'right', margin: '0.25rem 0 1rem' }}>
          <Link to="/forgot">Forgot your password?</Link>
        </p>
        <button type="submit" className="btn-primary" disabled={loading}>
          {loading ? 'Signing in...' : 'Sign In'}
        </button>
      </form>
    </>
  )

  return (
    <div className="page-center">
      <div className="auth-card">
        <h1 className="auth-title">Sponge Kids</h1>
        <p className="auth-subtitle">Welcome back</p>

        {formMarkup}

        <p className="auth-switch">
          Don't have an account? <Link to="/register">Sign up</Link>
        </p>
      </div>
    </div>
  )
}
