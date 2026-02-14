<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientCategory;
use Illuminate\Http\Request;

class ClientCategoryController extends Controller
{
    public function index()
    {
        $categories = ClientCategory::withCount('clients')->latest()->get();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ]);

        $category = ClientCategory::create($request->only(['name', 'description', 'is_default']));

        return response()->json($category->loadCount('clients'), 201);
    }

    public function show(ClientCategory $clientCategory)
    {
        return response()->json($clientCategory->loadCount('clients'));
    }

    public function update(Request $request, ClientCategory $clientCategory)
    {
        $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ]);

        $clientCategory->update($request->only(['name', 'description', 'is_default']));

        return response()->json($clientCategory->loadCount('clients'));
    }

    public function destroy(ClientCategory $clientCategory)
    {
        if ($clientCategory->clients()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف الفئة. يوجد عملاء مرتبطون بها'
            ], 400);
        }

        $clientCategory->delete();

        return response()->json(['message' => 'تم حذف الفئة بنجاح']);
    }
}
