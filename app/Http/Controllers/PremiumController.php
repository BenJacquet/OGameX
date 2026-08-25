<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use OGame\Enums\OfficerType;
use OGame\Services\DarkMatterService;
use OGame\Services\OfficerService;

class PremiumController extends OGameController
{
    /**
     * PremiumController constructor.
     *
     * @param OfficerService $officerService
     * @param DarkMatterService $darkMatterService
     */
    public function __construct(
        private OfficerService $officerService,
        private DarkMatterService $darkMatterService
    ) {
    }

    /**
     * Shows the premium/officers index page
     *
     * @return View
     */
    public function index(): View
    {
        $this->setBodyId('premium');

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        $darkMatter = $this->darkMatterService->getBalance($user);
        $weeklyCost = $this->officerService->getWeeklyCost();

        $officers = [];
        foreach (OfficerType::cases() as $type) {
            $officers[$type->getMachineName()] = [
                'type' => $type,
                'active' => $this->officerService->isActive($user, $type),
                'expiresAt' => $this->officerService->getExpiresAt($user, $type),
            ];
        }

        return view('ingame.premium.index', [
            'darkMatter' => $darkMatter,
            'officers' => $officers,
            'weeklyCost' => $weeklyCost,
            'hasAllOfficers' => $this->officerService->hasAllOfficers($user),
        ]);
    }

    /**
     * Hire (or extend) a single officer, or all officers if officerTypeId is omitted/null.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function hire(Request $request): JsonResponse
    {
        $request->validate([
            'officerTypeId' => 'nullable|integer|in:2,3,4,5,6',
        ]);

        $user = Auth::user();
        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], 401);
        }

        $officerTypeId = $request->input('officerTypeId');

        try {
            if ($officerTypeId === null) {
                $this->officerService->hireAll($user);
                $message = 'All officers hired successfully';
            } else {
                $type = OfficerType::from((int)$officerTypeId);
                $this->officerService->hire($user, $type);
                $message = $type->getName() . ' hired successfully';
            }

            $user->refresh();

            return response()->json([
                'status' => 'success',
                'message' => $message,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'lackingDM' => str_contains($e->getMessage(), 'Insufficient Dark Matter'),
            ], 400);
        }
    }
}
