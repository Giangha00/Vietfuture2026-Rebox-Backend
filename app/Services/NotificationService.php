<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

class NotificationService
{
    public function createAndPush(
        User|int $user,
        string $title,
        string $body,
        string $type = 'system',
        ?string $link = null,
        array $data = []
    ): Notification {
        if (! $user instanceof User) {
            $user = User::query()->findOrFail($user);
        }

        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'link' => $link,
            'data' => $data,
        ]);

        $this->pushFcm($user, $title, $body, $data + ['link' => (string) $link]);

        return $notification;
    }

    public function pushFcm(User $user, string $title, string $body, array $data = []): bool
    {
        $tokens = array_values(array_filter($user->fcm_tokens ?? []));
        if ($tokens === []) {
            return false;
        }

        $messaging = $this->messaging();
        if (! $messaging) {
            return false;
        }

        try {
            $message = CloudMessage::new()
                ->withNotification(FcmNotification::create($title, $body))
                ->withData(collect($data)->map(fn ($v) => (string) $v)->all());

            $report = $messaging->sendMulticast($message, $tokens);
            $invalid = [];
            foreach ($report->failures()->getItems() as $failure) {
                $invalid[] = $failure->target()->value();
            }
            if ($invalid !== []) {
                $user->fcm_tokens = array_values(array_diff($tokens, $invalid));
                $user->save();
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('FCM push failed: '.$e->getMessage());

            return false;
        }
    }

    public function firebaseReady(): bool
    {
        return $this->messaging() !== null;
    }

    protected function messaging(): mixed
    {
        static $messaging = false;
        if ($messaging !== false) {
            return $messaging;
        }

        $projectId = config('rebox.firebase.project_id');
        $clientEmail = config('rebox.firebase.client_email');
        $privateKey = str_replace('\\n', "\n", (string) config('rebox.firebase.private_key'));

        if (! $projectId || ! $clientEmail || ! $privateKey) {
            return $messaging = null;
        }

        try {
            $factory = (new Factory)
                ->withServiceAccount([
                    'type' => 'service_account',
                    'project_id' => $projectId,
                    'client_email' => $clientEmail,
                    'private_key' => $privateKey,
                    'token_uri' => 'https://oauth2.googleapis.com/token',
                ]);

            return $messaging = $factory->createMessaging();
        } catch (\Throwable $e) {
            Log::warning('Firebase init failed: '.$e->getMessage());

            return $messaging = null;
        }
    }
}
