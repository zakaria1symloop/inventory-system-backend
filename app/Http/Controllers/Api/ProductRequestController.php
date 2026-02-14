<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductRequest;
use App\Models\ProductRequestItem;
use App\Models\VanSession;
use App\Models\VanSessionItem;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductRequestController extends Controller
{
    /**
     * List all product requests (admin)
     */
    public function index(Request $request)
    {
        $query = ProductRequest::with(['requester', 'vanSession', 'warehouse', 'processor', 'items.product'])
            ->latest();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('van_session_id')) {
            $query->where('van_session_id', $request->van_session_id);
        }

        $perPage = $request->input('per_page', 20);
        return response()->json($query->paginate($perPage));
    }

    /**
     * Get requests for the current user's active session (driver/cashvan)
     */
    public function myRequests(Request $request)
    {
        $user = $request->user();
        $session = VanSession::where('livreur_id', $user->id)
            ->whereIn('status', ['preparing', 'active'])
            ->latest()
            ->first();

        if (!$session) {
            return response()->json([]);
        }

        $requests = ProductRequest::with(['items.product', 'processor'])
            ->where('van_session_id', $session->id)
            ->where('requested_by', $user->id)
            ->latest()
            ->get();

        return response()->json($requests);
    }

    /**
     * Create a product request (driver/cashvan)
     */
    public function store(Request $request)
    {
        $request->validate([
            'van_session_id' => 'required|exists:van_sessions,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.notes' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $session = VanSession::findOrFail($request->van_session_id);

        // Must be the session's driver
        if ($session->livreur_id !== $request->user()->id) {
            return response()->json(['message' => 'غير مصرح لك بطلب منتجات لهذه الجلسة'], 403);
        }

        // Session must be active
        if ($session->status !== 'active') {
            return response()->json(['message' => 'الجلسة غير نشطة'], 422);
        }

        $productRequest = DB::transaction(function () use ($request, $session) {
            $pr = ProductRequest::create([
                'van_session_id' => $session->id,
                'requested_by' => $request->user()->id,
                'warehouse_id' => $session->warehouse_id,
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $item) {
                ProductRequestItem::create([
                    'product_request_id' => $pr->id,
                    'product_id' => $item['product_id'],
                    'quantity_requested' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $pr;
        });

        return response()->json(
            $productRequest->load(['items.product', 'requester', 'vanSession']),
            201
        );
    }

    /**
     * Show a single request
     */
    public function show(ProductRequest $productRequest)
    {
        return response()->json(
            $productRequest->load(['requester', 'vanSession.livreur', 'warehouse', 'processor', 'items.product'])
        );
    }

    /**
     * Approve a request (admin)
     */
    public function approve(Request $request, ProductRequest $productRequest)
    {
        if ($productRequest->status !== 'pending') {
            return response()->json(['message' => 'لا يمكن الموافقة على هذا الطلب'], 422);
        }

        $request->validate([
            'items' => 'sometimes|array',
            'items.*.id' => 'required_with:items|exists:product_request_items,id',
            'items.*.quantity_approved' => 'required_with:items|numeric|min:0',
            'admin_notes' => 'nullable|string',
        ]);

        $session = $productRequest->vanSession;
        if ($session->status !== 'active') {
            return response()->json(['message' => 'الجلسة غير نشطة'], 422);
        }

        DB::transaction(function () use ($request, $productRequest, $session) {
            // Update approved quantities
            if ($request->has('items')) {
                foreach ($request->items as $itemData) {
                    ProductRequestItem::where('id', $itemData['id'])
                        ->update(['quantity_approved' => $itemData['quantity_approved']]);
                }
            } else {
                // Approve all with requested quantities
                foreach ($productRequest->items as $item) {
                    $item->update(['quantity_approved' => $item->quantity_requested]);
                }
            }

            $productRequest->update([
                'status' => 'approved',
                'admin_notes' => $request->admin_notes,
                'processed_by' => $request->user()->id,
                'processed_at' => now(),
            ]);
        });

        return response()->json(
            $productRequest->fresh()->load(['items.product', 'requester', 'processor'])
        );
    }

    /**
     * Reject a request (admin)
     */
    public function reject(Request $request, ProductRequest $productRequest)
    {
        if ($productRequest->status !== 'pending') {
            return response()->json(['message' => 'لا يمكن رفض هذا الطلب'], 422);
        }

        $productRequest->update([
            'status' => 'rejected',
            'admin_notes' => $request->admin_notes,
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        return response()->json(
            $productRequest->load(['items.product', 'requester', 'processor'])
        );
    }

    /**
     * Fulfill an approved request - deduct stock and add to van session (admin)
     */
    public function fulfill(Request $request, ProductRequest $productRequest)
    {
        if ($productRequest->status !== 'approved') {
            return response()->json(['message' => 'يجب الموافقة على الطلب أولاً'], 422);
        }

        $session = $productRequest->vanSession;
        if ($session->status !== 'active') {
            return response()->json(['message' => 'الجلسة غير نشطة'], 422);
        }

        $errors = [];

        DB::transaction(function () use ($productRequest, $session, &$errors) {
            $productRequest->load('items');

            foreach ($productRequest->items as $item) {
                if ($item->quantity_approved <= 0) continue;

                $product = Product::findOrFail($item->product_id);

                // Check warehouse stock
                $stock = Stock::where('product_id', $item->product_id)
                    ->where('warehouse_id', $session->warehouse_id)
                    ->first();

                $available = $stock ? $stock->quantity : 0;

                if ($available < $item->quantity_approved) {
                    $errors[] = "الكمية غير كافية للمنتج {$product->name} (متوفر: {$available})";
                    continue;
                }

                // Deduct from warehouse
                $stock->decrement('quantity', $item->quantity_approved);

                // Add to van session items (or update existing)
                $sessionItem = VanSessionItem::where('van_session_id', $session->id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if ($sessionItem) {
                    $sessionItem->increment('quantity_loaded', $item->quantity_approved);
                } else {
                    VanSessionItem::create([
                        'van_session_id' => $session->id,
                        'product_id' => $item->product_id,
                        'quantity_loaded' => $item->quantity_approved,
                        'quantity_sold' => 0,
                        'quantity_returned' => 0,
                        'unit_cost' => $product->cost_price,
                    ]);
                }
            }

            if (empty($errors)) {
                $productRequest->update(['status' => 'fulfilled']);
                // Recalculate session totals
                $session->updateTotals();
            }
        });

        if (!empty($errors)) {
            return response()->json(['message' => 'بعض المنتجات غير متوفرة', 'errors' => $errors], 422);
        }

        return response()->json(
            $productRequest->fresh()->load(['items.product', 'requester', 'processor'])
        );
    }

    /**
     * Get pending requests count (for admin dashboard badge)
     */
    public function pendingCount()
    {
        return response()->json([
            'count' => ProductRequest::where('status', 'pending')->count(),
        ]);
    }
}
