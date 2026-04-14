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
        /** @var array<string,mixed> $content */
        $content = (array) config('docs.content', []);

        $replacements = [
            ':app_url' => (string) config('app.url', 'http://localhost'),
            ':rate_limit' => (string) config('ocr.rate_limit_per_minute', 30),
            ':max_file_size_kb' => (string) config('ocr.max_file_size_kb', 5120),
            ':ocr_provider' => (string) config('ocr.provider', 'gemini'),
        ];

        return $this->replacePlaceholders($content, $replacements);
    }

    /**
     * @param array<string,mixed>|list<mixed>|string $value
     * @param array<string,string> $replacements
     * @return array<string,mixed>|list<mixed>|string
     */
    private function replacePlaceholders(array|string $value, array $replacements): array|string
    {
        if (is_string($value)) {
            return strtr($value, $replacements);
        }

        $output = [];
        foreach ($value as $key => $item) {
            $output[$key] = is_array($item) || is_string($item)
                ? $this->replacePlaceholders($item, $replacements)
                : $item;
        }

        return $output;
    }
}
