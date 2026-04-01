<?php

namespace hexa_package_gptzero\Http\Controllers;

use hexa_core\Http\Controllers\Controller;
use hexa_core\Models\Setting;
use hexa_package_gptzero\Services\GptZeroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GptZeroController — settings + raw test page + detect endpoint.
 */
class GptZeroController extends Controller
{
    protected GptZeroService $service;

    /**
     * @param GptZeroService $service
     */
    public function __construct(GptZeroService $service)
    {
        $this->service = $service;
    }

    /**
     * Settings page.
     *
     * @return View
     */
    public function settings(): View
    {
        return view('gptzero::settings.index');
    }

    /**
     * Save settings.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => 'nullable|string|max:500',
            'enabled' => 'nullable|boolean',
            'debug_mode' => 'nullable|boolean',
        ]);

        if (isset($validated['api_key']) && !empty($validated['api_key'])) {
            Setting::setValue('gptzero_api_key', $validated['api_key']);
        }
        Setting::setValue('gptzero_enabled', $validated['enabled'] ?? true);
        Setting::setValue('gptzero_debug_mode', $validated['debug_mode'] ?? false);

        hexaLog('gptzero', 'settings_updated', 'GPTZero settings updated');

        return response()->json(['success' => true, 'message' => 'Settings saved.']);
    }

    /**
     * Test API connection.
     *
     * @return JsonResponse
     */
    public function testConnection(): JsonResponse
    {
        return response()->json($this->service->testConnection());
    }

    /**
     * Raw test page.
     *
     * @return View
     */
    public function raw(): View
    {
        return view('gptzero::raw.index');
    }

    /**
     * Detect AI content.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function detect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|min:10',
        ]);

        return response()->json($this->service->detect($validated['text']));
    }
}
