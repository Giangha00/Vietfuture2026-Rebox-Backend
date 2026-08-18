<?php

namespace App\Console\Commands;

use App\Models\AiTrainingSample;
use App\Support\Ai\TrainingImageResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExportAiTrainingSamples extends Command
{
    protected $signature = 'ai:export-training-samples
                            {--approved-only : Gold set only (admin_label=approved + user_final). Default when --dataset-dir is set}
                            {--labeled-only : Any sample with admin_label (legacy)}
                            {--incremental : Skip rows already marked exported_at}
                            {--mark-exported : Set exported_at after a successful dataset export}
                            {--val-ratio= : Validation split ratio (default from config)}
                            {--min-samples=0 : Abort dataset export if fewer gold samples than this}
                            {--dataset-dir= : Write versioned export under this dir (train/val/images/manifest)}
                            {--path=ai-exports/samples.jsonl : Legacy single JSONL under local disk}
                            {--copy-to= : Legacy: also copy the single JSONL to this path}
                            {--include-rejected : Also write rejected.jsonl beside the gold set}';

    protected $description = 'Export AI training samples to versioned JSONL (+ images) for rebox-ai LoRA';

    public function handle(TrainingImageResolver $images): int
    {
        $datasetDir = trim((string) $this->option('dataset-dir'));
        if ($datasetDir === '') {
            $datasetDir = '';
        }

        $useDataset = $datasetDir !== '';
        $approvedOnly = (bool) $this->option('approved-only') || $useDataset;
        if ($this->option('labeled-only') && ! $this->option('approved-only') && ! $useDataset) {
            $approvedOnly = false;
        }

        $query = AiTrainingSample::query()->orderBy('id');

        if ($approvedOnly) {
            $query->where('admin_label', 'approved')->whereNotNull('user_final');
        } elseif ($this->option('labeled-only')) {
            $query->whereNotNull('admin_label');
        }

        if ($this->option('incremental')) {
            $query->whereNull('exported_at');
        }

        $samples = $query->get();
        if ($samples->isEmpty()) {
            $this->warn('No training samples matched the export filters.');

            return self::SUCCESS;
        }

        if ($useDataset) {
            return $this->exportDataset($samples, $datasetDir, $images);
        }

        return $this->exportLegacyJsonl($samples);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AiTrainingSample>  $samples
     */
    protected function exportDataset($samples, string $datasetDir, TrainingImageResolver $images): int
    {
        $min = (int) $this->option('min-samples');
        if ($min > 0 && $samples->count() < $min) {
            $this->warn("Only {$samples->count()} gold samples (need {$min}). Skipping export.");

            return self::SUCCESS;
        }

        $root = $this->resolvePath($datasetDir);
        $version = now()->format('Ymd_His');
        $exportRoot = $root.DIRECTORY_SEPARATOR.'exports'.DIRECTORY_SEPARATOR.$version;
        $imagesRoot = $exportRoot.DIRECTORY_SEPARATOR.'images';
        if (! is_dir($imagesRoot) && ! mkdir($imagesRoot, 0755, true) && ! is_dir($imagesRoot)) {
            $this->error("Cannot create {$imagesRoot}");

            return self::FAILURE;
        }

        $valRatio = $this->option('val-ratio');
        $valRatio = $valRatio !== null && $valRatio !== ''
            ? (float) $valRatio
            : (float) config('rebox.ai.export_val_ratio', 0.2);
        $valRatio = max(0.0, min(0.5, $valRatio));

        $rows = [];
        $skippedNoImage = 0;
        $skippedNoTarget = 0;

        foreach ($samples as $sample) {
            $target = $this->buildTrainTarget($sample);
            if ($target === null) {
                $skippedNoTarget++;
                continue;
            }

            $resolved = $images->resolveMany($sample->image_urls);
            $localPaths = [];
            $sampleImageDir = $imagesRoot.DIRECTORY_SEPARATOR.(string) $sample->id;
            foreach ($resolved as $i => $ref) {
                $local = $ref['local'];
                if ($local === null || ! is_file($local)) {
                    continue;
                }
                if (! is_dir($sampleImageDir)) {
                    mkdir($sampleImageDir, 0755, true);
                }
                $ext = pathinfo($local, PATHINFO_EXTENSION) ?: 'jpg';
                $destName = $i.'.'.strtolower($ext);
                $dest = $sampleImageDir.DIRECTORY_SEPARATOR.$destName;
                if (! copy($local, $dest)) {
                    continue;
                }
                // Paths relative to rebox-ai/data so training is portable across machines.
                $localPaths[] = 'exports/'.$version.'/images/'.$sample->id.'/'.$destName;
            }

            if ($localPaths === []) {
                $skippedNoImage++;
                continue;
            }

            $rows[] = [
                'id' => $sample->id,
                'product_id' => $sample->product_id,
                'source' => $sample->source ?: 'user',
                'images' => $localPaths,
                'image_urls' => $sample->image_urls,
                'category_slug' => $sample->category_slug,
                'ai_draft' => $sample->ai_draft,
                'user_final' => $sample->user_final,
                'target' => $target,
                'admin_label' => $sample->admin_label,
                'model_version' => $sample->model_version,
            ];
        }

        if ($rows === []) {
            $this->warn('No exportable rows (missing local images or user_final).');
            $this->line("skipped_no_image={$skippedNoImage} skipped_no_target={$skippedNoTarget}");

            return self::SUCCESS;
        }

        shuffle($rows);
        $valCount = (int) floor(count($rows) * $valRatio);
        if (count($rows) >= 5 && $valCount < 1) {
            $valCount = 1;
        }
        $valRows = array_slice($rows, 0, $valCount);
        $trainRows = array_slice($rows, $valCount);
        if ($trainRows === [] && $valRows !== []) {
            $trainRows = $valRows;
            $valRows = [];
        }

        $trainBody = $this->encodeJsonl($trainRows);
        $valBody = $this->encodeJsonl($valRows);
        file_put_contents($exportRoot.DIRECTORY_SEPARATOR.'train.jsonl', $trainBody);
        file_put_contents($exportRoot.DIRECTORY_SEPARATOR.'val.jsonl', $valBody);

        // Stable "current" pointers at dataset root for train scripts.
        file_put_contents($root.DIRECTORY_SEPARATOR.'train.jsonl', $trainBody);
        file_put_contents($root.DIRECTORY_SEPARATOR.'val.jsonl', $valBody);

        $rejectedCount = 0;
        if ($this->option('include-rejected')) {
            $rejected = AiTrainingSample::query()
                ->where('admin_label', 'rejected')
                ->orderBy('id')
                ->get();
            $rejectedRows = [];
            foreach ($rejected as $sample) {
                $rejectedRows[] = [
                    'id' => $sample->id,
                    'product_id' => $sample->product_id,
                    'images' => $sample->image_urls,
                    'category_slug' => $sample->category_slug,
                    'ai_draft' => $sample->ai_draft,
                    'user_final' => $sample->user_final,
                    'admin_label' => $sample->admin_label,
                    'rejection_reason' => $sample->rejection_reason,
                    'model_version' => $sample->model_version,
                    'source' => $sample->source ?: 'user',
                ];
            }
            $rejectedCount = count($rejectedRows);
            $rejectedBody = $this->encodeJsonl($rejectedRows);
            file_put_contents($exportRoot.DIRECTORY_SEPARATOR.'rejected.jsonl', $rejectedBody);
            file_put_contents($root.DIRECTORY_SEPARATOR.'rejected.jsonl', $rejectedBody);
        }

        $manifest = [
            'version' => $version,
            'created_at' => now()->toIso8601String(),
            'gold_filter' => 'admin_label=approved AND user_final IS NOT NULL',
            'train_count' => count($trainRows),
            'val_count' => count($valRows),
            'rejected_count' => $rejectedCount,
            'skipped_no_image' => $skippedNoImage,
            'skipped_no_target' => $skippedNoTarget,
            'val_ratio' => $valRatio,
            'incremental' => (bool) $this->option('incremental'),
            'export_root' => $exportRoot,
            'train_jsonl' => 'train.jsonl',
            'val_jsonl' => 'val.jsonl',
            'category_counts' => $this->categoryCounts($rows),
        ];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        file_put_contents($exportRoot.DIRECTORY_SEPARATOR.'manifest.json', $manifestJson);
        file_put_contents($root.DIRECTORY_SEPARATOR.'manifest.json', $manifestJson);

        if ($this->option('mark-exported')) {
            $ids = array_column($rows, 'id');
            AiTrainingSample::query()->whereIn('id', $ids)->update(['exported_at' => now()]);
        }

        $this->info("Exported dataset version {$version}");
        $this->line("  train={$manifest['train_count']} val={$manifest['val_count']} → {$exportRoot}");
        $this->line("  current pointers → {$root}/train.jsonl , {$root}/val.jsonl");
        if ($skippedNoImage || $skippedNoTarget) {
            $this->warn("  skipped_no_image={$skippedNoImage} skipped_no_target={$skippedNoTarget}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AiTrainingSample>  $samples
     */
    protected function exportLegacyJsonl($samples): int
    {
        $path = ltrim((string) $this->option('path'), '/');
        $lines = [];
        foreach ($samples as $sample) {
            $target = $sample->user_final ?: $sample->ai_draft;
            $lines[] = json_encode([
                'id' => $sample->id,
                'product_id' => $sample->product_id,
                'source' => $sample->source ?: 'user',
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
        $full = Storage::disk('local')->path($path);
        $this->info("Exported {$samples->count()} samples to {$full}");

        $copyTo = trim((string) $this->option('copy-to'));
        if ($copyTo !== '') {
            $dest = $this->resolvePath($copyTo);
            $dir = dirname($dest);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dest, $body);
            $this->info("Also copied to {$dest}");
        }

        return self::SUCCESS;
    }

    /**
     * Gold label shaped like the VLM JSON schema (snake_case) used at inference.
     *
     * @return array<string, mixed>|null
     */
    protected function buildTrainTarget(AiTrainingSample $sample): ?array
    {
        $final = $sample->user_final;
        if (! is_array($final) || $final === []) {
            return null;
        }

        $slug = strtolower(trim((string) ($final['category_slug'] ?? $sample->category_slug ?? '')));
        if ($slug === '') {
            return null;
        }

        $price = $final['price'] ?? $final['suggested_price'] ?? null;
        $suggested = is_numeric($price) ? round((float) $price, 2) : null;

        $condition = (string) ($final['condition'] ?? 'Good');
        if (! in_array($condition, ['Like New', 'Good', 'Fair'], true)) {
            $condition = 'Good';
        }

        $attributes = is_array($final['attributes'] ?? null) ? $final['attributes'] : [];

        return [
            'category_slug' => $slug,
            'title' => mb_substr(trim((string) ($final['title'] ?? 'Used product')), 0, 200),
            'brand' => mb_substr(trim((string) ($final['brand'] ?? 'Generic')) ?: 'Generic', 0, 80),
            'description' => mb_substr(trim((string) ($final['description'] ?? '')), 0, 2000),
            'condition' => $condition,
            'attributes' => $attributes,
            'suggested_price' => $suggested,
            'confidence' => 0.85,
            'flags' => [],
            'risk_score' => 'low',
            'notes_for_admin' => '',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function encodeJsonl(array $rows): string
    {
        if ($rows === []) {
            return "";
        }
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    protected function categoryCounts(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $slug = (string) ($row['category_slug']
                ?? ($row['target']['category_slug'] ?? 'unknown'));
            $counts[$slug] = ($counts[$slug] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    protected function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }
}
