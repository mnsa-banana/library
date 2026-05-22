@php $win = $copy['demo']['window']; $panel = $copy['demo']['panel']; $vanish = $copy['demo']['vanishNote']; @endphp
<section class="section" id="demo">
  <div class="container">
    <div class="section__header">
      <div>
        <p class="label">{{ $copy['demo']['label'] }}</p>
        <h2>{{ $copy['demo']['heading'] }}</h2>
      </div>
      <p class="section__lede">{{ $copy['demo']['lede'] }}</p>
    </div>

    <div class="demo">
      <div>
        <div class="restriction-window" aria-label="Netflix restrictions, illustrative">
          <div class="restriction-window__chrome" aria-hidden="true">
            <span class="dot"></span><span class="dot"></span><span class="dot"></span>
          </div>
          <div class="restriction-window__body">
            <h3>{{ $win['title'] }}</h3>
            <p class="restriction-window__hint">{{ $win['hint'] }}</p>
            <div class="restriction-search">{{ $win['searchPlaceholder'] }}</div>
            <ul class="restriction-list">
              @foreach ($win['items'] as $item)
              <li><span>{{ $item }}</span><button type="button" aria-label="Remove (illustrative)" tabindex="-1">&times;</button></li>
              @endforeach
            </ul>
            <div class="restriction-actions">
              <button type="button" class="save" tabindex="-1">{{ $win['save'] }}</button>
              <button type="button" tabindex="-1">{{ $win['cancel'] }}</button>
            </div>
          </div>
        </div>

        <div class="vanish-note">
          <div class="vanish-note__icon" aria-hidden="true">&minus;</div>
          <div>
            <strong>{{ $vanish['title'] }}</strong>
            <p>{{ $vanish['body'] }}</p>
          </div>
        </div>
      </div>

      <aside class="extension-panel" aria-label="Browser extension panel, illustrative">
        <div class="extension-panel__chrome" aria-hidden="true">
          <span class="extension-panel__mark"></span>
          <span>{{ $panel['title'] }}</span>
          <span class="extension-panel__close">&times;</span>
        </div>
        <div class="extension-card">
          <h3>{{ $panel['title'] }}</h3>
          <hr class="extension-rule">
          <div class="extension-meta">
            <span>{!! nl2br(e($panel['metaLine'])) !!}</span>
            <a href="#install">{{ $panel['metaLink'] }}</a>
          </div>
          <ul class="candidate-list">
            @foreach ($panel['candidates'] as $c)
            <li class="candidate">
              <span class="candidate__poster" aria-hidden="true"></span>
              <span>
                <span class="candidate__title">{{ $c['title'] }}</span>
                <span class="candidate__details">{{ $c['details'] }} <span class="candidate__flag">{{ $c['flag'] }}</span></span>
              </span>
              <span class="candidate__chevron" aria-hidden="true">&rsaquo;</span>
            </li>
            @endforeach
          </ul>
          <a class="extension-button" href="{{ $registerUrl }}">{{ $panel['installCta'] }} ({{ count($panel['candidates']) }})</a>
        </div>
      </aside>
    </div>
  </div>
</section>
