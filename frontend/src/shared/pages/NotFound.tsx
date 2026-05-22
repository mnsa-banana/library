import { Link } from 'react-router-dom'

export function NotFound() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', minHeight: '100vh' }}>
      <h1 style={{ fontSize: '2rem', marginBottom: '1rem' }}>404</h1>
      <p style={{ marginBottom: '1rem' }}>Page not found.</p>
      <Link to="/">Go home</Link>
    </div>
  )
}
