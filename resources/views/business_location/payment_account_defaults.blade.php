@extends('layouts.app')
@section('title', __('lang_v1.payment_account_defaults'))

@section('content')
    <section class="content-header">
        <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">
            @lang('lang_v1.payment_account_defaults')
            <small class="tw-text-sm md:tw-text-base tw-text-gray-700 tw-font-semibold">
                @lang('lang_v1.payment_account_defaults_help')
            </small>
        </h1>
    </section>

    <section class="content">
        @if ($errors->any())
            <div class="alert alert-danger">{{ $errors->first() }}</div>
        @endif

        @if (empty($accounts) || count($accounts) <= 1)
            <div class="alert alert-warning">
                @lang('lang_v1.no_payment_accounts_available')
                <a href="{{ action([\App\Http\Controllers\AccountController::class, 'index']) }}">
                    @lang('lang_v1.payment_accounts')
                </a>
            </div>
        @else
            {!! Form::open(['route' => 'business-location.payment-account-defaults', 'method' => 'post']) !!}
                <div class="box box-solid">
                    <div class="box-body table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>@lang('business.business_location')</th>
                                    @foreach ($payment_types as $method => $label)
                                        <th>{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($locations as $location)
                                    <tr>
                                        <th>{{ $location->name }}</th>
                                        @foreach ($payment_types as $method => $label)
                                            @php
                                                $current_account = $location_defaults[$location->id][$method]['account'] ?? null;
                                                $suggested_account = $suggestions[$location->id][$method] ?? null;
                                                $selected_account = $current_account ?: $suggested_account;
                                            @endphp
                                            <td>
                                                {!! Form::select(
                                                    "default_payment_accounts[{$location->id}][{$method}]",
                                                    $accounts,
                                                    $selected_account,
                                                    ['class' => 'form-control select2', 'style' => 'width:100%']
                                                ) !!}
                                                @if (empty($current_account) && !empty($suggested_account))
                                                    <small class="text-muted">
                                                        @lang('lang_v1.suggested_account'): {{ $accounts[$suggested_account] ?? '' }}
                                                    </small>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ count($payment_types) + 1 }}" class="text-center">
                                            @lang('lang_v1.no_business_locations')
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if (count($locations))
                        <div class="box-footer">
                            <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white">
                                @lang('lang_v1.apply_payment_account_defaults')
                            </button>
                            <a href="{{ action([\App\Http\Controllers\BusinessLocationController::class, 'index']) }}"
                                class="tw-dw-btn tw-dw-btn-neutral tw-text-white">@lang('messages.cancel')</a>
                        </div>
                    @endif
                </div>
            {!! Form::close() !!}
        @endif
    </section>
@endsection
