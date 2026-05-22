import { useEffect, useState } from 'react'

// Three-way state for the MNSA Chrome extension:
//   'unknown'       – probing on mount, before timeout
//   'not-installed' – probe timed out with no response
//   'installed'     – extension is installed, side panel closed
//   'open'          – extension installed, side panel currently open
export type ExtensionState = 'unknown' | 'not-installed' | 'installed' | 'open'

const EXT_SOURCE = 'imbuo-mnsa-ext'
const PAGE_SOURCE = 'imbuo-mnsa-page'
const PROBE_TIMEOUT_MS = 1500

interface ExtMessage {
  source?: string
  type?: string
  installed?: boolean
  panelOpen?: boolean
}

function deriveState(installed: boolean, panelOpen: boolean): ExtensionState {
  if (!installed) return 'not-installed'
  return panelOpen ? 'open' : 'installed'
}

// Synchronous initial check via the data-attributes the content script stamps
// on documentElement. Lets us skip the 'unknown' flicker if the content
// script already ran (e.g. on SPA navigation back to /extension).
function readStampedState(): ExtensionState {
  const el = typeof document !== 'undefined' ? document.documentElement : null
  if (!el) return 'unknown'
  const installed = el.dataset.mnsaExtensionInstalled === '1'
  if (!installed) return 'unknown'
  return el.dataset.mnsaExtensionPanelOpen === '1' ? 'open' : 'installed'
}

export function useExtensionState(): ExtensionState {
  const [state, setState] = useState<ExtensionState>(() => readStampedState())

  useEffect(() => {
    let timedOut = false

    function onMessage(event: MessageEvent) {
      if (event.source !== window) return
      const data = event.data as ExtMessage | null
      if (!data || data.source !== EXT_SOURCE || data.type !== 'state') return
      setState(deriveState(!!data.installed, !!data.panelOpen))
    }

    window.addEventListener('message', onMessage)
    window.postMessage({ source: PAGE_SOURCE, type: 'query' }, '*')

    const timer = window.setTimeout(() => {
      timedOut = true
      setState((prev) => (prev === 'unknown' ? 'not-installed' : prev))
    }, PROBE_TIMEOUT_MS)

    return () => {
      window.removeEventListener('message', onMessage)
      window.clearTimeout(timer)
      void timedOut
    }
  }, [])

  return state
}
