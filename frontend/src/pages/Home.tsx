import { useEffect, useState, useCallback, useRef } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { fetchReports, apiLogout, setApiToken, ReportSummary } from '../shared/api'
import { useAuth } from '../shared/auth-context'

const MIN_SEARCH_LENGTH = 3

export function Home() {
  const navigate = useNavigate()
  const { token, logout } = useAuth()
  const [reports, setReports] = useState<ReportSummary[]>([])
  const [results, setResults] = useState<ReportSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [searching, setSearching] = useState(false)
  const [query, setQuery] = useState('')
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const showingResults = query.trim().length >= MIN_SEARCH_LENGTH

  function handleLogout() {
    if (token) apiLogout(token).catch(() => {})
    setApiToken(null)
    logout()
  }

  useEffect(() => {
    fetchReports(1)
      .then((res) => setReports(res.data))
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const handleSearch = useCallback((text: string) => {
    setQuery(text)
    if (debounceRef.current) clearTimeout(debounceRef.current)

    if (text.trim().length < MIN_SEARCH_LENGTH) {
      setResults([])
      setSearching(false)
      return
    }

    debounceRef.current = setTimeout(() => {
      setSearching(true)
      fetchReports(1, text.trim())
        .then((res) => setResults(res.data))
        .catch(() => {})
        .finally(() => setSearching(false))
    }, 300)
  }, [])

  if (loading) {
    return (
      <div className="page-center" style={{ background: 'var(--color-bg)' }}>
        <p>Loading...</p>
      </div>
    )
  }

  const displayReports = showingResults ? results : reports

  return (
    <div style={{ background: 'var(--color-bg)', minHeight: '100vh', color: 'var(--color-text)' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '1rem 1.5rem' }}>
        <h1 style={{ fontSize: '1.25rem', fontWeight: 800, fontFamily: 'var(--font-heading)' }}>
          Sponge<span style={{ color: 'var(--color-accent-secondary)' }}>.kids</span>
        </h1>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '0.75rem' }}>
          <Link to="/account" className="btn-link">Account</Link>
          <span style={{ color: 'var(--color-text-muted)' }}>·</span>
          <button className="btn-link" onClick={handleLogout}>Sign out</button>
        </span>
      </header>

      <div style={{ maxWidth: 600, margin: '0 auto', padding: '0 1.5rem' }}>
        {!showingResults && (
          <div style={{ textAlign: 'center', padding: '2rem 0' }}>
            <h2 style={{ fontSize: '1.75rem', fontWeight: 800, marginBottom: '0.5rem', fontFamily: 'var(--font-heading)' }}>
              What are they watching tonight?
            </h2>
            <p style={{ color: 'var(--color-text-muted)', marginBottom: '1.5rem' }}>
              Search any movie or show for a parent-friendly content breakdown
            </p>
          </div>
        )}

        <input
          type="text"
          className="form-input"
          placeholder="Search movies and shows..."
          value={query}
          onChange={(e) => handleSearch(e.target.value)}
          style={{ marginBottom: '1.5rem' }}
        />

        {searching && <p style={{ textAlign: 'center', color: 'var(--color-text-muted)' }}>Searching...</p>}

        {showingResults && !searching && (
          <p style={{ color: 'var(--color-text-muted)', marginBottom: '1rem', fontSize: '0.875rem', textTransform: 'uppercase', letterSpacing: '0.05em' }}>
            {results.length} {results.length === 1 ? 'result' : 'results'}
          </p>
        )}

        <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
          {displayReports.map((report) => (
            <div
              key={report.id}
              onClick={() => navigate(`/reports/${report.id}`)}
              style={{
                background: 'var(--color-surface)',
                borderRadius: '0.75rem',
                padding: '1rem',
                cursor: 'pointer',
                display: 'flex',
                gap: '0.75rem',
                alignItems: 'center',
              }}
            >
              {report.poster_url && (
                <img
                  src={report.poster_url}
                  alt={report.title}
                  style={{ width: 48, height: 72, borderRadius: 8, objectFit: 'cover' }}
                />
              )}
              <div>
                <p style={{ fontWeight: 700, marginBottom: '0.25rem' }}>{report.title}</p>
                <p style={{ fontSize: '0.875rem', color: 'var(--color-text-muted)' }}>
                  {[report.certification, report.year].filter(Boolean).join(' · ')}
                </p>
              </div>
            </div>
          ))}
        </div>

        {!showingResults && (
          <p style={{ textAlign: 'center', color: 'var(--color-text-muted)', marginTop: '1.5rem', fontSize: '0.875rem' }}>
            {reports.length}+ titles reviewed
          </p>
        )}
      </div>
    </div>
  )
}
