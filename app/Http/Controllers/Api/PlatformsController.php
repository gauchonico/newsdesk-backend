<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PlatformsController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            // Fetch records directly from the platforms table
            $platforms = Platform::all()->map(function ($platform) {
                return [
                    'id'          => (int) $platform->id,
                    'name'        => (string) $platform->name,
                    'type'        => (string) $platform->type,
                    // Fallback to a preset color string if null in legacy rows
                    'color'       => (string) ($platform->hsl_color ?? '142 60% 45%'), 
                    'description' => $platform->description,
                    'active'      => (bool) $platform->is_active, 
                ];
            });

            return response()->json(['data' => $platforms]);

        } catch (\Exception $e) {
            // If it crashes, this safely outputs the error directly to your network tab preview
            return response()->json([
                'error'   => true,
                'message' => $e->getMessage(),
                'line'    => $e->getLine()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'        => 'required|string|max:255',
                'type'        => 'required|string|in:TV,Radio,Web,Social',
                'color'       => 'required|string|max:50', // Expects HSL string e.g., "142 60% 45%"
                'description' => 'nullable|string',
                'active'      => 'boolean'
            ]);

            $platform = Platform::create([
                'name'        => $validated['name'],
                'type'        => $validated['type'],
                'hsl_color'   => $validated['color'], // Map frontend 'color' to database 'hsl_color'
                'description' => $validated['description'] ?? null,
                'is_active'   => $validated['active'] ?? true,
            ]);

            // Return the wrapped data signature expected by your frontend's: response.data.data
            return response()->json([
                'data' => [
                    'id'          => (int) $platform->id,
                    'name'        => $platform->name,
                    'type'        => $platform->type,
                    'color'       => $platform->hsl_color,
                    'description' => $platform->description,
                    'active'      => (bool) $platform->is_active,
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create platform',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, Platform $platform): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name'        => 'sometimes|required|string|max:255',
                'type'        => 'sometimes|required|string|in:TV,Radio,Web,Social',
                'color'       => 'sometimes|required|string|max:50',
                'description' => 'nullable|string',
                'active'      => 'sometimes|required|boolean'
            ]);

            // Update parameters if passed in payload
            if (isset($validated['name']))        $platform->name = $validated['name'];
            if (isset($validated['type']))        $platform->type = $validated['type'];
            if (isset($validated['color']))       $platform->hsl_color = $validated['color'];
            if (isset($validated['description'])) $platform->description = $validated['description'];
            if (isset($validated['active']))      $platform->is_active = $validated['active'];

            $platform->save();

            // Return the updated item wrapped inside 'data'
            return response()->json([
                'data' => [
                    'id'          => (int) $platform->id,
                    'name'        => $platform->name,
                    'type'        => $platform->type,
                    'color'       => $platform->hsl_color,
                    'description' => $platform->description,
                    'active'      => (bool) $platform->is_active,
                ]
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update platform',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Platform $platform): JsonResponse
    {
        $platform->delete();
        return response()->json(['success' => true, 'message' => 'Platform deleted successfully']);
    }
}