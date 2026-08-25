<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use OGame\Exceptions\AccountImportException;
use OGame\Services\AccountExportService;
use OGame\Services\AccountImportService;
use OGame\Services\PlayerService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OptionsController extends OGameController
{
    /**
     * Shows the overview index page
     *
     * @param PlayerService $player
     * @return View
     */
    public function index(PlayerService $player): View
    {
        $this->setBodyId('preferences');

        $canUpdateUsername = true;
        if ($lastChange = $player->getLastUsernameChange()) {
            $canUpdateUsername = $lastChange->addWeek()->isPast();
        }

        return view('ingame.options.index')->with([
            'username' => $player->getUsername(),
            'current_email' => $player->getEmail(),
            'canUpdateUsername' => $canUpdateUsername,
            'player' => $player,
            'espionage_probes_amount' => $player->getEspionageProbesAmount(),
        ]);
    }

    /**
     * Process change username submit request.
     *
     * @param Request $request
     * @param PlayerService $player
     *
     * @return array<string,string>
     * @throws Exception
     */
    public function processChangeUsername(Request $request, PlayerService $player): array|null
    {
        $name = $request->input('new_username_username');
        if (!empty($name)) {
            // Check if username validates.
            $validationResult = $player->isUsernameValid($name);
            if (!$validationResult['valid']) {
                return array('error' => $validationResult['error']);
            }

            // Update username
            $player->setUsername($name);
            $player->save();

            return array('success' => __('t_ingame.options.msg_settings_saved'));
        }

        return array();
    }

    /**
     * Process vacation mode activation/deactivation request.
     *
     * @param Request $request
     * @param PlayerService $player
     *
     * @return array<string,string>
     */
    public function processVacationMode(Request $request, PlayerService $player): array
    {
        $vacationModeChecked = $request->has('urlaubs_modus');

        // If player is currently in vacation mode
        if ($player->isInVacationMode()) {
            // Player wants to deactivate vacation mode
            if (!$vacationModeChecked) {
                if ($player->canDeactivateVacationMode()) {
                    $player->deactivateVacationMode();
                    return array('success' => __('t_ingame.options.msg_vacation_deactivated'));
                } else {
                    return array('error' => __('t_ingame.options.msg_vacation_min_duration'));
                }
            }
            // If checkbox is still checked while in vacation mode, do nothing
            return array();
        } else {
            // Player is not in vacation mode and wants to activate it
            if ($vacationModeChecked) {
                if ($player->canActivateVacationMode()) {
                    $player->activateVacationMode();
                    return array('success' => __('t_ingame.options.msg_vacation_activated'));
                } else {
                    return array('error' => __('t_ingame.options.msg_vacation_fleets_in_transit'));
                }
            }
        }

        return array();
    }

    /**
     * Process password change request.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return array<string,string>|null
     */
    public function processChangePassword(Request $request, PlayerService $player): array|null
    {
        $currentPassword = $request->input('db_password');

        // Only process if the password section was submitted
        if (empty($currentPassword)) {
            return null;
        }

        $newPassword = $request->input('newpass1');
        $confirmPassword = $request->input('newpass2');

        if (!Hash::check($currentPassword, $player->getUser()->password)) {
            return ['error' => __('t_ingame.options.msg_password_incorrect')];
        }

        if ($newPassword !== $confirmPassword) {
            return ['error' => __('t_ingame.options.msg_password_mismatch')];
        }

        $length = strlen($newPassword);
        if ($length < 4 || $length > 128) {
            return ['error' => __('t_ingame.options.msg_password_length_invalid')];
        }

        $player->getUser()->forceFill(['password' => Hash::make($newPassword)])->save();

        return ['success' => __('t_ingame.options.msg_settings_saved')];
    }

    /**
     * Process espionage probes amount save request.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return array<string,string>|null
     */
    public function processEspionageProbesAmount(Request $request, PlayerService $player): array|null
    {
        // Only process if the field is present in the request
        if (!array_key_exists('espionage_probes_amount', $request->all())) {
            return null;
        }

        $amount = $request->input('espionage_probes_amount');

        // Allow empty string to clear the setting
        if ($amount === '' || $amount === null) {
            $player->setEspionageProbesAmount(null);
            $player->save();
            return array('success' => __('t_ingame.options.msg_settings_saved'));
        }

        // Validate that it's a positive integer
        $amount = (int) $amount;
        if ($amount < 1) {
            return array('error' => __('t_ingame.options.msg_probes_min_one'));
        }

        $player->setEspionageProbesAmount($amount);
        $player->save();

        return array('success' => __('t_ingame.options.msg_settings_saved'));
    }

    /**
     * Download the current player's account data (empire state) as a JSON file.
     *
     * @param PlayerService $player
     * @param AccountExportService $exportService
     * @return StreamedResponse
     */
    public function exportData(PlayerService $player, AccountExportService $exportService): StreamedResponse
    {
        $data = $exportService->export($player->getUser());
        $filename = 'ogamex-export-' . Str::slug($player->getUsername(false)) . '-' . now()->format('Y-m-d') . '.json';

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Restore a previously exported account data JSON file into the current player's account.
     * This fully replaces the player's existing planets/queues with the imported data.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param AccountImportService $importService
     * @return RedirectResponse
     */
    public function importData(Request $request, PlayerService $player, AccountImportService $importService): RedirectResponse
    {
        if (!$request->hasFile('import_file') || !$request->file('import_file')->isValid()) {
            return redirect()->route('options.index')->with('error', __('t_ingame.options.msg_import_invalid_file'));
        }

        $file = $request->file('import_file');
        if ($file->getSize() > 5 * 1024 * 1024) {
            return redirect()->route('options.index')->with('error', __('t_ingame.options.msg_import_invalid_file'));
        }

        $contents = file_get_contents($file->getRealPath());
        $data = $contents !== false ? json_decode($contents, true) : null;

        if (!is_array($data)) {
            return redirect()->route('options.index')->with('error', __('t_ingame.options.msg_import_invalid_file'));
        }

        try {
            $warnings = $importService->import($player->getUser(), $data);
        } catch (AccountImportException $e) {
            return redirect()->route('options.index')->with('error', $e->getMessage());
        }

        // Reload the player object so the rest of the app reflects the newly imported empire.
        $player->load($player->getId());

        $successMessage = __('t_ingame.options.msg_import_success');
        if (!empty($warnings)) {
            $successMessage .= ' ' . implode(' ', $warnings);
        }

        return redirect()->route('options.index')->with('success', $successMessage);
    }

    /**
     * Save handler for index() form.
     *
     * @param Request $request
     * @param PlayerService $player
     * @return RedirectResponse
     */
    public function save(Request $request, PlayerService $player): RedirectResponse
    {
        // Define change handlers.
        $change_handlers = [
            'processChangeUsername',
            'processChangePassword',
            'processVacationMode',
            'processEspionageProbesAmount'
        ];

        // Loop through change handlers, execute them and if it triggers
        // return its message.
        foreach ($change_handlers as $method) {
            $change_handler = $this->{$method}($request, $player);
            if ($change_handler) {
                if (!empty($change_handler['success_logout'])) {
                    return redirect()->route('options.index')->with('success_logout', $change_handler['success_logout']);
                }

                if (!empty($change_handler[ 'success'])) {
                    return redirect()->route('options.index')->with('success', $change_handler['success']);
                }

                if (!empty($change_handler[ 'error'])) {
                    return redirect()->route('options.index')->with('error', $change_handler['error']);
                }
            }
        }

        // No actual change has been detected, return to index page.
        return redirect()->route('options.index');
    }
}
