<nav class="nav" aria-label="Primary">
  <div class="container nav__inner">
    <a class="brand" href="#top" aria-label="{{ $copy['brandNameLead'] }} {{ $copy['brandNameAccent'] }}">
      <span class="brand__mark">N</span>
      <span class="brand__name">{{ $copy['brandNameLead'] }} <span>{{ $copy['brandNameAccent'] }}</span></span>
    </a>
    <div style="display:flex;align-items:center;gap:18px">
      <a class="nav__link" href="{{ $loginUrl }}">{{ $copy['nav']['signIn'] }}</a>
      <a class="nav__cta" href="{{ $registerUrl }}">{{ $copy['nav']['cta'] }}</a>
    </div>
  </div>
</nav>
