<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Contracts\Encryption\DecryptException;

class BackupController extends Controller
{
    /**
     * Tables ordered for FK-safe restore (parents first).
     * On truncate, reversed (children first).
     */
    private array $backupTables = [
        // Tier 0: No foreign key dependencies
        'settings',
        'employees',
        'client_categories',
        'categories',
        'brands',
        'units',
        'vehicles',
        'warehouses',

        // Tier 1: Basic FK refs to Tier 0
        'users',
        'products',
        'suppliers',

        // Tier 2: Refs Tier 0-1
        'clients',
        'caisses',
        'stock',
        'product_category_prices',
        'personal_access_tokens',

        // Tier 3: Main transactional tables
        'purchases',
        'sales',
        'trips',
        'adjustments',
        'van_sessions',
        'dispenses',
        'stock_transfers',
        'purchase_orders',
        'deliveries',

        // Tier 4: Children of Tier 3
        'purchase_items',
        'purchase_returns',
        'sale_items',
        'sale_returns',
        'trip_stores',
        'orders',
        'adjustment_items',
        'van_session_items',
        'van_sales',
        'van_returns',
        'caisse_transactions',
        'caisse_settlements',
        'caisse_transfers',
        'stock_transfer_items',
        'payments',
        'stock_movements',
        'product_requests',
        'purchase_order_items',
        'delivery_orders',
        'delivery_stock',
        'sync_logs',

        // Tier 5: Leaf child tables
        'purchase_return_items',
        'sale_return_items',
        'order_items',
        'van_sale_items',
        'delivery_returns',
        'product_request_items',
    ];

    /**
     * Create an encrypted backup of all application data.
     */
    public function create(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        set_time_limit(300);

        $tableCounts = [];
        $tables = [];

        foreach ($this->backupTables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn($row) => (array) $row)->toArray();
            $tables[$table] = $rows;
            $tableCounts[$table] = count($rows);
        }

        $data = [
            'metadata' => [
                'version' => '1.0',
                'app' => 'rafik_biskra',
                'created_at' => now()->toIso8601String(),
                'created_by' => $request->user()->name,
                'table_counts' => $tableCounts,
            ],
            'tables' => $tables,
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $compressed = gzencode($json, 9);
        $encrypted = encrypt($compressed);

        $filename = 'backup_' . now()->format('Y-m-d_His') . '.rbk';

        return response($encrypted)
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Restore data from an encrypted backup file.
     */
    public function restore(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'backup' => 'required|file|max:102400', // 100MB max
        ]);

        set_time_limit(600);

        // Decrypt
        try {
            $encryptedContent = file_get_contents($request->file('backup')->getRealPath());
            $compressed = decrypt($encryptedContent);
        } catch (DecryptException $e) {
            return response()->json([
                'message' => 'فشل فك التشفير. الملف غير صالح أو من نظام آخر.'
            ], 422);
        }

        // Decompress
        $json = @gzdecode($compressed);
        if ($json === false) {
            return response()->json([
                'message' => 'فشل فك الضغط. الملف تالف.'
            ], 422);
        }

        // Decode JSON
        $data = json_decode($json, true);
        if (!$data || !isset($data['metadata']) || !isset($data['tables'])) {
            return response()->json([
                'message' => 'بنية الملف غير صالحة.'
            ], 422);
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            // Delete all data in reverse order (children first)
            foreach (array_reverse($this->backupTables) as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            // Insert in forward order (parents first)
            foreach ($this->backupTables as $table) {
                if (!isset($data['tables'][$table]) || !Schema::hasTable($table)) {
                    continue;
                }

                $rows = $data['tables'][$table];
                if (empty($rows)) {
                    continue;
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table($table)->insert(
                        array_map(fn($row) => (array) $row, $chunk)
                    );
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            // Clear settings cache
            Setting::clearCache();

            return response()->json([
                'message' => 'تم استعادة النسخة الاحتياطية بنجاح',
                'metadata' => $data['metadata'],
            ]);
        } catch (\Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            return response()->json([
                'message' => 'فشل في استعادة النسخة: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export current tenant database as plain SQL dump.
     */
    public function exportSql(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        set_time_limit(300);

        try {
            $sql = $this->generateSqlDump();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'فشل تصدير قاعدة البيانات: ' . $e->getMessage(),
            ], 500);
        }

        $dbName = DB::getDatabaseName();
        $filename = $dbName . '_' . now()->format('Y-m-d_His') . '.sql';

        return response($sql)
            ->header('Content-Type', 'application/sql')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Generate SQL dump from current DB connection.
     */
    public function generateSqlDump(?string $connection = null): string
    {
        $db = $connection ? DB::connection($connection) : DB::connection();
        $dbName = $db->getDatabaseName();

        $lines = [];
        $lines[] = "-- SQL Dump generated by TrackSera";
        $lines[] = "-- Date: " . now()->toIso8601String();
        $lines[] = "-- Database: {$dbName}";
        $lines[] = "";
        $lines[] = "SET FOREIGN_KEY_CHECKS=0;";
        $lines[] = "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';";
        $lines[] = "";

        // Get all tables
        $tables = $db->select("SHOW TABLES");
        $key = "Tables_in_{$dbName}";

        foreach ($tables as $tableRow) {
            $table = $tableRow->$key;

            // CREATE TABLE
            $create = $db->select("SHOW CREATE TABLE `{$table}`");
            $createSql = $create[0]->{'Create Table'} ?? null;
            if (!$createSql) continue;

            $lines[] = "DROP TABLE IF EXISTS `{$table}`;";
            $lines[] = $createSql . ";";
            $lines[] = "";

            // INSERT rows in chunks
            $rows = $db->table($table)->get();
            if ($rows->isEmpty()) continue;

            foreach ($rows->chunk(500) as $chunk) {
                $columns = array_keys((array) $chunk->first());
                $colList = implode('`, `', $columns);

                $values = [];
                foreach ($chunk as $row) {
                    $vals = [];
                    foreach ((array) $row as $val) {
                        if (is_null($val)) {
                            $vals[] = 'NULL';
                        } else {
                            $vals[] = "'" . addslashes((string) $val) . "'";
                        }
                    }
                    $values[] = '(' . implode(', ', $vals) . ')';
                }

                $lines[] = "INSERT INTO `{$table}` (`{$colList}`) VALUES";
                $lines[] = implode(",\n", $values) . ";";
                $lines[] = "";
            }
        }

        $lines[] = "SET FOREIGN_KEY_CHECKS=1;";

        return implode("\n", $lines);
    }

    /**
     * Read backup file metadata without restoring.
     */
    public function info(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'backup' => 'required|file|max:102400',
        ]);

        try {
            $encryptedContent = file_get_contents($request->file('backup')->getRealPath());
            $compressed = decrypt($encryptedContent);
        } catch (DecryptException $e) {
            return response()->json([
                'message' => 'فشل فك التشفير. الملف غير صالح أو من نظام آخر.'
            ], 422);
        }

        $json = @gzdecode($compressed);
        if ($json === false) {
            return response()->json([
                'message' => 'فشل فك الضغط. الملف تالف.'
            ], 422);
        }

        $data = json_decode($json, true);
        if (!$data || !isset($data['metadata'])) {
            return response()->json([
                'message' => 'بنية الملف غير صالحة.'
            ], 422);
        }

        return response()->json([
            'metadata' => $data['metadata'],
        ]);
    }
}
