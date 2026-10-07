<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoryType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Story types and their review-time targets. Everyone can read; admins and editors manage.
 */
class StoryTypesController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => StoryType::orderBy('sort_order')->orderBy('id')->get()->map(fn ($t) => $this->present($t))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:100', 'unique:story_types,name'],
            'reviewMinutes' => ['required', 'integer', 'between:1,10080'],
        ]);

        $type = StoryType::create([
            'name'           => $data['name'],
            'review_minutes' => $data['reviewMinutes'],
            'sort_order'     => (StoryType::max('sort_order') ?? 0) + 1,
        ]);

        return response()->json(['data' => $this->present($type)], 201);
    }

    public function update(Request $request, StoryType $storyType): JsonResponse
    {
        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100', Rule::unique('story_types', 'name')->ignore($storyType->id)],
            'reviewMinutes' => ['sometimes', 'integer', 'between:1,10080'],
            'isDefault'     => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $storyType) {
            if (! empty($data['isDefault'])) {
                StoryType::where('id', '!=', $storyType->id)->update(['is_default' => false]);
                $storyType->is_default = true;
            }
            if (isset($data['name']))          $storyType->name = $data['name'];
            if (isset($data['reviewMinutes'])) $storyType->review_minutes = $data['reviewMinutes'];
            $storyType->save();
        });

        return response()->json(['data' => $this->present($storyType)]);
    }

    public function destroy(StoryType $storyType): JsonResponse
    {
        if ($storyType->is_default) {
            return response()->json(['message' => 'Make another type the default before deleting this one.'], 422);
        }

        // Stories of this type fall back to the default type (FK is nullOnDelete)
        $storyType->delete();

        return response()->json(['message' => 'Story type deleted.']);
    }

    private function present(StoryType $type): array
    {
        return [
            'id'            => $type->id,
            'name'          => $type->name,
            'reviewMinutes' => $type->review_minutes,
            'isDefault'     => $type->is_default,
        ];
    }
}
