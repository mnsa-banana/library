import { useState } from 'react'
import { useSearchParams, Link } from 'react-router-dom'
import { apiConfirmEmailChange, fetchMe } from '../api'
import { useAuth } from '../auth-context'

type Status = 'idle' | 'pending' | 'success' | 'error'

export function EmailConfirm() {
  const [params] = useSearchParams()
  const { token: authToken, login } = useAuth()
  const idParam = params.get('id')
  const linkToken = params.get('token') ?? ''
  const id = idParam ? Number(idParam) : NaN

  const [status, setStatus] = useState<Status>('idle')
  const [message, setMessage] = useState<string>('')

  const linkValid = Number.isFinite(id) && id > 0 && linkToken !== ''

  async function handleConfirm() {
    setStatus('pending')
    try {
      await apiConfirmEmailChange({ id, token: linkToken })
      setStatus('success')
      if (authToken) {
        try {
          const me = await fetchMe()
          login(authToken, { id: me.id, name: me.name, email: me.email }, me.subscribed)
        } catch {}
      }
    } catch (err: any) {
      setStatus('error')
      setMessage(err.message || 'Could not confirm.')
    }
  }

  return (
    <div className="page-center">
      <div className="auth-card">
        {!linkValid && (
          <>
            <p className="auth-error">Bad confirmation link.</p>
            <p className="auth-switch"><Link to="/account">Back to your account</Link></p>
          </>
        )}

        {linkValid && status === 'idle' && (
          <>
            <h1 className="auth-title">Confirm email change</h1>
            <p>Click to confirm the email change you requested.</p>
            <button className="btn-primary" onClick={handleConfirm}>Confirm change</button>
          </>
        )}

        {linkValid && status === 'pending' && <p>Confirming…</p>}

        {linkValid && status === 'success' && (
          <>
            <h1 className="auth-title">Email confirmed</h1>
            <p>Your account email has been updated.</p>
            <p className="auth-switch"><Link to="/account">Back to your account</Link></p>
          </>
        )}

        {linkValid && status === 'error' && (
          <>
            <p className="auth-error">{message}</p>
            <p className="auth-switch"><Link to="/account">Back to your account</Link></p>
          </>
        )}
      </div>
    </div>
  )
}
