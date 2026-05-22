<footer class="footer">
  <div class="container footer__inner">
    <div>{{ $copy['footer']['taglineLead'] }} <strong style="color:var(--color-accent)">{{ $copy['footer']['taglineAccent'] }}</strong></div>
    <div class="footer__links">
      @foreach ($copy['footer']['links'] as $link)
      <a href="{{ $link['href'] }}">{{ $link['label'] }}</a>
      @endforeach
    </div>
    <div class="disclaimer">{{ $copy['footer']['disclaimer'] }}</div>
  </div>
</footer>
