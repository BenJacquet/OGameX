@php
    use OGame\Enums\AllianceClass;
@endphp

{{-- Alliance Classes Tab --}}
<div id="allianceclassselection">
    <div class="content">
        <h2>{{ __('t_ingame.alliance.select_class_title') }}</h2>
        <p>{{ __('t_ingame.alliance.select_class_note') }}</p>

        @if(!$canManageClasses)
            <p class="box_highlight textCenter">{{ __('t_ingame.alliance.no_manage_classes_perm') }}</p>
        @endif

        <div class="allianceclass boxes">
            @foreach(AllianceClass::cases() as $class)
                @php
                    $isActive = $allianceClass === $class;
                @endphp
                <div class="allianceclass box {{ $isActive ? 'selected' : '' }}"
                     data-alliance-class-id="{{ $class->value }}"
                     data-alliance-class-name="{{ __('t_ingame.alliance.class_' . strtolower($class->name) . 's') }}"
                     data-alliance-class-price="{{ $changeCost }}">
                    <div class="buttons">
                        @if($isActive)
                            @if($canManageClasses)
                                <a class="deactivate-it deactivate" href="javascript:void(0);" onclick="deactivateAllianceClass()">
                                    <span>{{ __('t_ingame.alliance.loca_deactivate') }}</span>
                                </a>
                            @endif
                        @elseif($canManageClasses)
                            @if($darkMatter >= $changeCost)
                                <a class="build-it" href="javascript:void(0);" onclick="selectAllianceClass({{ $class->value }}, '{{ __('t_ingame.alliance.class_' . strtolower($class->name) . 's') }}')">
                                    <span>{{ __('t_ingame.alliance.buy_for') }}<br>{{ number_format($changeCost, 0, ',', '.') }} DM</span>
                                </a>
                            @else
                                <a class="build-it_disabled tooltip js_hideTipOnMobile nodarkmatter" rel="{{ route('premium.index') }}" data-tooltip-title="{{ __('t_ingame.alliance.no_dark_matter') }}">
                                    <span>{{ __('t_ingame.alliance.buy_for') }}<br>{{ number_format($changeCost, 0, ',', '.') }} DM</span>
                                </a>
                            @endif
                        @endif
                    </div>
                    <div class="sprite allianceclass large {{ $class->getMachineName() }}"></div>
                    <div class="boxClassBoni">
                        <h2>{{ __('t_ingame.alliance.class_' . strtolower($class->name) . 's') }}</h2>
                        <ul>
                            @if($class === AllianceClass::WARRIOR)
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.warrior_bonus_1') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.warrior_bonus_2') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.warrior_bonus_3') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.warrior_bonus_4') }}</li>
                            @elseif($class === AllianceClass::TRADER)
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.trader_bonus_1') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.trader_bonus_2') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.trader_bonus_3') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.trader_bonus_4') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.trader_bonus_5') }}</li>
                            @else
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.researcher_bonus_1') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.researcher_bonus_2') }}</li>
                                <li class="allianceclass bonus">{{ __('t_ingame.alliance.researcher_bonus_3') }}</li>
                            @endif
                        </ul>
                    </div>
                </div>
            @endforeach
        </div>

        <br>
    </div>
</div>

<script type="text/javascript">
    function selectAllianceClass(classId, className) {
        let price = {{ $changeCost }};
        let message = @json(__('t_ingame.alliance.loca_activate_dm'))
            .replace('#allianceClassName#', className)
            .replace('#darkmatter#', price.toLocaleString());

        errorBoxDecision(
            @json(__('t_ingame.alliance.select_class_title')),
            message,
            @json(__('t_ingame.shared.yes')),
            @json(__('t_ingame.shared.no')),
            function() {
                fetch('{{ route('alliance.action') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        action: 'select_alliance_class',
                        alliance_class_id: classId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        fadeBox(data.message, false);
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        fadeBox(data.message, true);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    fadeBox('An error occurred. Please try again.', true);
                });
            }
        );
    }

    function deactivateAllianceClass() {
        let price = {{ $changeCost }};
        let className = @json($allianceClass?->getName());
        let message = @json(__('t_ingame.alliance.loca_deactivate_note'))
            .replace('#allianceClassName#', className)
            .replace('#darkmatter#', price.toLocaleString());

        errorBoxDecision(
            @json(__('t_ingame.alliance.loca_deactivate')),
            message,
            @json(__('t_ingame.alliance.loca_deactivate')),
            @json(__('t_ingame.shared.no')),
            function() {
                fetch('{{ route('alliance.action') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({
                        action: 'deactivate_alliance_class'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        fadeBox(data.message, false);
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        fadeBox(data.message, true);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    fadeBox('An error occurred. Please try again.', true);
                });
            }
        );
    }
</script>
