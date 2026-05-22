<section class="section" id="how">
  <div class="container">
    <div class="section__header">
      <div>
        <p class="label">{{ $copy['workflow']['label'] }}</p>
        <h2>{{ $copy['workflow']['heading'] }}</h2>
      </div>
      <p class="section__lede">{{ $copy['workflow']['lede'] }}</p>
    </div>
    <div class="workflow">
      @foreach ($copy['workflow']['steps'] as $step)
      <article class="step">
        <span class="step__number">{{ $loop->iteration }}</span>
        <h3>{{ $step['title'] }}</h3>
        <p>{{ $step['body'] }}</p>
      </article>
      @endforeach
    </div>
  </div>
</section>
