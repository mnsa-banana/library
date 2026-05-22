<section class="section" id="features">
  <div class="container">
    <div class="section__header">
      <div>
        <p class="label">{{ $copy['features']['label'] }}</p>
        <h2>{{ $copy['features']['heading'] }}</h2>
      </div>
      <p class="section__lede">{{ $copy['features']['lede'] }}</p>
    </div>
    <div class="feature-strip">
      @foreach ($copy['features']['items'] as $item)
      <article class="feature">
        <span class="feature__tag">{{ $item['tag'] }}</span>
        <h3>{{ $item['title'] }}</h3>
        <p>{{ $item['body'] }}</p>
      </article>
      @endforeach
    </div>
  </div>
</section>
