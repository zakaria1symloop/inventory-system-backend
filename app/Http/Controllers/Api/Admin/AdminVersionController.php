<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Http\Request;

class AdminVersionController extends Controller
{
    private function gcs(): \Google\Cloud\Storage\Bucket
    {
        $storage = new StorageClient([
            'projectId' => config('filesystems.disks.gcs.project_id'),
        ]);
        return $storage->bucket(config('filesystems.disks.gcs.bucket'));
    }

    /**
     * Get current APK URLs and versions.
     */
    public function getApks()
    {
        $apks = AppVersion::first();

        return response()->json([
            'driver_apk_url' => $apks?->driver_apk_url,
            'driver_apk_version' => $apks?->driver_apk_version,
            'sales_apk_url' => $apks?->sales_apk_url,
            'sales_apk_version' => $apks?->sales_apk_version,
            'cashvan_apk_url' => $apks?->cashvan_apk_url,
            'cashvan_apk_version' => $apks?->cashvan_apk_version,
        ]);
    }

    /**
     * Upload an APK file to GCS.
     */
    public function uploadApk(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:102400', // 100MB
            'type' => 'required|in:driver,sales,cashvan',
            'version' => 'required|string|max:20',
        ]);

        $file = $request->file('file');
        $type = $request->type;
        $version = $request->version;
        $filename = time() . '_' . $file->getClientOriginalName();
        $objectName = "apks/{$type}/{$filename}";

        try {
            $bucket = $this->gcs();
            $bucket->upload(
                file_get_contents($file->getRealPath()),
                [
                    'name' => $objectName,
                    'resumable' => false,
                ]
            );
        } catch (\Exception $e) {
            return response()->json(['message' => 'خطأ في الرفع: ' . $e->getMessage()], 500);
        }

        $bucketName = config('filesystems.disks.gcs.bucket');
        $url = "https://storage.googleapis.com/{$bucketName}/{$objectName}";

        // Save to the single config row
        $apks = AppVersion::firstOrCreate([]);

        $urlKey = "{$type}_apk_url";
        $versionKey = "{$type}_apk_version";

        // Delete old file if exists
        $oldUrl = $apks->$urlKey;
        if ($oldUrl) {
            $prefix = "https://storage.googleapis.com/{$bucketName}/";
            if (str_starts_with($oldUrl, $prefix)) {
                $oldPath = substr($oldUrl, strlen($prefix));
                try {
                    $bucket = $this->gcs();
                    $bucket->object($oldPath)->delete();
                } catch (\Exception $e) {
                    // Ignore deletion errors
                }
            }
        }

        $apks->update([
            $urlKey => $url,
            $versionKey => $version,
        ]);

        return response()->json([
            'url' => $url,
            'version' => $version,
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Delete an APK file from GCS and clear the URL.
     */
    public function deleteApk(Request $request)
    {
        $request->validate([
            'type' => 'required|in:driver,sales,cashvan',
        ]);

        $apks = AppVersion::first();
        if (!$apks) {
            return response()->json(['message' => 'لا توجد بيانات.'], 404);
        }

        $urlKey = "{$request->type}_apk_url";
        $versionKey = "{$request->type}_apk_version";
        $url = $apks->$urlKey;

        if ($url) {
            $bucketName = config('filesystems.disks.gcs.bucket');
            $prefix = "https://storage.googleapis.com/{$bucketName}/";

            if (str_starts_with($url, $prefix)) {
                $path = substr($url, strlen($prefix));
                try {
                    $bucket = $this->gcs();
                    $bucket->object($path)->delete();
                } catch (\Exception $e) {
                    // Ignore deletion errors
                }
            }

            $apks->update([
                $urlKey => null,
                $versionKey => null,
            ]);
        }

        return response()->json([
            'message' => 'تم حذف الملف بنجاح.',
        ]);
    }
}
