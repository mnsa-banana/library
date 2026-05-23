import { useEffect, useState } from 'react'
import { Link, useParams, useNavigate } from 'react-router-dom'
import { fetchReport, ReportDetail, Rating } from '../shared/api'

function formatKey(key: string): string {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

const SEVERITY_ORDER = ['Extreme', 'Overwhelming', 'Explicit', 'Strong', 'Heavy', 'Moderate', 'Mild', 'Light', 'Low', 'None', 'Exemplary']

function severityColor(level: string): string {
  const idx = SEVERITY_ORDER.indexOf(level)
  if (idx <= 2) return '#e53e3e'
  if (idx <= 4) return '#dd6b20'
  if (idx <= 6) return '#d69e2e'
  return '#38a169'
}

export function Report() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [report, setReport] = useState<ReportDetail | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetchReport(Number(id))
      .then(setReport)
      .catch((e) => setError(e.message))
      .finally(() => setLoading(false))
  }, [id])

  if (loading) {
    return <div className="page-center" style={{ background: 'var(--color-bg)' }}><p>Loading...</p></div>
  }

  if (error || !report) {
    return (
      <div className="page-center" style={{ background: 'var(--color-bg)', color: 'var(--color-text)' }}>
        <p>{error || 'Report not found'}</p>
        <button className="btn-link" onClick={() => navigate('/')}>Go back</button>
      </div>
    )
  }

  // Group ratings by section_key -> group_key
  const sections = new Map<string, Map<string, Rating[]>>()
  for (const r of report.ratings) {
    if (!sections.has(r.section_key)) sections.set(r.section_key, new Map())
    const groups = sections.get(r.section_key)!
    if (!groups.has(r.group_key)) groups.set(r.group_key, [])
    groups.get(r.group_key)!.push(r)
  }

  const groupNotes = new Map<string, string>()
  for (const cg of report.category_groups) {
    groupNotes.set(`${cg.section_key}:${cg.group_key}`, cg.notes || '')
  }

  return (
    <div style={{ background: 'var(--color-bg)', minHeight: '100vh', color: 'var(--color-text)' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '1rem 1.5rem' }}>
        <button className="btn-link" onClick={() => navigate('/')}>← Back</button>
        <Link to="/account" className="btn-link">Account</Link>
      </header>

      <div style={{ maxWidth: 640, margin: '0 auto', padding: '0 1.5rem 3rem' }}>
        {/* Hero */}
        <div style={{ display: 'flex', gap: '1rem', marginBottom: '1.5rem' }}>
          {report.poster_url && (
            <img src={report.poster_url} alt={report.title} style={{ width: 100, height: 148, borderRadius: 12, objectFit: 'cover' }} />
          )}
          <div>
            <h1 style={{ fontSize: '1.5rem', fontWeight: 800, marginBottom: '0.25rem', fontFamily: 'var(--font-heading)' }}>{report.title}</h1>
            <p style={{ color: 'var(--color-text-muted)', marginBottom: '0.5rem' }}>
              {[report.certification, report.year, report.runtime ? `${report.runtime}m` : null].filter(Boolean).join(' · ')}
            </p>
            {report.directors && <p style={{ fontSize: '0.875rem', color: 'var(--color-text-muted)' }}><strong>Directors:</strong> {report.directors}</p>}
            {report.top_cast && <p style={{ fontSize: '0.875rem', color: 'var(--color-text-muted)' }}><strong>Cast:</strong> {report.top_cast}</p>}
          </div>
        </div>

        {/* Text sections */}
        {report.overview && (
          <section style={{ background: 'var(--color-surface)', borderRadius: 12, padding: '1.25rem', marginBottom: '0.75rem' }}>
            <h2 style={{ fontSize: '1.125rem', fontWeight: 800, marginBottom: '0.5rem', fontFamily: 'var(--font-heading)' }}>Overview</h2>
            <p style={{ lineHeight: 1.7, color: 'var(--color-text-muted)' }}>{report.overview}</p>
          </section>
        )}

        {report.heads_up && (
          <section style={{ background: 'var(--color-surface)', borderRadius: 12, padding: '1.25rem', marginBottom: '0.75rem', borderLeft: '4px solid var(--color-accent-secondary)' }}>
            <h2 style={{ fontSize: '1.125rem', fontWeight: 800, marginBottom: '0.5rem', fontFamily: 'var(--font-heading)' }}>Heads Up</h2>
            <p style={{ lineHeight: 1.7, color: 'var(--color-text-muted)' }}>{report.heads_up}</p>
          </section>
        )}

        {report.summary && (
          <section style={{ background: 'var(--color-surface)', borderRadius: 12, padding: '1.25rem', marginBottom: '0.75rem' }}>
            <h2 style={{ fontSize: '1.125rem', fontWeight: 800, marginBottom: '0.5rem', fontFamily: 'var(--font-heading)' }}>Summary</h2>
            <p style={{ lineHeight: 1.7, color: 'var(--color-text-muted)' }}>{report.summary}</p>
          </section>
        )}

        {/* Content Ratings */}
        {[...sections.entries()].map(([sectionKey, groups]) => (
          <section key={sectionKey} style={{ background: 'var(--color-surface)', borderRadius: 12, marginBottom: '0.75rem', overflow: 'hidden' }}>
            <div style={{ padding: '1rem 1.25rem', borderBottom: '1px solid var(--color-border)' }}>
              <h2 style={{ fontSize: '1.125rem', fontWeight: 800, fontFamily: 'var(--font-heading)' }}>{formatKey(sectionKey)}</h2>
            </div>
            {[...groups.entries()].map(([groupKey, ratings]) => {
              const notes = groupNotes.get(`${sectionKey}:${groupKey}`)
              return (
                <div key={groupKey} style={{ padding: '0.75rem 1.25rem', borderBottom: '1px solid var(--color-border)' }}>
                  <h3 style={{ fontSize: '0.875rem', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--color-accent)', marginBottom: '0.5rem' }}>
                    {formatKey(groupKey)}
                  </h3>
                  {notes && <p style={{ fontStyle: 'italic', color: 'var(--color-text-muted)', marginBottom: '0.5rem', fontSize: '0.875rem' }}>{notes}</p>}
                  {ratings.map((r) => (
                    <div key={r.subcategory_key} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', padding: '0.375rem 0', gap: '1rem' }}>
                      <span style={{ fontSize: '0.875rem' }}>{formatKey(r.subcategory_key)}</span>
                      <span style={{ fontSize: '0.75rem', fontWeight: 700, color: r.level ? severityColor(r.level) : 'var(--color-text-muted)', whiteSpace: 'nowrap' }}>
                        {r.level || (r.present === false ? 'Not present' : '—')}
                      </span>
                    </div>
                  ))}
                </div>
              )
            })}
          </section>
        ))}
      </div>
    </div>
  )
}
