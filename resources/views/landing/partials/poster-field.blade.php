@php $restricted = [2, 7, 11, 18, 23, 31, 38, 45, 49, 57, 62, 70, 76, 83, 91, 96]; @endphp
<div class="poster-field" aria-hidden="true">
  <div class="poster-grid">
    @for ($i = 1; $i <= 112; $i++)
    @php $col = ($i - 1) % 16; $row = intdiv($i - 1, 16); @endphp
    <div class="poster{{ in_array($i, $restricted, true) ? ' is-restricted' : '' }}" style="background-position: -{{ $col * 74 }}px -{{ $row * 108 }}px;"><span class="poster__slash"></span></div>
    @endfor
  </div>
</div>
