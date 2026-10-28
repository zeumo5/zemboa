@extends('professional.layout')
@section('title', __('customization.title'))
@section('content')
<section class="store-editor" data-store-editor>
    <header class="store-editor-header"><div><p class="store-editor-eyebrow">{{ $store->name }}</p><h1>{{ __('customization.title') }}</h1><p>{{ __('customization.intro') }}</p></div><div class="store-editor-actions">
        <a class="team-button" href="{{ route('marketplace.shops.show', ['shopSlug'=>$store->slug]) }}" target="_blank" rel="noopener">{{ __('customization.view') }} ↗</a>
        @if($editable)<a class="team-button" href="{{ route('professional.customization.index',['section'=>'preview']) }}">{{ __('customization.preview') }}</a>
        <form method="post" action="{{ route('professional.customization.publish') }}" data-store-confirm="{{ __('customization.publish_confirm') }}">@csrf<input type="hidden" name="revision" value="{{ $record?->revision ?? 0 }}"><button class="team-button team-primary" @disabled(!$record?->draft)>{{ __('customization.publish') }} ↗</button></form>@endif
    </div></header>
    @if(session('success'))<p class="store-editor-notice" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<div class="store-editor-errors" role="alert" tabindex="-1"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @unless($editable)<p class="store-editor-notice">{{ __('customization.readonly') }}</p>@endunless
    <div class="store-editor-shell"><nav class="store-editor-nav" aria-label="{{ __('customization.title') }}">
        @foreach(['identity','appearance','information','hours','commerce','documents'] as $area)<a href="{{ route('professional.customization.index',['section'=>$area]) }}" @if($section===$area) aria-current="page" @endif><span aria-hidden="true">{{ ['identity'=>'◈','appearance'=>'◉','information'=>'ⓘ','hours'=>'◷','commerce'=>'◇','documents'=>'▤'][$area] }}</span><span><strong>{{ __('customization.'.$area) }}</strong><small>{{ __('customization.'.$area.'_hint') }}</small></span></a>@endforeach
    </nav><div class="store-editor-content">
    <div class="store-editor-status"><span class="store-editor-badge">{{ $editable && $record?->draft ? __('customization.draft') : __('customization.live') }}</span>
    @if($editable && $record?->draft)<span>{{ collect(array_keys($draft))->filter(fn($key)=>$key!=='media')->map(fn($key)=>__('customization.'.$key))->implode(' · ') }}</span><form method="post" action="{{ route('professional.customization.discard') }}" data-store-confirm="{{ __('customization.discard_confirm') }}">@csrf<input type="hidden" name="revision" value="{{ $record->revision }}"><button class="store-editor-discard">{{ __('customization.discard') }}</button></form>@endif</div>
    @if($section==='preview')
        @if($editable)<section class="store-editor-panel"><h2>{{ __('customization.preview') }}</h2><p>{{ __('customization.preview_info') }}</p><p>{{ __('customization.unsaved') }}</p><div class="store-preview-toolbar"><button type="button" class="team-button" data-preview-width="100%" aria-pressed="true">{{ __('customization.desktop') }}</button><button type="button" class="team-button" data-preview-width="390px" aria-pressed="false">{{ __('customization.mobile') }}</button><select aria-label="{{ __('customization.preview') }}" data-preview-page>@foreach(['home','catalog','categories','about','services'] as $page)<option value="{{ route('professional.customization.preview',['page'=>$page]) }}">{{ __('marketplace.storefront.'.(['home'=>'menu','catalog'=>'discover','categories'=>'categories','about'=>'about','services'=>'services'][$page])) }}</option>@endforeach</select></div><div class="store-preview-canvas"><iframe src="{{ route('professional.customization.preview') }}" title="{{ __('customization.preview_label') }}" data-store-preview></iframe></div></section>@endif
    @elseif($section==='documents')
        <section class="store-editor-panel"><h2>{{ __('customization.documents') }}</h2><p>{{ __('customization.documents_info') }}</p>@if($actor->hasPermission('store.document_settings.view'))<a class="team-button" href="{{ route('pos.interface.index') }}#pos-document-preferences">{{ __('customization.open_documents') }} →</a>@endif</section>
    @else
        <form method="post" action="{{ route('professional.customization.save') }}" enctype="multipart/form-data" class="store-editor-panel" data-store-draft-form>@csrf
            <input type="hidden" name="revision" value="{{ $record?->revision ?? 0 }}"><input type="hidden" name="section" value="{{ $section }}">
            <h2>{{ __('customization.'.$section) }}</h2><p>{{ __('customization.'.$section.'_hint') }}</p>
            <fieldset @disabled(!$editable)><legend class="zm-sr-only">{{ __('customization.'.$section) }}</legend>
            @if($section==='identity')
                <div class="store-field-grid">@foreach(['name','slogan'] as $field)<label for="brand-{{ $field }}">{{ __('customization.'.$field) }} @if($field==='name')<span aria-hidden="true">*</span>@endif<input id="brand-{{ $field }}" name="{{ $field }}" value="{{ old($field,$values[$field]??'') }}" maxlength="{{ $field==='name'?150:255 }}" @required($field==='name')></label>@endforeach</div>
                <label for="brand-description">{{ __('customization.description') }}<textarea id="brand-description" name="description" rows="5" maxlength="5000">{{ old('description',$values['description']??'') }}</textarea></label>
                <div class="store-media-grid">@foreach(['logo','banner'] as $slot)
                    @php($mediaUrl = array_key_exists($slot,$draft['media']??[]) ? ($draft['media'][$slot] ? route('professional.customization.media',$slot) : null) : \App\Support\StoreBranding::url($settings,$slot))
                    <div class="store-media-field"><label for="brand-{{ $slot }}">{{ __('customization.'.$slot) }}</label><div class="store-media-frame store-media-{{ $slot }}"><img src="{{ $mediaUrl ?? '' }}" alt="{{ __('customization.'.$slot) }}" data-brand-image="{{ $slot }}" @if(!$mediaUrl) hidden @endif><span data-brand-empty="{{ $slot }}" @if($mediaUrl) hidden @endif>{{ __('customization.no_media') }}</span></div>
                    <input id="brand-{{ $slot }}" type="file" name="{{ $slot }}" accept="image/jpeg,image/png,image/webp" data-brand-upload="{{ $slot }}" aria-describedby="brand-{{ $slot }}-help"><small id="brand-{{ $slot }}-help">{{ __('customization.'.$slot.'_help') }}</small><label class="store-checkbox"><input type="checkbox" name="remove_{{ $slot }}" value="1">{{ __('customization.remove_'.$slot) }}</label></div>
                @endforeach</div>
            @elseif($section==='appearance')
                <label for="brand-color">{{ __('customization.color') }}<input id="brand-color" type="color" name="primary_color" value="{{ old('primary_color',$values['primary_color']??'#0F6B57') }}"></label><p>{{ __('customization.contrast') }}</p><div class="store-brand-sample" style="--brand-sample:{{ \App\Support\StoreBranding::accent($values['primary_color']??null) }}"><strong>{{ $values['name'] }}</strong><span>{{ __('customization.view') }} →</span></div><p>{{ __('customization.unsaved') }}</p>
            @elseif($section==='information')
                <p>{{ __('customization.public_info') }}</p><div class="store-field-grid">@foreach(['email','phone','whatsapp'] as $field)<label for="brand-{{ $field }}">{{ __('customization.'.$field) }}<input id="brand-{{ $field }}" name="{{ $field }}" type="{{ $field==='email'?'email':'tel' }}" value="{{ old($field,$values[$field]??'') }}" maxlength="{{ $field==='email'?255:25 }}"></label>@endforeach</div><label for="brand-address">{{ __('customization.address') }}<textarea id="brand-address" name="address" maxlength="1000" rows="3">{{ old('address',$values['address']??'') }}</textarea></label><label for="brand-slug">{{ __('customization.slug') }}<input id="brand-slug" readonly value="{{ route('marketplace.shops.show',['shopSlug'=>$store->slug]) }}"></label>
            @elseif($section==='hours')
                <p>{{ __('customization.hours_info') }}</p><label for="brand-locale">{{ __('customization.locale') }}<select id="brand-locale" name="default_locale">@foreach(['fr'=>'Français','en'=>'English'] as $locale=>$name)<option value="{{ $locale }}" @selected(old('default_locale',$values['default_locale']??'fr')===$locale)>{{ $name }}</option>@endforeach</select></label>
                <div class="store-hours">@foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $day)<div><strong>{{ __('marketplace.storefront.days.'.$day) }}</strong>@foreach(['open','close'] as $edge)<label class="zm-sr-only" for="brand-{{ $day }}-{{ $edge }}">{{ __('marketplace.storefront.days.'.$day) }} — {{ __('customization.'.$edge) }}</label><input id="brand-{{ $day }}-{{ $edge }}" type="time" name="opening_hours[{{ $day }}][{{ $edge }}]" value="{{ old('opening_hours.'.$day.'.'.$edge,$values['opening_hours'][$day][$edge]??'') }}">@endforeach</div>@endforeach</div>
            @elseif($section==='commerce')
                <p class="store-editor-notice">{{ __('customization.commerce_info') }}</p>@if(!$settings || !$settings->hasCommercialConfiguration())<p>{{ __('customization.unconfigured') }}</p>@endif
                @foreach(['delivery_enabled','pickup_enabled','allow_partial_payments','returns_enabled'] as $field)<label class="store-checkbox"><input type="hidden" name="{{ $field }}" value="0"><input type="checkbox" name="{{ $field }}" value="1" @checked(old($field,$values[$field]??false))>{{ __('customization.'.$field) }}</label>@endforeach
                <div class="store-field-grid">@foreach(['reservation_duration_minutes'=>[1,43200,30],'minimum_deposit_percent'=>[0,100,0],'return_window_days'=>[0,365,7],'low_stock_threshold'=>[0,1000000,5]] as $field=>$limits)<label for="brand-{{ $field }}">{{ __('customization.'.$field) }}<input id="brand-{{ $field }}" name="{{ $field }}" type="number" min="{{ $limits[0] }}" max="{{ $limits[1] }}" step="1" required value="{{ old($field,$values[$field]??$limits[2]) }}"></label>@endforeach</div><p>{{ __('customization.threshold_info') }}</p>
            @endif
            </fieldset>
            @if($editable)<div class="store-editor-save"><p>{{ __('customization.unsaved') }}</p><button class="team-button team-primary" type="submit">{{ __('customization.save') }} →</button></div>@endif
        </form>
    @endif
    </div></div><p class="store-file-feedback" data-brand-feedback role="status"></p>
</section>
@endsection
