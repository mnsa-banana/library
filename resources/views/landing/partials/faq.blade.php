<section class="section" id="faq">
  <div class="container">
    <div class="section__header">
      <div>
        <p class="label">{{ $copy['faq']['label'] }}</p>
        <h2>{{ $copy['faq']['heading'] }}</h2>
      </div>
    </div>
    <div class="faq">
      @foreach ($copy['faq']['items'] as $item)
      <details {{ $loop->first ? 'open' : '' }}>
        <summary>{{ $item['q'] }}</summary>
        <p>{{ $item['a'] }}</p>
      </details>
      @endforeach
    </div>
  </div>
</section>
