<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispense;
use Illuminate\Http\Request;

class DispenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Dispense::with(['employee:id,name', 'user:id,name']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        if ($request->has('employee_id') && $request->employee_id) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        $query->orderBy('date', 'desc')->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'date' => 'required|date',
            'category' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $validated['user_id'] = auth()->id();

        $dispense = Dispense::create($validated);
        $dispense->load(['employee:id,name', 'user:id,name']);

        return response()->json([
            'message' => 'تم إضافة المصروف بنجاح',
            'data' => $dispense,
        ], 201);
    }

    public function show(Dispense $dispense)
    {
        $dispense->load(['employee', 'user:id,name']);
        return response()->json($dispense);
    }

    public function update(Request $request, Dispense $dispense)
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'date' => 'required|date',
            'category' => 'required|string|max:50',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $dispense->update($validated);
        $dispense->load(['employee:id,name', 'user:id,name']);

        return response()->json([
            'message' => 'تم تحديث المصروف بنجاح',
            'data' => $dispense,
        ]);
    }

    public function destroy(Dispense $dispense)
    {
        $dispense->delete();

        return response()->json([
            'message' => 'تم حذف المصروف بنجاح',
        ]);
    }

    public function getCategories()
    {
        return response()->json(Dispense::getCategories());
    }

    public function summary(Request $request)
    {
        $query = Dispense::query();

        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('date', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('date', '<=', $request->date_to);
        }

        // Total by category
        $byCategory = Dispense::query()
            ->when($request->date_from, fn($q) => $q->whereDate('date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('date', '<=', $request->date_to))
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        // Total by employee
        $byEmployee = Dispense::query()
            ->when($request->date_from, fn($q) => $q->whereDate('date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('date', '<=', $request->date_to))
            ->whereNotNull('employee_id')
            ->with('employee:id,name')
            ->selectRaw('employee_id, SUM(amount) as total')
            ->groupBy('employee_id')
            ->get();

        // Monthly totals
        $monthly = Dispense::query()
            ->when($request->date_from, fn($q) => $q->whereDate('date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('date', '<=', $request->date_to))
            ->selectRaw('DATE_FORMAT(date, "%Y-%m") as month, SUM(amount) as total')
            ->groupBy('month')
            ->orderBy('month', 'desc')
            ->limit(12)
            ->get();

        $total = $query->sum('amount');

        return response()->json([
            'total' => $total,
            'by_category' => $byCategory,
            'by_employee' => $byEmployee,
            'monthly' => $monthly,
        ]);
    }
}
