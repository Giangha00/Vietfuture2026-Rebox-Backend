<?php

namespace App\Console\Commands;

use App\Models\AiTrainingSample;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportAiTrainingSamples extends Command
{
    protected $signature = 'ai:export-training-samples
                            {--labeled-only : Only samples with admin_label}
                            {--path=ai-exports/samples.jsonl : Path under the local disk (storage/app/private)}
                            {--copy-to= : Optional path to also copy the JSONL (e.g. ../rebox-ai/data/samples.jsonl)}';

    protected $description = 'Export AI training samples to JSONL for rebox-ai LoRA fine-tune';

    public function handle(): int
    {
        $query = AiTrainingSample::query()->latest('id');
        if ($this->option('labeled-only')) {
            $query->whereNotNull('admin_label');
        }

        $samples = $query->get();
        if ($samples->isEmpty()) {
            $this->warn('No training samples found.');

            return self::SUCCESS;
        }

        $path = ltrim((string) $this->option('path'), '/');
        $lines = [];
        foreach ($samples as $sample) {
            $target = $sample->user_final ?: $sample->ai_draft;
            $lines[] = json_encode([
                'id' => $sample->id,
                'product_id' => $sample->product_id,
                'images' => $sample->image_urls,
                'image_urls' => $sample->image_urls,
                'category_slug' => $sample->category_slug,
                'ai_draft' => $sample->ai_draft,
                'user_final' => $sample->user_final,
                'target' => $target,
                'admin_label' => $sample->admin_label,
                'rejection_reason' => $sample->rejection_reason,
                'model_version' => $sample->model_version,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $body = implode("\n", $lines)."\n";
        Storage::disk('local')->put($path, $body);

        // Laravel local disk root is storage/app/private (not storage/app).
        $full = Storage::disk('local')->path($path);
        $this->info("Exported {$samples->count()} samples to {$full}");

        $copyTo = trim((string) $this->option('copy-to'));
        if ($copyTo !== '') {
            $dest = str_starts_with($copyTo, '/')
                ? $copyTo
                : base_path($copyTo);
            $dir = dirname($dest);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dest, $body);
            $this->info("Also copied to {$dest}");
        }

        return self::SUCCESS;
    }
}
