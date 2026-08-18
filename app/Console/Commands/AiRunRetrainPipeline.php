<?php

namespace App\Console\Commands;

use App\Models\AiTrainingSample;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class AiRunRetrainPipeline extends Command
{
    protected $signature = 'ai:run-retrain-pipeline
                            {--skip-export : Do not export; only train/eval/promote}
                            {--force : Run even if fewer than export_min_approved unexported samples}';

    protected $description = 'Export gold samples then run rebox-ai auto_retrain (train → eval → promote/rollback)';

    public function handle(): int
    {
        $min = (int) config('rebox.ai.export_min_approved', 20);
        $pending = AiTrainingSample::query()
            ->where('admin_label', 'approved')
            ->whereNotNull('user_final')
            ->whereNull('exported_at')
            ->count();

        if (! $this->option('force') && $pending < $min) {
            $this->info("Skip: only {$pending} unexported approved samples (need {$min}). Use --force to override.");

            return self::SUCCESS;
        }

        $aiRoot = (string) config('rebox.ai.rebox_ai_root');
        $script = rtrim($aiRoot, '/').'/scripts/auto_retrain.sh';
        if (! is_file($script)) {
            $this->error("auto_retrain.sh not found: {$script}");
            $this->line('Set REBOX_AI_ROOT in .env to your rebox-ai directory.');

            return self::FAILURE;
        }

        $cmd = ['bash', $script];
        if ($this->option('skip-export')) {
            $cmd[] = '--skip-export';
        }

        $this->info('Running: '.implode(' ', $cmd));
        $process = new Process($cmd, $aiRoot, [
            'REBOX_BACKEND_DIR' => base_path(),
            'REBOX_AI_DATASET_DIR' => (string) config('rebox.ai.dataset_dir'),
            'REBOX_AI_AUTO_MIN_TRAIN' => (string) config('rebox.ai.auto_min_train', 10),
            'REBOX_AI_AUTO_MIN_CATEGORY' => (string) config('rebox.ai.auto_min_category', 0.40),
            'REBOX_AI_AUTO_MIN_CONDITION' => (string) config('rebox.ai.auto_min_condition', 0.30),
            'REBOX_AI_AUTO_EVAL_LIMIT' => (string) config('rebox.ai.auto_eval_limit', 10),
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
        ]);
        // LoRA on M1 can take a long time.
        $process->setTimeout(60 * 60 * 6);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('auto_retrain failed (exit '.$process->getExitCode().')');

            return self::FAILURE;
        }

        $this->info('Retrain pipeline finished successfully.');

        return self::SUCCESS;
    }
}
