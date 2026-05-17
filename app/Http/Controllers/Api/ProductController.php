<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Product;
use App\Models\ProductCategoryPrice;
use App\Models\Stock;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $warehouseId = $request->warehouse_id;

        // Always eager-load ALL stock entries so the frontend can compute
        // per-warehouse availability and show stock from other warehouses.
        // (Previously this filtered stock to the main warehouse, hiding
        // stock that existed in sub-warehouses.)
        $query = Product::with(['category', 'brand', 'unitBuy', 'unitSale', 'stock', 'categoryPrices']);

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->brand_id) {
            $query->where('brand_id', $request->brand_id);
        }

        // Filter by supplier — products with no supplier set are also returned
        // so that pre-existing inventory remains visible until users assign suppliers.
        if ($request->supplier_id) {
            $supplierId = $request->supplier_id;
            $query->where(function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId)
                  ->orWhereNull('supplier_id');
            });
        }

        if ($request->active_only) {
            $query->where('is_active', true);
        }

        if ($request->low_stock) {
            $query->lowStock();
        }

        $products = $query->latest()->paginate($request->per_page ?? 15);

        // If warehouse_id is specified, calculate available stock for each product
        if ($warehouseId) {
            $products->getCollection()->transform(function ($product) use ($warehouseId) {
                $product->available_stock = Stock::getAvailableStock($product->id, $warehouseId);
                return $product;
            });
        }

        return response()->json($products);
    }

    public function store(Request $request)
    {
        // Check tenant product limit
        $tenant = $request->attributes->get('tenant');
        if ($tenant) {
            $currentCount = Product::count();
            if ($currentCount >= $tenant->product_limit) {
                return response()->json([
                    'message' => 'لقد وصلت إلى الحد الأقصى لعدد المنتجات (' . $tenant->product_limit . '). قم بترقية خطتك لإضافة المزيد.',
                    'limit' => $tenant->product_limit,
                    'plan' => $tenant->plan,
                ], 403);
            }
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'unit_buy_id' => 'nullable|exists:units,id',
            'unit_sale_id' => 'nullable|exists:units,id',
            'barcode' => 'nullable|unique:products',
            'cost_price' => 'required|numeric|min:0',
            'retail_price' => 'nullable|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'min_selling_price' => 'nullable|numeric|min:0',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'tax_type' => 'in:exclusive,inclusive',
            'discount_type' => 'in:percent,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'stock_alert' => 'nullable|integer|min:0',
            'opening_stock' => 'nullable|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'image' => 'nullable|image|max:2048',
        ]);

        // Buy and sale units must be convertible (share same root unit)
        if (!\App\Models\Unit::compatible($request->unit_buy_id, $request->unit_sale_id)) {
            return response()->json([
                'message' => 'وحدة الشراء ووحدة البيع غير متوافقتين (مثلاً كيلوغرام لا يُباع بالقطعة).',
                'errors' => ['unit_sale_id' => ['Sale unit incompatible with buy unit']],
            ], 422);
        }

        $data = $request->except(['image', 'warehouse_id', 'opening_stock', 'category_prices']);

        // Default price fields to 0 if not provided
        $data['retail_price'] = $data['retail_price'] ?? 0;
        $data['wholesale_price'] = $data['wholesale_price'] ?? 0;
        $data['min_selling_price'] = $data['min_selling_price'] ?? 0;

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create($data);

        if ($request->opening_stock && $request->warehouse_id) {
            Stock::create([
                'product_id' => $product->id,
                'warehouse_id' => $request->warehouse_id,
                'quantity' => $request->opening_stock,
            ]);
        }

        // Save category prices and auto-set retail_price from default category
        if ($request->has('category_prices') && is_array($request->category_prices)) {
            $defaultCategoryId = ClientCategory::where('is_default', true)->value('id');

            foreach ($request->category_prices as $cp) {
                if (!empty($cp['client_category_id']) && isset($cp['price']) && $cp['price'] !== null && $cp['price'] !== '') {
                    ProductCategoryPrice::create([
                        'product_id' => $product->id,
                        'client_category_id' => $cp['client_category_id'],
                        'price' => $cp['price'],
                    ]);

                    // Auto-set retail_price from default category price
                    if ($defaultCategoryId && (int) $cp['client_category_id'] === $defaultCategoryId) {
                        $product->update(['retail_price' => $cp['price']]);
                    }
                }
            }
        }

        return response()->json($product->load(['category', 'brand', 'supplier', 'unitBuy', 'unitSale', 'categoryPrices']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'brand', 'supplier', 'unitBuy', 'unitSale', 'stock.warehouse', 'categoryPrices']));
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'name' => 'string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'unit_buy_id' => 'nullable|exists:units,id',
            'unit_sale_id' => 'nullable|exists:units,id',
            'barcode' => 'nullable|unique:products,barcode,' . $product->id,
            'cost_price' => 'numeric|min:0',
            'retail_price' => 'numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'min_selling_price' => 'nullable|numeric|min:0',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'tax_type' => 'in:exclusive,inclusive',
            'discount_type' => 'in:percent,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'stock_alert' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:2048',
        ]);

        // Buy and sale units must be convertible (share same root unit)
        $effectiveBuy = $request->unit_buy_id ?? $product->unit_buy_id;
        $effectiveSale = $request->unit_sale_id ?? $product->unit_sale_id;
        if (!\App\Models\Unit::compatible($effectiveBuy, $effectiveSale)) {
            return response()->json([
                'message' => 'وحدة الشراء ووحدة البيع غير متوافقتين (مثلاً كيلوغرام لا يُباع بالقطعة).',
                'errors' => ['unit_sale_id' => ['Sale unit incompatible with buy unit']],
            ], 422);
        }

        $data = $request->except(['image', 'category_prices']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product->update($data);

        // Update category prices and auto-set retail_price from default category
        if ($request->has('category_prices') && is_array($request->category_prices)) {
            $product->categoryPrices()->delete();
            $defaultCategoryId = ClientCategory::where('is_default', true)->value('id');

            foreach ($request->category_prices as $cp) {
                if (!empty($cp['client_category_id']) && isset($cp['price']) && $cp['price'] !== null && $cp['price'] !== '') {
                    ProductCategoryPrice::create([
                        'product_id' => $product->id,
                        'client_category_id' => $cp['client_category_id'],
                        'price' => $cp['price'],
                    ]);

                    // Auto-set retail_price from default category price
                    if ($defaultCategoryId && (int) $cp['client_category_id'] === $defaultCategoryId) {
                        $product->update(['retail_price' => $cp['price']]);
                    }
                }
            }
        }

        return response()->json($product->load(['category', 'brand', 'unitBuy', 'unitSale', 'categoryPrices']));
    }

    public function destroy(Product $product)
    {
        // Check if product has stock in any warehouse
        $totalStock = $product->stock()->sum('quantity');
        if ($totalStock > 0) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. يوجد كمية في المخزون (' . $totalStock . '). قم بإفراغ المخزون أولاً'
            ], 400);
        }

        // Check if product is used in any purchase items
        if ($product->purchaseItems()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. تم استخدامه في فواتير شراء'
            ], 400);
        }

        // Check if product is used in any sale items
        if ($product->saleItems()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. تم استخدامه في فواتير بيع'
            ], 400);
        }

        // Check if product is used in any order items
        if ($product->orderItems()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. تم استخدامه في طلبات'
            ], 400);
        }

        $product->delete();

        return response()->json(['message' => 'تم حذف المنتج بنجاح']);
    }

    public function generateBarcode()
    {
        return response()->json(['barcode' => Product::generateBarcode()]);
    }

    public function findByBarcode(Request $request)
    {
        $request->validate(['barcode' => 'required']);

        $product = Product::where('barcode', $request->barcode)->first();

        if (!$product) {
            return response()->json(['message' => 'المنتج غير موجود'], 404);
        }

        return response()->json($product->load(['category', 'brand', 'unitBuy', 'unitSale', 'stock']));
    }

    public function getStock(Product $product, Request $request)
    {
        $warehouseId = $request->warehouse_id;

        if ($warehouseId) {
            $stock = $product->getStockInWarehouse($warehouseId);
        } else {
            $stock = $product->getTotalStock();
        }

        return response()->json(['quantity' => $stock]);
    }

    /**
     * Get available stock for a product (current stock minus reserved quantities)
     */
    public function getAvailableStock(Product $product, Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $availableQty = Stock::getAvailableStock($product->id, $request->warehouse_id);

        return response()->json([
            'quantity' => $availableQty,
            'product_id' => $product->id,
            'warehouse_id' => $request->warehouse_id,
        ]);
    }

    /**
     * Get available stock for multiple products in a warehouse
     */
    public function getAvailableStockBulk(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        $warehouseId = $request->warehouse_id;
        $productIds = $request->product_ids;

        $query = Product::with(['stock' => function ($q) use ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }]);

        if ($productIds) {
            $query->whereIn('id', $productIds);
        }

        $products = $query->get();

        $result = $products->map(function ($product) use ($warehouseId) {
            $currentStock = $product->stock->first()?->quantity ?? 0;
            $availableStock = Stock::getAvailableStock($product->id, $warehouseId);

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'current_stock' => (float) $currentStock,
                'available_stock' => $availableStock,
                'reserved' => (float) $currentStock - $availableStock,
            ];
        });

        return response()->json($result);
    }

    /**
     * Get product prices for a specific client based on their category
     */
    public function getPricesForClient(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
        ]);

        $client = Client::find($request->client_id);
        $categoryId = $client->client_category_id;

        $prices = [];

        if ($categoryId) {
            $categoryPrices = ProductCategoryPrice::where('client_category_id', $categoryId)->get();
            foreach ($categoryPrices as $cp) {
                $prices[$cp->product_id] = (float) $cp->price;
            }
        }

        return response()->json([
            'client_category_id' => $categoryId,
            'prices' => $prices,
        ]);
    }

    /**
     * Download Excel import template with dropdown menus and styled headers
     */
    public function downloadTemplate()
    {
        $categories = Category::orderBy('name')->pluck('name')->toArray();
        $brands = Brand::orderBy('name')->pluck('name')->toArray();
        $units = Unit::orderBy('name')->get();
        $clientCategories = ClientCategory::orderBy('id')->get();

        $spreadsheet = new Spreadsheet();

        // ── Sheet 2: "المراجع" (References) — hidden data source for dropdowns ──
        $refSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'المراجع');
        $spreadsheet->addSheet($refSheet, 1);
        $refSheet->setRightToLeft(true);

        // Column A: Categories
        $refSheet->setCellValue('A1', 'الفئات');
        foreach ($categories as $i => $name) {
            $refSheet->setCellValue('A' . ($i + 2), $name);
        }

        // Column B: Brands
        $refSheet->setCellValue('B1', 'العلامات التجارية');
        foreach ($brands as $i => $name) {
            $refSheet->setCellValue('B' . ($i + 2), $name);
        }

        // Column C: Units
        $refSheet->setCellValue('C1', 'الوحدات');
        foreach ($units->values() as $i => $unit) {
            $refSheet->setCellValue('C' . ($i + 2), $unit->name);
        }

        // Hide reference sheet
        $refSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        // ── Sheet 1: "المنتجات" (Products) — data entry sheet ──
        $dataSheet = $spreadsheet->getSheet(0);
        $dataSheet->setTitle('المنتجات');
        $dataSheet->setRightToLeft(true);

        // Define headers
        $staticHeaders = [
            'الاسم',
            'الفئة',
            'العلامة التجارية',
            'وحدة الشراء',
            'وحدة البيع',
            'الباركود',
            'سعر الشراء/قطعة',
            'حد التنبيه',
            'نسبة الضريبة %',
            'عدد القطع في الكرتون',
            'الكمية الأولية',
        ];

        $dynamicHeaders = [];
        foreach ($clientCategories as $cc) {
            $dynamicHeaders[] = 'سعر ' . $cc->name;
        }

        $allHeaders = array_merge($staticHeaders, $dynamicHeaders);
        $totalCols = count($allHeaders);
        $lastColLetter = Coordinate::stringFromColumnIndex($totalCols);

        // ── Row 1: Headers ──
        foreach ($allHeaders as $colIdx => $header) {
            $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
            $dataSheet->setCellValue($colLetter . '1', $header);
        }

        // Style header row: blue background, white bold text
        $headerRange = 'A1:' . $lastColLetter . '1';
        $dataSheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E79'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
            ],
        ]);
        $dataSheet->getRowDimension(1)->setRowHeight(30);

        // Freeze top row
        $dataSheet->freezePane('A2');

        // ── Row 2: Example data (gray italic) ──
        $exampleData = [
            'مثال: حليب نصف دسم 1 لتر',
            $categories[0] ?? 'اختر من القائمة',
            $brands[0] ?? 'اختر من القائمة',
            $units->first()?->name ?? 'اختر من القائمة',
            $units->first()?->name ?? 'اختر من القائمة',
            '6281000000001',
            '150',
            '10',
            '0',
            '12',
            '100',
        ];
        foreach ($clientCategories as $cc) {
            $exampleData[] = '220';
        }

        foreach ($exampleData as $colIdx => $value) {
            $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
            $dataSheet->setCellValue($colLetter . '2', $value);
        }

        // Style example row: light gray background, italic
        $exampleRange = 'A2:' . $lastColLetter . '2';
        $dataSheet->getStyle($exampleRange)->applyFromArray([
            'font' => [
                'italic' => true,
                'color' => ['rgb' => '808080'],
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F2F2F2'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // ── Row 3: Instruction hint ──
        $dataSheet->setCellValue('A3', '⬆ صف المثال — احذفه أو استبدله ببياناتك. ابدأ الإدخال من السطر 3');
        $dataSheet->mergeCells('A3:' . $lastColLetter . '3');
        $dataSheet->getStyle('A3')->applyFromArray([
            'font' => [
                'italic' => true,
                'color' => ['rgb' => '2E75B6'],
                'size' => 9,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'DEEAF6'],
            ],
        ]);

        // ── Data Validation (Dropdowns) for rows 2–200 ──
        $maxDataRow = 200;

        // Category dropdown (column B)
        if (count($categories) > 0) {
            $catLastRow = count($categories) + 1;
            for ($row = 2; $row <= $maxDataRow; $row++) {
                $validation = $dataSheet->getCell('B' . $row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_WARNING);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setShowInputMessage(true);
                $validation->setPromptTitle('الفئة');
                $validation->setPrompt('اختر الفئة من القائمة');
                $validation->setShowErrorMessage(true);
                $validation->setErrorTitle('خطأ');
                $validation->setError('هذه القيمة غير موجودة في القائمة');
                $validation->setFormula1('المراجع!$A$2:$A$' . $catLastRow);
            }
        }

        // Brand dropdown (column C)
        if (count($brands) > 0) {
            $brandLastRow = count($brands) + 1;
            for ($row = 2; $row <= $maxDataRow; $row++) {
                $validation = $dataSheet->getCell('C' . $row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_WARNING);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setShowInputMessage(true);
                $validation->setPromptTitle('العلامة التجارية');
                $validation->setPrompt('اختر العلامة من القائمة');
                $validation->setShowErrorMessage(true);
                $validation->setFormula1('المراجع!$B$2:$B$' . $brandLastRow);
            }
        }

        // Unit Buy dropdown (column D) and Unit Sale dropdown (column E)
        if ($units->count() > 0) {
            $unitLastRow = $units->count() + 1;
            foreach (['D', 'E'] as $unitCol) {
                for ($row = 2; $row <= $maxDataRow; $row++) {
                    $validation = $dataSheet->getCell($unitCol . $row)->getDataValidation();
                    $validation->setType(DataValidation::TYPE_LIST);
                    $validation->setErrorStyle(DataValidation::STYLE_WARNING);
                    $validation->setAllowBlank(true);
                    $validation->setShowDropDown(true);
                    $validation->setShowInputMessage(true);
                    $validation->setPromptTitle('الوحدة');
                    $validation->setPrompt('اختر الوحدة من القائمة');
                    $validation->setShowErrorMessage(true);
                    $validation->setFormula1('المراجع!$C$2:$C$' . $unitLastRow);
                }
            }
        }

        // ── Column Widths ──
        $colWidths = [
            'A' => 30, // Name
            'B' => 18, // Category
            'C' => 20, // Brand
            'D' => 16, // Unit Buy
            'E' => 16, // Unit Sale
            'F' => 20, // Barcode
            'G' => 18, // Cost Price
            'H' => 14, // Stock Alert
            'I' => 16, // Tax %
            'J' => 22, // Pieces per Package
            'K' => 16, // Opening Stock
        ];
        foreach ($colWidths as $col => $width) {
            $dataSheet->getColumnDimension($col)->setWidth($width);
        }
        // Dynamic columns for client category prices (start at column 12 now)
        for ($i = 0; $i < count($dynamicHeaders); $i++) {
            $colLetter = Coordinate::stringFromColumnIndex(12 + $i);
            $dataSheet->getColumnDimension($colLetter)->setWidth(18);
        }

        // Style price columns with light green background header
        if (count($dynamicHeaders) > 0) {
            $priceStartCol = Coordinate::stringFromColumnIndex(12);
            $priceEndCol = Coordinate::stringFromColumnIndex(11 + count($dynamicHeaders));
            $priceHeaderRange = $priceStartCol . '1:' . $priceEndCol . '1';
            $dataSheet->getStyle($priceHeaderRange)->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2E75B6'],
                ],
            ]);
        }

        // ── Alternate row coloring for data area (rows 4–20) ──
        for ($row = 4; $row <= 20; $row++) {
            if ($row % 2 === 0) {
                $dataSheet->getStyle('A' . $row . ':' . $lastColLetter . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F8F9FA'],
                    ],
                ]);
            }
        }

        // ── Write to temp file and return ──
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'product_template_');
        $writer->save($tempFile);

        return response()->download($tempFile, 'نموذج_استيراد_المنتجات.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Parse Excel file headers and build column mappings.
     */
    private function parseExcelFile(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestRow();
        $highestColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $headers = [];
        for ($col = 1; $col <= $highestColIdx; $col++) {
            $headers[$col] = trim((string) $sheet->getCell([$col, 1])->getValue());
        }

        $colMap = [
            'name' => null, 'category' => null, 'brand' => null,
            'unit_buy' => null, 'unit_sale' => null, 'barcode' => null,
            'cost_price' => null, 'stock_alert' => null,
            'tax_percent' => null, 'pieces_per_package' => null,
            'opening_stock' => null,
        ];

        $knownHeaders = [
            'الاسم' => 'name', 'الفئة' => 'category',
            'العلامة التجارية' => 'brand', 'وحدة الشراء' => 'unit_buy',
            'وحدة البيع' => 'unit_sale', 'الباركود' => 'barcode',
            'سعر الشراء/قطعة' => 'cost_price', 'حد التنبيه' => 'stock_alert',
            'نسبة الضريبة %' => 'tax_percent', 'عدد القطع في الكرتون' => 'pieces_per_package',
            'الكمية الأولية' => 'opening_stock',
        ];

        $clientCategories = ClientCategory::all();
        $ccColumnMap = [];

        foreach ($headers as $colIdx => $header) {
            if (isset($knownHeaders[$header])) {
                $colMap[$knownHeaders[$header]] = $colIdx;
            } else {
                foreach ($clientCategories as $cc) {
                    if ($header === 'سعر ' . $cc->name) {
                        $ccColumnMap[$colIdx] = $cc->id;
                        break;
                    }
                }
            }
        }

        return [$sheet, $colMap, $ccColumnMap, $highestRow, $clientCategories];
    }

    /**
     * Build lookup maps for name → ID resolution.
     */
    private function buildLookupMaps(): array
    {
        $categoryMap = Category::all()->mapWithKeys(fn($c) => [mb_strtolower($c->name) => $c->id])->toArray();
        $brandMap = Brand::all()->mapWithKeys(fn($b) => [mb_strtolower($b->name) => $b->id])->toArray();
        $unitMap = [];
        foreach (Unit::all() as $unit) {
            $unitMap[mb_strtolower($unit->name)] = $unit->id;
            $unitMap[mb_strtolower($unit->short_name)] = $unit->id;
        }
        return [$categoryMap, $brandMap, $unitMap];
    }

    /**
     * Preview import: parse Excel and return per-cell validation
     */
    public function previewImport(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
        ]);

        try {
            [$sheet, $colMap, $ccColumnMap, $highestRow, $clientCategories] = $this->parseExcelFile(
                $request->file('file')->getRealPath()
            );
        } catch (\Exception $e) {
            return response()->json(['message' => 'لا يمكن قراءة الملف. تأكد من أنه ملف Excel صالح'], 422);
        }

        if ($colMap['name'] === null) {
            return response()->json(['message' => 'عمود "الاسم" مطلوب ولم يتم العثور عليه'], 422);
        }

        [$categoryMap, $brandMap, $unitMap] = $this->buildLookupMaps();
        $existingBarcodes = Product::whereNotNull('barcode')->pluck('barcode')->flip()->toArray();
        $seenBarcodes = []; // track within-file duplicates

        // Build columns metadata
        $columns = [
            ['key' => 'name', 'label' => 'الاسم', 'type' => 'text', 'required' => true],
            ['key' => 'category', 'label' => 'الفئة', 'type' => 'select', 'required' => false],
            ['key' => 'brand', 'label' => 'العلامة التجارية', 'type' => 'select', 'required' => false],
            ['key' => 'unit_buy', 'label' => 'وحدة الشراء', 'type' => 'select', 'required' => false],
            ['key' => 'unit_sale', 'label' => 'وحدة البيع', 'type' => 'select', 'required' => false],
            ['key' => 'barcode', 'label' => 'الباركود', 'type' => 'text', 'required' => false],
            ['key' => 'cost_price', 'label' => 'سعر الشراء/قطعة', 'type' => 'number', 'required' => true],
            ['key' => 'stock_alert', 'label' => 'حد التنبيه', 'type' => 'number', 'required' => false],
            ['key' => 'tax_percent', 'label' => 'نسبة الضريبة %', 'type' => 'number', 'required' => false],
            ['key' => 'pieces_per_package', 'label' => 'عدد القطع في الكرتون', 'type' => 'number', 'required' => false],
            ['key' => 'opening_stock', 'label' => 'الكمية الأولية', 'type' => 'number', 'required' => false],
        ];
        foreach ($ccColumnMap as $colIdx => $ccId) {
            $cc = $clientCategories->firstWhere('id', $ccId);
            $columns[] = [
                'key' => 'price_' . $ccId,
                'label' => 'سعر ' . ($cc ? $cc->name : $ccId),
                'type' => 'number',
                'required' => false,
                'client_category_id' => $ccId,
            ];
        }

        $rows = [];
        $validCount = 0;
        $invalidCount = 0;

        for ($row = 2; $row <= $highestRow; $row++) {
            $getValue = function ($key) use ($sheet, $row, $colMap) {
                if ($colMap[$key] === null) return '';
                return trim((string) $sheet->getCell([$colMap[$key], $row])->getValue());
            };

            $name = $getValue('name');
            if (empty($name)) continue;
            if (mb_strpos($name, 'مثال:') === 0 || mb_strpos($name, '⬆') === 0) continue;

            $cells = [];
            $rowValid = true;

            // Name
            $cells['name'] = ['value' => $name, 'isValid' => true, 'error' => null];

            // Category
            $catVal = $getValue('category');
            $catId = $catVal !== '' ? ($categoryMap[mb_strtolower($catVal)] ?? null) : null;
            $catValid = $catVal === '' || $catId !== null;
            if (!$catValid) $rowValid = false;
            $cells['category'] = [
                'value' => $catVal, 'resolvedId' => $catId, 'isValid' => $catValid,
                'error' => $catValid ? null : 'الفئة غير موجودة',
            ];

            // Brand
            $brandVal = $getValue('brand');
            $brandId = $brandVal !== '' ? ($brandMap[mb_strtolower($brandVal)] ?? null) : null;
            $brandValid = $brandVal === '' || $brandId !== null;
            if (!$brandValid) $rowValid = false;
            $cells['brand'] = [
                'value' => $brandVal, 'resolvedId' => $brandId, 'isValid' => $brandValid,
                'error' => $brandValid ? null : 'العلامة التجارية غير موجودة',
            ];

            // Unit buy
            $ubVal = $getValue('unit_buy');
            $ubId = $ubVal !== '' ? ($unitMap[mb_strtolower($ubVal)] ?? null) : null;
            $ubValid = $ubVal === '' || $ubId !== null;
            if (!$ubValid) $rowValid = false;
            $cells['unit_buy'] = [
                'value' => $ubVal, 'resolvedId' => $ubId, 'isValid' => $ubValid,
                'error' => $ubValid ? null : 'وحدة الشراء غير موجودة',
            ];

            // Unit sale
            $usVal = $getValue('unit_sale');
            $usId = $usVal !== '' ? ($unitMap[mb_strtolower($usVal)] ?? null) : null;
            $usValid = $usVal === '' || $usId !== null;
            if (!$usValid) $rowValid = false;
            $cells['unit_sale'] = [
                'value' => $usVal, 'resolvedId' => $usId, 'isValid' => $usValid,
                'error' => $usValid ? null : 'وحدة البيع غير موجودة',
            ];

            // Barcode
            $barcodeVal = $getValue('barcode');
            $barcodeError = null;
            if ($barcodeVal !== '') {
                if (isset($existingBarcodes[$barcodeVal])) {
                    $barcodeError = 'الباركود موجود مسبقاً في قاعدة البيانات';
                } elseif (isset($seenBarcodes[$barcodeVal])) {
                    $barcodeError = 'الباركود مكرر في الملف';
                } else {
                    $seenBarcodes[$barcodeVal] = $row;
                }
            }
            $barcodeValid = $barcodeError === null;
            if (!$barcodeValid) $rowValid = false;
            $cells['barcode'] = [
                'value' => $barcodeVal, 'isValid' => $barcodeValid, 'error' => $barcodeError,
            ];

            // Cost price
            $cpVal = $getValue('cost_price');
            $cpValid = $cpVal !== '' && is_numeric($cpVal) && $cpVal >= 0;
            if (!$cpValid) $rowValid = false;
            $cells['cost_price'] = [
                'value' => $cpVal, 'isValid' => $cpValid,
                'error' => $cpValid ? null : ($cpVal === '' ? 'سعر الشراء مطلوب' : 'سعر الشراء غير صالح'),
            ];

            // Stock alert
            $saVal = $getValue('stock_alert');
            $saValid = $saVal === '' || (is_numeric($saVal) && $saVal >= 0);
            if (!$saValid) $rowValid = false;
            $cells['stock_alert'] = [
                'value' => $saVal, 'isValid' => $saValid,
                'error' => $saValid ? null : 'قيمة غير صالحة',
            ];

            // Tax percent
            $tpVal = $getValue('tax_percent');
            $tpValid = $tpVal === '' || (is_numeric($tpVal) && $tpVal >= 0 && $tpVal <= 100);
            if (!$tpValid) $rowValid = false;
            $cells['tax_percent'] = [
                'value' => $tpVal, 'isValid' => $tpValid,
                'error' => $tpValid ? null : 'النسبة يجب أن تكون بين 0 و 100',
            ];

            // Pieces per package
            $pppVal = $getValue('pieces_per_package');
            $pppValid = $pppVal === '' || (is_numeric($pppVal) && (int) $pppVal >= 1);
            if (!$pppValid) $rowValid = false;
            $cells['pieces_per_package'] = [
                'value' => $pppVal, 'isValid' => $pppValid,
                'error' => $pppValid ? null : 'يجب أن تكون 1 أو أكثر',
            ];

            // Opening stock
            $osVal = $getValue('opening_stock');
            $osValid = $osVal === '' || (is_numeric($osVal) && $osVal >= 0);
            if (!$osValid) $rowValid = false;
            $cells['opening_stock'] = [
                'value' => $osVal, 'isValid' => $osValid,
                'error' => $osValid ? null : 'كمية غير صالحة',
            ];

            // Client category prices
            foreach ($ccColumnMap as $colIdx => $ccId) {
                $priceVal = trim((string) $sheet->getCell([$colIdx, $row])->getValue());
                $priceValid = $priceVal === '' || (is_numeric($priceVal) && $priceVal >= 0);
                if (!$priceValid) $rowValid = false;
                $cells['price_' . $ccId] = [
                    'value' => $priceVal, 'isValid' => $priceValid,
                    'error' => $priceValid ? null : 'سعر غير صالح',
                ];
            }

            $rows[] = ['rowIndex' => $row, 'isValid' => $rowValid, 'cells' => $cells];
            $rowValid ? $validCount++ : $invalidCount++;
        }

        // Reference data for frontend dropdowns
        $referenceData = [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'units' => Unit::orderBy('name')->get(['id', 'name', 'short_name']),
            'clientCategories' => $clientCategories->map(fn($cc) => [
                'id' => $cc->id, 'name' => $cc->name, 'is_default' => $cc->is_default,
            ]),
        ];

        return response()->json([
            'columns' => $columns,
            'rows' => $rows,
            'referenceData' => $referenceData,
            'summary' => [
                'totalRows' => count($rows),
                'validRows' => $validCount,
                'invalidRows' => $invalidCount,
            ],
        ]);
    }

    /**
     * Confirm import: create products from corrected JSON data
     */
    public function confirmImport(Request $request)
    {
        // Check tenant product limit for bulk import
        $tenant = $request->attributes->get('tenant');
        if ($tenant) {
            $currentCount = Product::count();
            $importCount = is_array($request->rows) ? count($request->rows) : 0;
            if (($currentCount + $importCount) > $tenant->product_limit) {
                $remaining = max(0, $tenant->product_limit - $currentCount);
                return response()->json([
                    'message' => "لا يمكن استيراد {$importCount} منتج. الحد الأقصى لخطتك {$tenant->product_limit} منتج، المتبقي {$remaining} منتج. قم بترقية خطتك.",
                    'limit' => $tenant->product_limit,
                    'current' => $currentCount,
                    'remaining' => $remaining,
                    'plan' => $tenant->plan,
                ], 403);
            }
        }

        $request->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'required|string|max:255',
            'rows.*.category_id' => 'nullable|integer',
            'rows.*.brand_id' => 'nullable|integer',
            'rows.*.unit_buy_id' => 'nullable|integer',
            'rows.*.unit_sale_id' => 'nullable|integer',
            'rows.*.barcode' => 'nullable|string',
            'rows.*.cost_price' => 'required|numeric|min:0',
            'rows.*.stock_alert' => 'nullable|integer|min:0',
            'rows.*.tax_percent' => 'nullable|numeric|min:0|max:100',
            'rows.*.pieces_per_package' => 'nullable|integer|min:1',
            'rows.*.opening_stock' => 'nullable|integer|min:0',
            'rows.*.warehouse_id' => 'nullable|integer',
            'rows.*.category_prices' => 'nullable|array',
            'rows.*.category_prices.*.client_category_id' => 'required|integer',
            'rows.*.category_prices.*.price' => 'required|numeric|min:0',
        ]);

        $defaultCategoryId = ClientCategory::where('is_default', true)->value('id');
        $existingBarcodes = Product::whereNotNull('barcode')->pluck('barcode')->flip()->toArray();

        $created = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($request->rows as $index => $row) {
                $barcode = $row['barcode'] ?? '';
                if ($barcode !== '' && isset($existingBarcodes[$barcode])) {
                    $errors[] = ['row' => $index + 1, 'message' => "الباركود مكرر: {$barcode}"];
                    continue;
                }
                if ($barcode === '') {
                    $barcode = Product::generateBarcode();
                }

                $openingStock = (int) ($row['opening_stock'] ?? 0);

                $product = Product::create([
                    'name' => $row['name'],
                    'category_id' => $row['category_id'] ?? null,
                    'brand_id' => $row['brand_id'] ?? null,
                    'unit_buy_id' => $row['unit_buy_id'] ?? null,
                    'unit_sale_id' => $row['unit_sale_id'] ?? null,
                    'barcode' => $barcode,
                    'cost_price' => (float) $row['cost_price'],
                    'retail_price' => 0,
                    'wholesale_price' => 0,
                    'min_selling_price' => 0,
                    'stock_alert' => $row['stock_alert'] ?? 0,
                    'tax_percent' => $row['tax_percent'] ?? 0,
                    'pieces_per_package' => $row['pieces_per_package'] ?? 1,
                    'opening_stock' => $openingStock,
                    'is_active' => true,
                ]);

                // Create stock record if opening stock > 0
                if ($openingStock > 0) {
                    $warehouseId = $row['warehouse_id'] ?? Warehouse::where('is_main', true)->value('id') ?? 1;
                    Stock::create([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                        'quantity' => $openingStock,
                    ]);
                }

                $existingBarcodes[$barcode] = true;

                if (!empty($row['category_prices'])) {
                    foreach ($row['category_prices'] as $cp) {
                        if ($cp['price'] > 0) {
                            ProductCategoryPrice::create([
                                'product_id' => $product->id,
                                'client_category_id' => $cp['client_category_id'],
                                'price' => (float) $cp['price'],
                            ]);
                            if ($defaultCategoryId && (int) $cp['client_category_id'] === $defaultCategoryId) {
                                $product->update(['retail_price' => (float) $cp['price']]);
                            }
                        }
                    }
                }

                $created++;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'خطأ أثناء الاستيراد: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'created' => $created,
            'errors' => $errors,
            'message' => "تم استيراد {$created} منتج بنجاح" . (count($errors) > 0 ? ' مع ' . count($errors) . ' أخطاء' : ''),
        ]);
    }
}
