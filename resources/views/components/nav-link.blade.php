@props(['active'])

<a {{ $attributes->merge(['class' => 'zeno-nav-pill']) }} @if($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
