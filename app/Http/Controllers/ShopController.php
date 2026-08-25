<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use OGame\Enums\ItemType;
use OGame\Services\CharacterClassService;
use OGame\Services\DarkMatterService;
use OGame\Services\ItemService;
use OGame\Services\PlayerService;

class ShopController extends OGameController
{
    /**
     * ShopController constructor.
     *
     * @param ItemService $itemService
     * @param DarkMatterService $darkMatterService
     * @param CharacterClassService $characterClassService
     */
    public function __construct(
        private ItemService $itemService,
        private DarkMatterService $darkMatterService,
        private CharacterClassService $characterClassService
    ) {
    }

    /**
     * Shows the shop index page
     *
     * @param PlayerService $player
     * @return View
     */
    public function index(PlayerService $player): View
    {
        $this->setBodyId('shop');

        $user = $player->getUser();

        $items = [];
        foreach (ItemType::cases() as $type) {
            $items[$type->value] = $type;
        }

        return view('ingame.shop.index', [
            'items' => $items,
            'inventory' => $this->itemService->getInventory($user),
            'darkMatter' => $this->darkMatterService->getBalance($user),
            'hasAllClasses' => $this->characterClassService->hasAllClasses($user),
            'allClassesCost' => $this->characterClassService->getAllClassesCost($user),
        ]);
    }

    /**
     * Buy one unit of an item.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function buy(Request $request, PlayerService $player): JsonResponse
    {
        $request->validate([
            'itemType' => ['required', 'integer', Rule::in(array_map(fn (ItemType $type) => $type->value, ItemType::cases()))],
        ]);

        $user = $player->getUser();

        try {
            $type = ItemType::from((int)$request->input('itemType'));
            $this->itemService->buy($user, $type);

            return response()->json([
                'status' => 'success',
                'message' => 'Item bought successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'lackingDM' => str_contains($e->getMessage(), 'Insufficient Dark Matter'),
            ], 400);
        }
    }

    /**
     * Activate one owned unit of an item on the player's current planet.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return JsonResponse
     */
    public function activate(Request $request, PlayerService $player): JsonResponse
    {
        $request->validate([
            'itemType' => ['required', 'integer', Rule::in(array_map(fn (ItemType $type) => $type->value, ItemType::cases()))],
        ]);

        $user = $player->getUser();

        try {
            $type = ItemType::from((int)$request->input('itemType'));
            $this->itemService->activate($user, $player->planets->current(), $type);

            return response()->json([
                'status' => 'success',
                'message' => 'Item activated successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
