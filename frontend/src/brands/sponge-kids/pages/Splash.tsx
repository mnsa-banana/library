import { Link } from 'react-router-dom'
import { useAuth } from '../../../shared/auth-context'

export function SkSplash() {
  const { token } = useAuth()

  return (
    <div className="page-center" style={{ background: 'var(--color-bg)', color: 'var(--color-text)', minHeight: '100vh' }}>
      <div style={{ maxWidth: 480, textAlign: 'center', padding: '2rem' }}>
        <h1 style={{ fontSize: '2.5rem', fontWeight: 800, marginBottom: '0.25rem', fontFamily: 'var(--font-heading)' }}>
          Sponge<span style={{ color: 'var(--color-accent-secondary)' }}>.kids</span>
        </h1>
        <p style={{ fontSize: '1.125rem', color: 'var(--color-text-muted)', marginBottom: '2.5rem', lineHeight: 1.6 }}>
          Their brain is a sponge. Make sure they soak up the good stuff.
        </p>
        <p style={{ color: 'var(--color-text-muted)', marginBottom: '2.5rem', lineHeight: 1.7 }}>
          Placeholder — real marketing copy coming soon.
        </p>
        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
          {token ? (
            <Link to="/home" className="btn-primary" style={{ textDecoration: 'none', textAlign: 'center' }}>
              Go to App
            </Link>
          ) : (
            <>
              <Link to="/register" className="btn-primary" style={{ textDecoration: 'none', textAlign: 'center' }}>
                Get Started
              </Link>
              <Link to="/login" className="btn-secondary" style={{ textDecoration: 'none', textAlign: 'center' }}>
                Sign In
              </Link>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
