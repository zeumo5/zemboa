<?php
namespace App\Http\Controllers;


use App\Actions\StoreSettings\UpdateStoreSettings;
use App\Http\Requests\UpdateStoreSettingRequest;
use App\Models\StoreSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StoreSettingController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize(
            'viewAny',
            StoreSetting::class
        );

        $settings = StoreSetting::query()->first();

        if ($settings !== null) {
            Gate::authorize('view', $settings);
        }

        return response()->json([
            'data' => $settings,
            'configured' => $settings !== null,
        ]);
    }

    public function update(
        UpdateStoreSettingRequest $request,
        UpdateStoreSettings $updateStoreSettings
    ): JsonResponse {
        $settings = $updateStoreSettings->execute(
            $request->validated()
        );

        return response()->json([
            'message' => 'Paramètres enregistrés avec succès.',
            'data' => $settings,
        ]);
    }
}
