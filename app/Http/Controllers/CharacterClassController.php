<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use OGame\Enums\CharacterClass;
use OGame\Services\CharacterClassService;
use OGame\Services\DarkMatterService;

class CharacterClassController extends OGameController
{
    /**
     * CharacterClassController constructor.
     *
     * @param CharacterClassService $characterClassService
     * @param DarkMatterService $darkMatterService
     */
    public function __construct(
        private CharacterClassService $characterClassService,
        private DarkMatterService $darkMatterService
    ) {
    }

    /**
     * Shows the character class selection page
     *
     * @return View
     */
    public function index(): View
    {
        $this->setBodyId('characterclassselectionpage');

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        $currentClass = $this->characterClassService->getCharacterClass($user);
        $changeCost = $this->characterClassService->getChangeCost($user);

        // Get all character classes
        $classes = [
            CharacterClass::COLLECTOR,
            CharacterClass::GENERAL,
            CharacterClass::DISCOVERER,
        ];

        return view('ingame.characterclass.index', [
            'currentClass' => $currentClass,
            'changeCost' => $changeCost,
            'classes' => $classes,
            'darkMatter' => $this->darkMatterService->getBalance($user),
            'isFreeSelection' => !$user->character_class_free_used,
        ]);
    }

    /**
     * Select a character class
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function selectClass(Request $request): JsonResponse
    {
        $request->validate([
            'characterClassId' => 'required|integer|in:1,2,3',
        ]);

        $user = Auth::user();
        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], 401);
        }

        $classId = (int)$request->input('characterClassId');
        $newClass = CharacterClass::from($classId);

        try {
            // Check if user can afford the change
            if (!$this->characterClassService->canChangeClass($user, $newClass)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough Dark Matter to change class',
                    'lackingDM' => true,
                ], 400);
            }

            // Select the class
            $this->characterClassService->selectClass($user, $newClass);

            // Refresh user to ensure changes are reflected
            $user->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Character class selected successfully',
                'newClass' => $newClass->getName(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Deselect current character class
     *
     * @return JsonResponse
     */
    public function deselectClass(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], 401);
        }

        try {
            $this->characterClassService->deselectClass($user);

            // Refresh user to ensure changes are reflected
            $user->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'Character class deactivated successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Purchase the all-classes bundle
     *
     * @return JsonResponse
     */
    public function purchaseAllClasses(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], 401);
        }

        try {
            if ($this->characterClassService->hasAllClasses($user)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'All classes bundle is already active',
                ], 400);
            }

            if (!$this->darkMatterService->canAfford($user, $this->characterClassService->getAllClassesCost($user))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough Dark Matter to purchase all classes',
                    'lackingDM' => true,
                ], 400);
            }

            $this->characterClassService->purchaseAllClasses($user);

            // Refresh user to ensure changes are reflected
            $user->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'All character classes purchased successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Deactivate the all-classes bundle
     *
     * @return JsonResponse
     */
    public function deactivateAllClasses(): JsonResponse
    {
        $user = Auth::user();
        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], 401);
        }

        try {
            $this->characterClassService->deactivateAllClasses($user);

            // Refresh user to ensure changes are reflected
            $user->refresh();

            return response()->json([
                'status' => 'success',
                'message' => 'All character classes deactivated successfully',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}
