@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div id="eventboxContent" style="display: none">
        <img height="16" width="16" src="/img/icons/3f9884806436537bdec305aa26fc60.gif">
    </div>

    <div id="inhalt" class="officers">
        <div id="planet">
            <div id="header_text">
                <h2>{{ __('t_ingame.premium.recruit_officers') }}</h2>
            </div>

            <div id="detail" class="detail_screen small">
                <div id="techDetailLoading"></div>
            </div>

        </div>	<div class="c-left"></div>
        <div class="c-right"></div>
        <div id="buttonz">
            <div class="header">
                <h2>{{ __('t_ingame.premium.your_officers') }}</h2>
            </div>
            <div class="content">
                <p class="stimulus">
                    {{ __('t_ingame.premium.intro_text') }}</p>

                <ul id="building">
                    <li class="on button" id="button1">
                        <div class="premium1">
                            <div class="officers100  darkMatter">
                                <a tabindex="1" href="javascript:void(0);" title="{{ __('t_ingame.premium.info_dark_matter') }}" class="detail_button tooltip js_hideTipOnMobile slideIn" ref="1">
                        <span class="ecke">
                            <span class="level">
                                {{ number_format($darkMatter, 0, ',', '.') }}
                            </span>
                        </span>
                                </a>
                            </div>
                        </div>			</li>
                    @foreach ($officers as $officer)
                    <li class="button {{ $officer['active'] ? 'on' : '' }}" id="button{{ $officer['type']->value }}">
                        <div class="premium">
                            <div class="officers100  {{ $officer['type']->getMachineName() }}">
                                <a tabindex="{{ $officer['type']->value }}" href="javascript:void(0);" onclick="hireOfficer({{ $officer['type']->value }})" title="{{ __('t_ingame.premium.info_' . $officer['type']->getMachineName()) }}" ref="{{ $officer['type']->value }}" class="detail_button tooltip js_hideTipOnMobile slideIn">
                        <span class="ecke">
                            <span class="level">
                                <img src="/img/icons/aa2ad16d1e00956f7dc8af8be3ca52.gif" width="12" height="11">
                            </span>
                        </span>
                                </a>
                            </div>
                            <div class="remaining tooltip" title="{{ __('t_ingame.premium.price_per_week', ['price' => number_format($weeklyCost, 0, ',', '.')]) }}">
                                <span class="remDate">
                                    @if ($officer['active'])
                                        {{ __('t_ingame.premium.active_until', ['date' => $officer['expiresAt']->format('Y-m-d H:i')]) }}
                                    @else
                                        {{ __('t_ingame.premium.price_per_week', ['price' => number_format($weeklyCost, 0, ',', '.')]) }}
                                    @endif
                                </span>
                            </div>
                        </div>
                    </li>
                    @endforeach
                    <li class="button" id="button12">
                        <div class="premium">
                            <div class="officers100  allOfficers">
                                <a tabindex="12" href="javascript:void(0);" onclick="hireAllOfficers()" title="{{ __('t_ingame.premium.info_commanding_staff') }}" ref="12" class="detail_button tooltip js_hideTipOnMobile slideIn">
                        <span class="ecke">
                            <span class="level">
                                <img src="/img/icons/aa2ad16d1e00956f7dc8af8be3ca52.gif" width="12" height="11">
                            </span>
                        </span>
                                </a>
                            </div>
                            <div class="remaining tooltip " title="">
                                <span class="remDate">{{ __('t_ingame.premium.remaining_officers', ['current' => collect($officers)->where('active', true)->count(), 'max' => 5]) }}</span>
                            </div>
                        </div>
                    </li>

                    <li class="allOfficers off">
                        <span title="{{ __('t_ingame.premium.benefit_fleet_slots_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_fleet_slots') }}</span><span title="{{ __('t_ingame.premium.benefit_energy_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_energy') }}</span><span title="{{ __('t_ingame.premium.benefit_mines_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_mines') }}</span><span title="{{ __('t_ingame.premium.benefit_espionage_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_espionage') }}</span>            </li>
                </ul>
                <br class="clearfloat">
                <div class="footer"></div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        function hireOfficer(officerTypeId) {
            errorBoxDecision(
                '{{ __('t_ingame.premium.recruit_officers') }}',
                '{{ __('t_ingame.premium.confirm_hire', ['price' => number_format($weeklyCost, 0, ',', '.')]) }}',
                'Confirm',
                'Cancel',
                function() {
                    submitHireRequest(officerTypeId);
                }
            );
        }

        function hireAllOfficers() {
            errorBoxDecision(
                '{{ __('t_ingame.premium.recruit_officers') }}',
                '{{ __('t_ingame.premium.confirm_hire_all', ['price' => number_format($weeklyCost * 5, 0, ',', '.')]) }}',
                'Confirm',
                'Cancel',
                function() {
                    submitHireRequest(null);
                }
            );
        }

        function submitHireRequest(officerTypeId) {
            fetch('{{ route('premium.hire') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    officerTypeId: officerTypeId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    fadeBox(data.message, false);
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else if (data.lackingDM) {
                    errorBoxDecision(
                        'Not enough Dark Matter',
                        '{{ __('t_ingame.premium.insufficient_dm') }}',
                        'Buy Dark Matter',
                        'Cancel',
                        function() {
                            window.location.href = '/premium';
                        }
                    );
                } else {
                    fadeBox(data.message, true);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                fadeBox('An error occurred. Please try again.', true);
            });
        }
    </script>

@endsection
