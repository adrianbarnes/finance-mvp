@php($status = $this->audioStatuses[$step->id] ?? ['state' => 'silent', 'language' => null])

@switch($status['state'])
    @case('own')
        <flux:badge size="sm" color="green" icon="speaker-wave">{{ $this->language->name }}</flux:badge>
        @break
    @case('fallback')
        <flux:badge size="sm" color="amber" icon="speaker-wave">{{ __('plays :language', ['language' => $status['language']->name]) }}</flux:badge>
        @break
    @case('missing')
        <flux:badge size="sm" color="red" icon="speaker-x-mark">{{ __('no audio') }}</flux:badge>
        @break
@endswitch
