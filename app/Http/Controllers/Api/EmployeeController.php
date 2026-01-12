<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('position', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $query->withSum('dispenses', 'amount');
        $query->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 15);
        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:255',
            'salary' => 'nullable|numeric|min:0',
            'hire_date' => 'nullable|date',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $employee = Employee::create($validated);

        return response()->json([
            'message' => 'تم إضافة الموظف بنجاح',
            'data' => $employee,
        ], 201);
    }

    public function show(Employee $employee)
    {
        $employee->loadSum('dispenses', 'amount');
        $employee->load(['dispenses' => function ($q) {
            $q->orderBy('date', 'desc')->limit(10);
        }]);

        return response()->json($employee);
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'position' => 'nullable|string|max:255',
            'salary' => 'nullable|numeric|min:0',
            'hire_date' => 'nullable|date',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $employee->update($validated);

        return response()->json([
            'message' => 'تم تحديث بيانات الموظف بنجاح',
            'data' => $employee,
        ]);
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return response()->json([
            'message' => 'تم حذف الموظف بنجاح',
        ]);
    }

    public function toggleActive(Employee $employee)
    {
        $employee->update(['is_active' => !$employee->is_active]);

        return response()->json([
            'message' => 'تم تحديث حالة الموظف',
            'data' => $employee,
        ]);
    }

    public function getActive()
    {
        $employees = Employee::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'position']);

        return response()->json($employees);
    }
}
