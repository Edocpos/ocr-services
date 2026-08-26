<?php

namespace App\Services\StatutoryOcr;

use App\Exceptions\StatutoryReceiptException;
use App\Support\OcrDocumentMime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StatutoryOcrPipeline
{
    public const SCHEMES = ['epf', 'socso', 'eis', 'pcb', 'hrdc'];

    public function __construct(
        private readonly ReceiptTextExtractor $textExtractor,
        private readonly LabeledReceiptReader $fieldReader,
        private readonly SchemeDetector $schemeDetector,
        private readonly SchemeParserFactory $parserFactory,
        private readonly ReceiptConfidenceEvaluator $confidenceEvaluator,
        private readonly ReceiptResponseBuilder $responseBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function process(UploadedFile $file, string $scheme, string $requestId): array
    {
        $scheme = strtolower($scheme);
        $tempPath = $this->storeSecurely($file);

        try {
            $content = file_get_contents($tempPath);
            if ($content === false || $content === '') {
                throw StatutoryReceiptException::unreadable();
            }

            $mime = OcrDocumentMime::identify($content);
            $bytes = strlen($content);

            $extractedText = $this->textExtractor->extract($content);
            $text = $extractedText['text'];
            $source = $extractedText['source'];

            if (trim($text) === '') {
                Log::info('statutory_ocr_unreadable', [
                    'request_id' => $requestId,
                    'scheme' => $scheme,
                    'mime' => $mime,
                    'bytes' => $bytes,
                    'source' => $source,
                ]);

                throw StatutoryReceiptException::unreadable();
            }

            $detected = $this->schemeDetector->detect($text);
            if ($detected !== null && $detected !== $scheme) {
                Log::info('statutory_ocr_scheme_mismatch', [
                    'request_id' => $requestId,
                    'expected_scheme' => $scheme,
                    'detected_scheme' => $detected,
                    'mime' => $mime,
                    'bytes' => $bytes,
                    'source' => $source,
                ]);

                throw StatutoryReceiptException::schemeMismatch($detected, $scheme);
            }

            $fields = $this->fieldReader->read($text);
            $parsed = $this->parserFactory->make($scheme)->parse($fields, $text, $source);
            $confidence = $this->confidenceEvaluator->evaluate(
                $parsed['extracted'],
                $parsed['field_confidence'],
                $parsed['requires_manual_review'],
                $extractedText['overall_confidence'],
                $source,
            );

            if ($confidence['unreadable'] === true) {
                throw StatutoryReceiptException::unreadable();
            }

            Log::info('statutory_ocr_processed', [
                'request_id' => $requestId,
                'scheme' => $scheme,
                'mime' => $mime,
                'bytes' => $bytes,
                'source' => $source,
                'confidence' => $confidence['overall'],
                'requires_manual_review' => $confidence['requires_manual_review'],
            ]);

            return $this->responseBuilder->success(
                $scheme,
                $parsed['extracted'],
                $confidence['field_confidence'],
                $confidence['overall'],
                $confidence['requires_manual_review'],
                $parsed['warnings'],
                $requestId,
                $source,
            );
        } catch (StatutoryReceiptException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('statutory_ocr_failed', [
                'request_id' => $requestId,
                'scheme' => $scheme,
                'exception' => $exception::class,
            ]);

            throw StatutoryReceiptException::unavailable();
        } finally {
            $this->deleteQuietly($tempPath);
        }
    }

    private function storeSecurely(UploadedFile $file): string
    {
        $directory = storage_path('app/private/ocr-tmp');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw StatutoryReceiptException::unavailable();
        }

        $path = $directory.DIRECTORY_SEPARATOR.(string) Str::uuid();
        $realPath = $file->getRealPath();

        if ($realPath === false || ! @copy($realPath, $path)) {
            throw StatutoryReceiptException::unreadable();
        }

        @chmod($path, 0600);

        return $path;
    }

    private function deleteQuietly(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
