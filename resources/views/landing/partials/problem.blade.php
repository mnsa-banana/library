<section class="section" id="problem">
  <div class="container">
    <div class="section__header">
      <div>
        <p class="label">{{ $copy['problem']['label'] }}</p>
        <h2>{{ $copy['problem']['heading'] }}</h2>
      </div>
      <p class="section__lede">{{ $copy['problem']['lede'] }}</p>
    </div>
    <div class="argument-grid">
      @foreach ($copy['problem']['arguments'] as $arg)
      <article class="argument">
        <span class="argument__kicker">{{ $arg['kicker'] }}</span>
        <h3>{{ $arg['title'] }}</h3>
        <p>{{ $arg['body'] }}</p>
      </article>
      @endforeach
    </div>
  </div>
</section>
