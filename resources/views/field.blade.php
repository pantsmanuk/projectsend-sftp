<div class="field">
    <label for="{{ $name }}">{{ $label }}@if($required ?? false) <span class="required">{{ __('Required') }}</span>@endif</label>
    <div class="input-row">
        @if(($type ?? '') === 'select')
        <select id="{{ $name }}" name="{{ $name }}" @if($required ?? false) required @endif aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" aria-describedby="{{ $name }}-help {{ $name }}-error" @if($errors->has($name)) autofocus @endif>
            <option value="">{{ __('Choose a destination') }}</option>
            @foreach($options as $option)<option value="{{ $option->id }}" @selected((string) old($name) === (string) $option->id)>{{ $option->name }}</option>@endforeach
        </select>
        @else
        <input id="{{ $name }}" name="{{ $name }}" type="{{ $type ?? 'text' }}" @if(($type ?? '') !== 'file' && ($type ?? '') !== 'password') value="{{ old($name, $destination->getAttribute($name)) }}" @endif
            @if($required ?? false) required @endif @if(isset($max)) maxlength="{{ $max }}" @endif
            @if(($type ?? '') === 'number') min="1" max="65535" inputmode="numeric" @endif
            @if(($type ?? '') === 'password') autocomplete="new-password" @elseif(($type ?? '') !== 'file') autocomplete="off" @endif
            aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}" aria-describedby="{{ $name }}-help {{ $name }}-error"
            @if($errors->has($name) && $errors->keys()[0] === $name) autofocus @endif>
        @endif
        @if(($type ?? '') === 'password')<button class="button secondary" type="button" data-reveal="{{ $name }}" aria-controls="{{ $name }}" aria-pressed="false" data-show="{{ __('Show passphrase') }}" data-hide="{{ __('Hide passphrase') }}">{{ __('Show passphrase') }}</button>@endif
    </div>
    <p id="{{ $name }}-help" class="help">{{ $help }}</p><p id="{{ $name }}-error" class="field-error">{{ $errors->first($name) }}</p>
</div>
