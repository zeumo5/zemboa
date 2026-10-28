@if(session('success'))<div class="pc-feedback pc-success" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="pc-feedback pc-error" role="alert"><strong>{{ __('buyer.errors') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
