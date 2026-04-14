<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiDocsController extends Controller
{
    public function index(Request $request): View
    {
        if (! $this->isUnlocked($request)) {
            return view('docs.login');
        }

        return view('docs.index', [
            'docs' => $this->docsContent(),
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $inputPassword = (string) $request->input('password');
        $actualPassword = (string) config('docs.password', 'EDP2026');

        if (! hash_equals($actualPassword, $inputPassword)) {
            return redirect()
                ->route('docs.index')
                ->withErrors(['password' => 'Invalid documentation password.']);
        }

        $request->session()->put('docs_unlocked', true);
        $request->session()->regenerate();

        return redirect()->route('docs.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('docs_unlocked');

        return redirect()->route('docs.index');
    }

    private function isUnlocked(Request $request): bool
    {
        return (bool) $request->session()->get('docs_unlocked', false);
    }

    /**
     * @return array<string,mixed>
     */
    private function docsContent(): array
    {
        return [
            'title' => 'OCR API Documentation',
            'subtitle' => 'Current module: IC OCR. Add future modules in this same page structure.',
            'version' => 'v1',
            'modules' => [
                [
                    'name' => 'IC OCR',
                    'description' => 'Extract Malaysian IC fields from image and derive deterministic values from IC number.',
                    'endpoint' => [
                        'method' => 'POST',
                        'path' => '/api/ocr/ic',
                        'content_type' => 'multipart/form-data',
                        'rate_limit' => (int) config('ocr.rate_limit_per_minute', 30) . ' requests/minute/IP',
                    ],
                    'request' => [
                        [
                            'field' => 'image',
                            'type' => 'file',
                            'required' => true,
                            'notes' => 'Max ' . (int) config('ocr.max_file_size_kb', 5120) . ' KB',
                        ],
                    ],
                    'response_example' => [
                        'data' => [
                            'extracted' => [
                                'ic_number' => '550106-12-5821',
                                'name' => 'ROWAN SEBASTIAN ATKINSON',
                                'address' => 'GDW KAMPUNG BAYANGAN, 80000 KENINGAU, SABAH',
                            ],
                            'derived' => [
                                'birth_date' => '1955-01-06',
                                'gender' => 'male',
                                'state' => 'Sabah',
                                'district' => null,
                            ],
                        ],
                        'validation' => [
                            'status' => 'ok',
                            'errors' => [],
                            'warnings' => [],
                        ],
                        'confidence' => [
                            'overall' => 0.95,
                            'ic_number' => 1.0,
                            'name' => 1.0,
                            'address' => 1.0,
                            'birth_date' => 1.0,
                            'gender' => 1.0,
                            'state' => 1.0,
                            'district' => 1.0,
                        ],
                        'usage' => [
                            'provider' => 'gemini',
                            'unit' => 'token',
                            'quantity' => 1018,
                            'currency' => 'MYR',
                            'price_per_unit_rm' => 0.0000017,
                            'estimated_cost_rm' => 0.001733,
                            'is_estimated' => true,
                            'prompt_tokens' => 654,
                            'completion_tokens' => 69,
                            'total_tokens' => 1018,
                        ],
                        'meta' => [
                            'provider' => 'gemini',
                            'stateless' => true,
                            'request_id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                        ],
                    ],
                    'errors' => [
                        ['code' => 'low_confidence_image', 'http' => 200, 'note' => 'Image accepted but result blocked by confidence gate.'],
                        ['code' => 'ocr_upstream_timeout', 'http' => 504, 'note' => 'Upstream provider timeout.'],
                        ['code' => 'ocr_image_too_large_for_provider', 'http' => 422, 'note' => 'Image too large for provider processing.'],
                        ['code' => 'ocr_processing_error', 'http' => 502, 'note' => 'Unhandled OCR pipeline failure.'],
                    ],
                ],
            ],
        ];
    }
}
