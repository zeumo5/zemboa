<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
@switch($icon)
@case('cart')<path d="M3 3h2l3 12h11l2-8H6M9 20h.01M18 20h.01"/>@break
@case('store')<path d="M3 9l2-6h14l2 6M3 9v3a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2V9M5 15v6h14v-6M10 21v-5h4v5"/>@break
@case('truck')<path d="M1 4h13v13H1zM14 9h5l4 5v3h-9M8 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm13 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z"/>@break
@case('shield')<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>@break
@case('arrow')<path d="M20 12H4m6-6-6 6 6 6"/>@break
@case('lock')<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 15v2"/>@break
@endswitch
</svg>
