import { Link } from 'react-router-dom'
import { useBrand } from '../../../shared/brand-context'
import { useAuth } from '../../../shared/auth-context'
import { apiLogout, setApiToken } from '../../../shared/api'
import { useExtensionState } from '../../../shared/useExtensionState'

const EYEBROWS = {
  unknown: 'Checking…',
  'not-installed': 'Almost ready',
  installed: "You're in",
  open: "You're in",
} as const

const SUBTITLES = {
  unknown: 'Looking for the browser extension…',
  'not-installed': 'Install the browser extension to start watching together.',
  installed: 'Open it from your toolbar to get started.',
  open: 'The side panel is open — continue there.',
} as const

export function MnsaExtension() {
  const brand = useBrand()
  const { token, logout } = useAuth()
  const extState = useExtensionState()

  function handleLogout() {
    if (token) apiLogout(token).catch(() => {})
    setApiToken(null)
    logout()
  }

  const parts = brand.name.split(' ')
  const titleAccent = parts.slice(-2).join(' ')
  const titleLead = parts.slice(0, -2).join(' ')

  return (
    <div className="page-center mnsa-auth-page">
      <div className="auth-shell">
        <header className="auth-hero">
          <p className="auth-eyebrow">{EYEBROWS[extState]}</p>
          <h1 className="auth-display-title">
            {titleLead}<span>{titleAccent}</span>
          </h1>
          <p className="auth-hero__subtitle">{SUBTITLES[extState]}</p>
        </header>

        <div className="auth-card auth-card--bare" style={{ textAlign: 'center' }}>
          {extState === 'not-installed' && (
            <>
              <a href="#" className="btn-primary" style={{ textDecoration: 'none', textAlign: 'center', marginBottom: '1rem' }}>
                Install Chrome Extension
              </a>
              <p style={{ fontSize: '0.8125rem', color: 'var(--color-text-muted)' }}>
                The install link will be added when the Chrome Web Store listing is live.
              </p>
            </>
          )}

          {extState === 'installed' && (
            <p className="ext-toolbar-hint">
              <span className="ext-arrow-up-right" aria-hidden>↗</span>
              Click the extension icon (or the puzzle-piece menu) in the top-right of your browser.
            </p>
          )}

          {extState === 'open' && (
            <div className="ext-continue">
              <div className="ext-continue-row" aria-label="Continue in the sidebar">
                <span className="ext-continue-text">Continue in the sidebar</span>
                <span className="ext-arrow-track" aria-hidden>
                  <span className="ext-arrow ext-arrow-1">→</span>
                  <span className="ext-arrow ext-arrow-2">→</span>
                  <span className="ext-arrow ext-arrow-3">→</span>
                </span>
              </div>
            </div>
          )}
        </div>

        <p className="auth-switch auth-switch--external">
          <Link to="/account">Manage account</Link> · <button className="btn-link" onClick={handleLogout}>Sign out</button>
        </p>
      </div>
    </div>
  )
}
