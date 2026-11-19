<?php

namespace App\Http\Controllers;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Videos and posters of the Panduan page, for signed-in panel users only.
 *
 * Served by the app rather than from public/ so Range requests (206) always work, also under
 * `php artisan serve`; without them a click on a step cannot jump to its second in the video.
 */
class PanduanVideoController extends Controller
{
    public const DIRECTORY = 'videos/panduan';

    public function __invoke(Request $request, string $file): BinaryFileResponse
    {
        // Same gate as the panel: an inactive account or an account without a role gets nothing.
        abort_unless($request->user()?->canAccessPanel(Filament::getPanel('admin')), 403);

        $path = self::path($file);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.mp4') ? 'video/mp4' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function path(string $file): string
    {
        return resource_path(self::DIRECTORY.'/'.$file);
    }
}
