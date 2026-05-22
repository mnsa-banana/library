<!doctype html>
<html lang="en" data-brand="{{ $brandKey }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $copy['meta']['title'] }}</title>
  <meta name="description" content="{{ $copy['meta']['description'] }}">
  <link rel="canonical" href="{{ rtrim(config('brands.brands.'.$brandKey.'.allowed_origin'), '/') }}/">
  <meta property="og:type" content="website">
  <meta property="og:title" content="{{ $copy['meta']['title'] }}">
  <meta property="og:description" content="{{ $copy['meta']['description'] }}">
  <meta property="og:url" content="{{ url()->current() }}">
  @if(!empty($copy['meta']['ogImage']))
  <meta property="og:image" content="{{ $copy['meta']['ogImage'] }}">
  @endif
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="{{ $copy['meta']['title'] }}">
  <meta name="twitter:description" content="{{ $copy['meta']['description'] }}">
  @if(!empty($copy['meta']['ogImage']))
  <meta name="twitter:image" content="{{ $copy['meta']['ogImage'] }}">
  @endif
  <link rel="stylesheet" href="/css/mnsa-landing.css">
</head>
<body>
  @include('landing.partials.nav')
  <main id="top">
    @include('landing.partials.hero')
    @include('landing.partials.problem')
    @include('landing.partials.demo')
    @include('landing.partials.workflow')
    @include('landing.partials.features')
    @include('landing.partials.faq')
    @include('landing.partials.cta')
  </main>
  @include('landing.partials.footer')
  <script>
    (function () {
      var n = document.querySelector('.nav');
      if (!n) return;
      var f = function () { n.classList.toggle('is-scrolled', window.scrollY > 12); };
      f();
      window.addEventListener('scroll', f, { passive: true });
    })();
  </script>
</body>
</html>
