@php
  $shopUrl = route('marketplace.shops.show', ['shopSlug' => $store->slug]);
  $identityName = preg_replace('/^DEMO\s*-\s*/u', '', $store->name);
  $initials = collect(preg_split('/\s+/u', trim($identityName)))->reject(fn ($word) => in_array(mb_strtolower($word), ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'of', 'the'], true))->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
  $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $store->public_primary_color ?? '') ? $store->public_primary_color : '#64748B';
@endphp
<article class="zm-store-card zm-merchant-card" style="--store-accent: {{ $accent }}">
  <a class="zm-store-cover" href="{{ $shopUrl }}" tabindex="-1" aria-hidden="true">
    @if($store->public_banner_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($store->public_banner_path) }}" alt="" loading="lazy" width="640" height="260">@else<span class="zm-store-cover-pattern" aria-hidden="true"></span>@endif
  </a>
  <div class="zm-store-info">
    <div class="zm-store-identity">
      <span class="zm-store-avatar" aria-hidden="true">@if($store->public_logo_path)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($store->public_logo_path) }}" alt="" width="64" height="64" loading="lazy">@else{{ $initials }}@endif</span>
      <span class="zm-store-label">{{ __('marketplace.independent_store') }}</span>
      <h3><a href="{{ $shopUrl }}">{{ $store->name }}</a></h3>
      @if($store->description || $store->slogan)<p>{{ \Illuminate\Support\Str::limit($store->description ?: $store->slogan, 125) }}</p>@endif
      @if($store->address)<p class="zm-store-location"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg>{{ $store->address }}</p>@endif
    </div>
    <a class="zm-store-visit" href="{{ $shopUrl }}">{{ __('marketplace.store_front') }}<span aria-hidden="true">↗</span></a>
  </div>
</article>
