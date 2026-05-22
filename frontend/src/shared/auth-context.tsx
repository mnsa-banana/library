import { createContext, useContext, useEffect, useState, ReactNode } from 'react'

interface User {
  id: number
  name: string
  email: string
}

interface AuthState {
  token: string | null
  user: User | null
  isLoading: boolean
  subscribed: boolean | null
  login: (token: string, user: User, subscribed?: boolean) => void
  logout: () => void
  setSubscribed: (value: boolean) => void
}

const TOKEN_KEY = 'auth_token'
const USER_KEY = 'auth_user'
const SUBSCRIBED_KEY = 'auth_subscribed'

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [token, setToken] = useState<string | null>(null)
  const [user, setUser] = useState<User | null>(null)
  const [subscribed, setSubscribedState] = useState<boolean | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    const storedToken = localStorage.getItem(TOKEN_KEY)
    const storedUser = localStorage.getItem(USER_KEY)
    const storedSubscribed = localStorage.getItem(SUBSCRIBED_KEY)
    if (storedToken && storedUser) {
      setToken(storedToken)
      setUser(JSON.parse(storedUser))
      setSubscribedState(storedSubscribed === 'true')
    }
    setIsLoading(false)
  }, [])

  function login(newToken: string, newUser: User, newSubscribed?: boolean) {
    setToken(newToken)
    setUser(newUser)
    setSubscribedState(newSubscribed ?? null)
    localStorage.setItem(TOKEN_KEY, newToken)
    localStorage.setItem(USER_KEY, JSON.stringify(newUser))
    if (newSubscribed !== undefined) {
      localStorage.setItem(SUBSCRIBED_KEY, String(newSubscribed))
    }
  }

  function logout() {
    setToken(null)
    setUser(null)
    setSubscribedState(null)
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
    localStorage.removeItem(SUBSCRIBED_KEY)
  }

  function setSubscribed(value: boolean) {
    setSubscribedState(value)
    localStorage.setItem(SUBSCRIBED_KEY, String(value))
  }

  return (
    <AuthContext.Provider value={{ token, user, isLoading, subscribed, login, logout, setSubscribed }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
