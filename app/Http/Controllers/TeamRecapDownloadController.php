<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TeamRecapService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the monthly team recap behind a short-lived signed link, so the app can open it in the
 * phone's browser (which has no API token) and save or share the Excel file from there.
 */
class TeamRecapDownloadController extends Controller
{
    public function __invoke(Request $request, User $user, TeamRecapService $recaps): BinaryFileResponse
    {
        // The link outlives nothing the API would refuse: an inactive account or a switched-off Klien gets no file.
        abort_unless($user->canSignIn() && $user->canManageWorkers(), 403);

        $month = Carbon::createFromFormat('Y-m', (string) $request->query('month'))->startOfMonth();

        return response()->download($recaps->export($user, $month), $recaps->fileName($month))->deleteFileAfterSend();
    }
}
