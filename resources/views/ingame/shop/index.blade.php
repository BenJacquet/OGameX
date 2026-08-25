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

    <style type="text/css">
        .js_selectable { cursor: pointer; box-sizing: border-box; border: 2px solid transparent; }
        .js_selectable.selected { border-color: #ffa500; box-shadow: 0 0 6px #ffa500; }
        .shop_action_bar { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 10px; }
        #itemBox { display: flex; align-items: flex-start; gap: 15px; }
        #itemBox .aside { flex: 0 0 220px; display: flex; flex-direction: column; }
        #itemBox .shop_content { flex: 1; min-width: 0; }
        .item_detail { margin-top: 10px; padding: 8px; border: 1px solid rgba(255, 255, 255, 0.2); min-height: 80px; }
        .item_detail h3 { margin: 0 0 6px; }
        .item_grid { display: grid; grid-template-columns: repeat(3, max-content); gap: 10px; width: fit-content; margin: 0 auto; }
        .item_grid .js_grid_filler { visibility: hidden; pointer-events: none; }
        .pagination { display: flex; align-items: center; gap: 12px; margin-top: 10px; width: fit-content; margin-left: auto; margin-right: auto; }
        .pagination .page_nav_btn { min-width: 32px; padding: 2px 10px; }
        .pagination .page_nav_btn:disabled { opacity: 0.35; cursor: not-allowed; }
        .pagination .page_indicator { min-width: 50px; text-align: center; }
        .allclasses_bundle { display: flex; align-items: center; gap: 12px; padding: 10px; margin-bottom: 15px; border: 1px solid rgba(255, 255, 255, 0.2); }
        .allclasses_bundle .sprite.resource.small.darkmatter { flex: 0 0 auto; }
        .allclasses_bundle .allclasses_bundle_info { flex: 1; min-width: 0; }
        .allclasses_bundle .allclasses_bundle_info h3 { margin: 0 0 4px; }
        .allclasses_bundle .allclasses_bundle_action { flex: 0 0 auto; }
    </style>

    <div id="inhalt">
        <div id="planet">
            <div id="header_text">
                <h2>
                    {{ __('t_ingame.shop.page_title') }}            </h2>
            </div>

            <div id="detail" class="detail_screen small">
                <div id="techDetailLoading"></div>
            </div>

        </div>
        <div class="c-left"></div>
        <div class="c-right"></div>

        <div id="buttonz">
            <div class="header">
                <h2>{{ __('t_ingame.shop.page_title') }}</h2>
            </div>
            <div class="content">
                <div class="allclasses_bundle">
                    <div class="sprite resource small darkmatter"></div>
                    <div class="allclasses_bundle_info">
                        <h3>All-in-One Character Class Bundle</h3>
                        <p>Purchase Collector, General and Discoverer class bonuses all at once. Replaces any currently selected single class (no refund).</p>
                    </div>
                    <div class="allclasses_bundle_action">
                        @if($hasAllClasses)
                            <button type="button" class="btn btn_confirm" onclick="deactivateAllClasses()">Deactivate</button>
                        @elseif($darkMatter >= $allClassesCost)
                            <button type="button" class="btn btn_confirm" onclick="purchaseAllClasses({{ $allClassesCost }})">Buy for {{ number_format($allClassesCost, 0, ',', '.') }} DM</button>
                        @else
                            <button type="button" class="btn" disabled title="Not enough Dark Matter">Buy for {{ number_format($allClassesCost, 0, ',', '.') }} DM</button>
                        @endif
                    </div>
                </div>

                <button type="button" id="js_tab_shop" class="to_shop active tooltip js_hideTipOnMobile" title="{{ __('t_ingame.shop.tooltip_shop') }}" onclick="showShopTab('shop')">
                    <span class="to_shop_icon">{{ __('t_ingame.shop.btn_shop') }}</span>
                </button>
                <button type="button" id="js_tab_inventory" class="to_inventory tooltip js_hideTipOnMobile" title="{{ __('t_ingame.shop.tooltip_inventory') }}" onclick="showShopTab('inventory')">
                    <span class="to_inventory_icon">{{ __('t_ingame.shop.btn_inventory') }}</span>
                </button>

                <div id="itemBox" class="border5px">
                    <div class="aside">
                        <div id="js_itemDetail" class="item_detail">
                            <p id="js_itemDetailHint" class="stimulus">{{ __('t_ingame.shop.select_item_hint') }}</p>
                            <div id="js_itemDetailContent" style="display: none;">
                                <h3 id="js_itemDetailName"></h3>
                                <div id="js_itemDetailDesc"></div>
                            </div>
                        </div>

                        <div id="js_shop_action_bar" class="shop_action_bar">
                            <button type="button" id="js_shop_buy_btn" class="btn btn_confirm" disabled onclick="buyItem()">{{ __('t_ingame.shop.btn_buy') }}</button>
                        </div>
                        <div id="js_inventory_action_bar" class="shop_action_bar" style="display: none;">
                            <button type="button" id="js_inventory_use_btn" class="btn btn_confirm" disabled onclick="activateItem()">{{ __('t_ingame.shop.loca_activate') }}</button>
                        </div>
                    </div>

                    <div class="shop_content">
                        <div id="js_shopPane">
                            <div id="js_shopGrid" class="item_grid">
                                @foreach ($items as $type)
                                    @php
                                        $itemName = __('t_resources.' . $type->getNameKey() . '.title') . ' ' . __('t_ingame.shop.tier_' . $type->getTierKey());
                                        if ($type->getEffectCategory() === 'planet_size') {
                                            $itemDesc = __('t_resources.' . $type->getNameKey() . '.description', ['fields' => $type->getFieldBonus()]);
                                        } else {
                                            $durationLabel = $type->getDurationSeconds() >= 3600
                                                ? (int)($type->getDurationSeconds() / 3600) . 'h'
                                                : (int)($type->getDurationSeconds() / 60) . 'm';
                                            $itemDesc = __('t_resources.' . $type->getNameKey() . '.description', ['duration' => $durationLabel]);
                                        }
                                        $owned = $inventory[$type->value] ?? 0;
                                    @endphp
                                    <div class="item_img r_{{ $type->getRarity() }} js_selectable" data-item-type="{{ $type->value }}" data-name="{{ $itemName }}" data-description="{{ $itemDesc }}" title="{{ $itemName }}|{{ $itemDesc }}" style="background-image: url(/img/icons/{{ $type->getImageHash() }}-100x.png);">
                                        <div class="item_img_box">
                                            <span class="ecke"><span class="level price">{{ number_format($type->getPrice(), 0, '', '.') }} {{ __('t_ingame.shop.dm_abbreviation') }}</span></span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="pagination">
                                <button type="button" id="js_shop_prev_btn" class="btn page_nav_btn" onclick="changePage('shop', -1)">&lt;</button>
                                <span id="js_shop_page_indicator" class="page_indicator"></span>
                                <button type="button" id="js_shop_next_btn" class="btn page_nav_btn" onclick="changePage('shop', 1)">&gt;</button>
                            </div>
                        </div>

                        <div id="js_inventoryPane" style="display: none;">
                            <div id="js_inventoryGrid" class="item_grid">
                                @foreach ($items as $type)
                                    @php
                                        $itemName = __('t_resources.' . $type->getNameKey() . '.title') . ' ' . __('t_ingame.shop.tier_' . $type->getTierKey());
                                        if ($type->getEffectCategory() === 'planet_size') {
                                            $itemDesc = __('t_resources.' . $type->getNameKey() . '.description', ['fields' => $type->getFieldBonus()]);
                                        } else {
                                            $durationLabel = $type->getDurationSeconds() >= 3600
                                                ? (int)($type->getDurationSeconds() / 3600) . 'h'
                                                : (int)($type->getDurationSeconds() / 60) . 'm';
                                            $itemDesc = __('t_resources.' . $type->getNameKey() . '.description', ['duration' => $durationLabel]);
                                        }
                                        $owned = $inventory[$type->value] ?? 0;
                                    @endphp
                                    <div class="item_img r_{{ $type->getRarity() }} js_selectable" data-item-type="{{ $type->value }}" data-owned="{{ $owned }}" data-name="{{ $itemName }}" data-description="{{ $itemDesc }}" title="{{ $itemName }}" style="background-image: url(/img/icons/{{ $type->getImageHash() }}-100x.png); opacity: {{ $owned > 0 ? '1' : '0.4' }};">
                                        <div class="item_img_box">
                                            <span class="ecke"><span class="level price">{{ $owned }}</span></span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="pagination">
                                <button type="button" id="js_inventory_prev_btn" class="btn page_nav_btn" onclick="changePage('inventory', -1)">&lt;</button>
                                <span id="js_inventory_page_indicator" class="page_indicator"></span>
                                <button type="button" id="js_inventory_next_btn" class="btn page_nav_btn" onclick="changePage('inventory', 1)">&gt;</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="footer"></div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        const itemsPerRow = 3;
        const rowsPerPage = 3;
        const itemsPerPage = itemsPerRow * rowsPerPage;
        let selectedShopItem = null;
        let selectedInventoryItem = null;
        let shopPage = 1;
        let inventoryPage = 1;

        document.querySelectorAll('#js_shopGrid .js_selectable').forEach(function (el) {
            el.addEventListener('click', function () {
                selectItem('shop', this);
            });
        });

        document.querySelectorAll('#js_inventoryGrid .js_selectable').forEach(function (el) {
            el.addEventListener('click', function () {
                selectItem('inventory', this);
            });
        });

        showPage('shop', 1);
        showPage('inventory', 1);

        function showPage(context, page) {
            const gridId = (context === 'shop') ? 'js_shopGrid' : 'js_inventoryGrid';
            const grid = document.getElementById(gridId);
            const items = Array.from(grid.querySelectorAll('.js_selectable'));
            const totalPages = Math.max(1, Math.ceil(items.length / itemsPerPage));
            page = Math.min(Math.max(1, page), totalPages);

            let itemsOnPage = 0;
            items.forEach(function (el, index) {
                const itemPage = Math.floor(index / itemsPerPage) + 1;
                const onCurrentPage = (itemPage === page);
                el.style.display = onCurrentPage ? '' : 'none';
                if (onCurrentPage) {
                    itemsOnPage++;
                }
            });

            // Pad the grid out to a full page with invisible filler tiles, so it always
            // reserves space for `rowsPerPage` rows and the pagination bar below it never
            // shifts position depending on how many real items are on the current page.
            grid.querySelectorAll('.js_grid_filler').forEach(function (filler) {
                filler.remove();
            });
            for (let i = itemsOnPage; i < itemsPerPage; i++) {
                const filler = document.createElement('div');
                filler.className = 'item_img js_grid_filler';
                grid.appendChild(filler);
            }

            if (context === 'shop') {
                shopPage = page;
            } else {
                inventoryPage = page;
            }

            document.getElementById('js_' + context + '_prev_btn').disabled = (page <= 1);
            document.getElementById('js_' + context + '_next_btn').disabled = (page >= totalPages);
            document.getElementById('js_' + context + '_page_indicator').textContent = page + ' / ' + totalPages;
        }

        function changePage(context, delta) {
            const currentPage = (context === 'shop') ? shopPage : inventoryPage;
            showPage(context, currentPage + delta);
        }

        function selectItem(context, el) {
            const gridId = (context === 'shop') ? 'js_shopGrid' : 'js_inventoryGrid';
            document.querySelectorAll('#' + gridId + ' .js_selectable').forEach(function (item) {
                item.classList.remove('selected');
            });
            el.classList.add('selected');

            if (context === 'shop') {
                selectedShopItem = parseInt(el.dataset.itemType, 10);
                document.getElementById('js_shop_buy_btn').disabled = false;
            } else {
                selectedInventoryItem = parseInt(el.dataset.itemType, 10);
                const owned = parseInt(el.dataset.owned, 10) || 0;
                document.getElementById('js_inventory_use_btn').disabled = (owned === 0);
            }

            showItemDetail(el.dataset.name, el.dataset.description);
        }

        function showItemDetail(name, description) {
            document.getElementById('js_itemDetailHint').style.display = 'none';
            document.getElementById('js_itemDetailContent').style.display = 'block';
            document.getElementById('js_itemDetailName').textContent = name;
            document.getElementById('js_itemDetailDesc').innerHTML = description;
        }

        function showShopTab(tab) {
            document.getElementById('js_shopPane').style.display = (tab === 'shop') ? 'block' : 'none';
            document.getElementById('js_inventoryPane').style.display = (tab === 'inventory') ? 'block' : 'none';
            document.getElementById('js_shop_action_bar').style.display = (tab === 'shop') ? 'flex' : 'none';
            document.getElementById('js_inventory_action_bar').style.display = (tab === 'inventory') ? 'flex' : 'none';
            document.getElementById('js_tab_shop').classList.toggle('active', tab === 'shop');
            document.getElementById('js_tab_inventory').classList.toggle('active', tab === 'inventory');

            const gridId = (tab === 'shop') ? 'js_shopGrid' : 'js_inventoryGrid';
            const selectedType = (tab === 'shop') ? selectedShopItem : selectedInventoryItem;
            const selectedEl = (selectedType !== null)
                ? document.querySelector('#' + gridId + ' .js_selectable[data-item-type="' + selectedType + '"]')
                : null;

            if (selectedEl) {
                showItemDetail(selectedEl.dataset.name, selectedEl.dataset.description);
            } else {
                document.getElementById('js_itemDetailHint').style.display = 'block';
                document.getElementById('js_itemDetailContent').style.display = 'none';
            }
        }

        function buyItem() {
            if (selectedShopItem === null) {
                return;
            }
            submitShopAction('{{ route('shop.buy') }}', selectedShopItem);
        }

        function activateItem() {
            if (selectedInventoryItem === null) {
                return;
            }
            submitShopAction('{{ route('shop.activate') }}', selectedInventoryItem);
        }

        function purchaseAllClasses(price) {
            errorBoxDecision(
                'Purchase All Classes',
                'Do you want to activate all three character classes simultaneously for ' + price.toLocaleString() + ' Dark Matter? This is a one-time purchase and will replace your current single class selection (if any).',
                'Confirm',
                'Cancel',
                function() {
                    submitAllClassesAction('{{ route('characterclass.purchaseall') }}');
                }
            );
        }

        function deactivateAllClasses() {
            errorBoxDecision(
                'Deactivate All Classes',
                'Do you really want to deactivate the All-in-One bundle? You will lose all three classes\' bonuses. This purchase is not refunded.',
                'Deactivate',
                'Cancel',
                function() {
                    submitAllClassesAction('{{ route('characterclass.deactivateall') }}');
                }
            );
        }

        function submitAllClassesAction(url) {
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
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
                        'Not enough Dark Matter available! Do you want to buy some now?',
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

        function submitShopAction(url, itemType) {
            fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    itemType: itemType
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
    </script>

@endsection
