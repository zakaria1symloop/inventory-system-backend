<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Stores APK files on the local (VPS) public disk.
 *
 *   Disk path:    storage/app/public/apks/{type}/{filename}
 *   Public URL:   {APP_URL}/storage/apks/{type}/{filename}
 *
 * `php artisan storage:link` must have been run inside the container so
 * `public/storage` points to `../storage/app/public`. Data folder is on
 * the host bind mount (./backend/storage), so files survive container
 * recreates; the symlink itself is in the image and needs to be recreated
 * after a `docker compose build`.
 */
class AdminVersionController extends Controller
{
    private const DISK = 'public';
    private const ALLOWED_TYPES = ['driver', 'sales', 'cashvan'];

    public function getApks()
    {
        $apks = AppVersion::first();

        return response()->json([
            'driver_apk_url'      => $apks?->driver_apk_url,
            'driver_apk_version'  => $apks?->driver_apk_version,
            'sales_apk_url'       => $apks?->sales_apk_url,
            'sales_apk_version'   => $apks?->sales_apk_version,
            'cashvan_apk_url'     => $apks?->cashvan_apk_url,
            'cashvan_apk_version' => $apks?->cashvan_apk_version,
        ]);
    }

    public function uploadApk(Request $request)
    {
        $request->validate([
            'file'    => 'required|file|max:102400', // 100 MB
            'type'    => 'required|in:' . implode(',', self::ALLOWED_TYPES),
            'version' => 'required|string|max:20',
        ]);

        $file     = $request->file('file');
        $type     = $request->type;
        $version  = $request->version;
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file->getClientOriginalName());
        $filename = time() . '_' . $safeName;
        $path     = "apks/{$type}/{$filename}";

        try {
            Storage::disk(self::DISK)->put(
                $path,
                file_get_contents($file->getRealPath())
            );
        } catch (\Exception $e) {
            return response()->json(['message' => 'خطأ في الرفع: ' . $e->getMessage()], 500);
        }

        // Since Laravel 9+, Storage::url() returns an absolute URL when
        // APP_URL is set. Don't prepend again — that doubles the host.
        $url = Storage::disk(self::DISK)->url($path);
        if (str_starts_with($url, '/')) {
            $url = rtrim(config('app.url'), '/') . $url;
        }

        $apks       = AppVersion::firstOrCreate([]);
        $urlKey     = "{$type}_apk_url";
        $versionKey = "{$type}_apk_version";

        if ($apks->$urlKey) {
            $this->deleteLocalIfOurs($apks->$urlKey);
        }

        $apks->update([
            $urlKey     => $url,
            $versionKey => $version,
        ]);

        return response()->json([
            'url'      => $url,
            'version'  => $version,
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    public function deleteApk(Request $request)
    {
        $request->validate([
            'type' => 'required|in:' . implode(',', self::ALLOWED_TYPES),
        ]);

        $apks = AppVersion::first();
        if (!$apks) {
            return response()->json(['message' => 'لا توجد بيانات.'], 404);
        }

        $urlKey     = "{$request->type}_apk_url";
        $versionKey = "{$request->type}_apk_version";

        if ($apks->$urlKey) {
            $this->deleteLocalIfOurs($apks->$urlKey);
            $apks->update([
                $urlKey     => null,
                $versionKey => null,
            ]);
        }

        return response()->json(['message' => 'تم حذف الملف بنجاح.']);
    }

    /**
     * Best-effort delete the file behind a URL — but only when the URL
     * looks like one we created (under our own /storage/ path). Old GCS
     * URLs from before the migration are just dropped from the DB.
     */
    private function deleteLocalIfOurs(string $url): void
    {
        $prefix = rtrim(config('app.url'), '/') . '/storage/';
        if (!str_starts_with($url, $prefix)) {
            return;
        }
        $relative = substr($url, strlen($prefix));
        try {
            Storage::disk(self::DISK)->delete($relative);
        } catch (\Exception) {
            // Best-effort.
        }
    }
}
