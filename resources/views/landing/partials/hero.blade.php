<section class="hero" aria-labelledby="hero-title">
  @include('landing.partials.poster-field')
  <div class="hero__shade" aria-hidden="true"></div>
  <div class="container">
    <div class="hero__content">
      <p class="eyebrow">{{ $copy['hero']['eyebrow'] }}</p>
      <h1 id="hero-title">{{ $copy['hero']['titleLead'] }} <span>{{ $copy['hero']['titleAccent'] }}</span></h1>
      <p class="hero__copy">{{ $copy['hero']['body'] }}</p>
      <div class="actions">
        <a class="button button--primary" href="{{ $registerUrl }}">{{ $copy['hero']['primaryCta'] }}</a>
        <a class="button button--secondary" href="#demo">{{ $copy['hero']['secondaryCta'] }}</a>
      </div>
      <p class="hero__note">{{ $copy['hero']['note'] }}</p>
    </div>
  </div>
</section>
