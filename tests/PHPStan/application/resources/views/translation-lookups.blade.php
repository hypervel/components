@lang('lookups.missing', [], 'fr')
@lang('lookups.present', [], 'fr')
@lang('lookups.present', [], 'fr', false)
@choice('lookups.missing', 1, [], 'fr')
{{ __('lookups.missing', [], null) }}
{{ __('lookups.missing', [], $locale) }}
{{ Lang::get('lookups.present', [], 'fr', $fallback) }}
{{ trans("lookups.{$item}") }}
{{ trans("lookups.$item") }}
{{ __( 'lookups.missing' ) }}
{{ __(replace: [], key: 'lookups.missing') }}
@lang ('lookups.missing')
{{-- {{ __('lookups.missing') }}
{{ __('lookups.missing') }} --}}
@verbatim {{ __('lookups.missing') }} @endverbatim
@{{ __('lookups.missing') }}
@@lang('lookups.missing')
test@lang('lookups.missing')
{{ $translator->trans('lookups.missing') }}
{{ $t('lookups.missing') }}
<!-- @lang('lookups.missing') -->
<p>__('lookups.missing')</p>
<script>const label = trans('lookups.missing');</script>
{{ "trans('lookups.missing')" }}
<div :title="__('lookups.missing')"></div>
<x-input-label :value="__('lookups.missing')" />
@section('title', __('lookups.missing'))
@foreach (__('lookups.missing') as $line) @endforeach
{!! __('lookups.missing') !!}
{{{ __('lookups.missing') }}}
@php
    $label = __('lookups.missing');
@endphp
@section('label', '@lang("lookups.missing")')
@unknownDirective(__('lookups.missing'))
@for ($index = 0; $index < count(__('lookups.missing')); $index++) @endfor
@php $translate = trans(...); @endphp
<?php __('lookups.missing'); ?>
{{-- <?php __('lookups.missing'); ?> --}}
@push('scripts', __('lookups.missing'))
{{-- @php __('lookups.missing'); @endphp --}}
@verbatim <?php __('lookups.missing'); ?> {{ __('lookups.missing') }} @endverbatim
{{-- @verbatim <?php __('lookups.missing'); ?> @endverbatim --}}
<x-input-label bind:value="__('lookups.missing')" />
<x-input-label x-bind:value="__('lookups.missing')" />
{{ \Hypervel\Support\Facades\Lang::get('lookups.missing') }}
{{ Hypervel\Support\Facades\Lang::choice('lookups.missing', 1) }}
