<?php

namespace Modules\Kernel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Modules\Kernel\Contracts\NotificationService;
use Modules\Kernel\Enums\StatutNotificationEmail;
use Modules\Kernel\Models\NotificationEmail;
use Modules\Kernel\Models\User;
use Modules\Kernel\Notifications\EchecEnvoiEmailNotification;
use Throwable;

/**
 * Seul endroit (avec Modules\Kernel\Support\LaravelNotificationService, qui
 * le met en file) autorisé à appeler Mail:: directement — l'envoi réel et
 * le suivi de son résultat (table notifications_email) vivent ensemble ici
 * pour que l'un ne puisse jamais se désynchroniser de l'autre.
 */
class EnvoyerNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array<int, User>  $alerterSiEchec  Agents internes à prévenir
     *                                            (notification en cloche) si
     *                                            les trois tentatives échouent.
     */
    public function __construct(
        public readonly int $notificationEmailId,
        public readonly Mailable $mailable,
        public readonly string $email,
        public readonly array $alerterSiEchec = [],
    ) {}

    /**
     * 1 min, 5 min, 15 min : une panne SMTP transitoire (coupure réseau,
     * fournisseur en maintenance) a de bonnes chances de se résoudre dans
     * cette fenêtre sans qu'on martèle inutilement un serveur en difficulté.
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        $notification = NotificationEmail::query()->find($this->notificationEmailId);
        if ($notification === null) {
            return;
        }

        $notification->increment('tentatives');
        // Enregistré à chaque tentative (pas seulement à l'échec final) :
        // si un worker tombe en panne avant failed(), on garde quand même
        // de quoi relancer manuellement depuis la supervision.
        $notification->update(['job_uuid' => $this->job?->uuid()]);

        Mail::to($this->email)->send($this->mailable);

        $notification->update([
            'statut' => StatutNotificationEmail::ENVOYE,
            'envoye_at' => now(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $notification = NotificationEmail::query()->find($this->notificationEmailId);
        if ($notification === null) {
            return;
        }

        $notification->update([
            'statut' => StatutNotificationEmail::ECHEC,
            'derniere_erreur' => mb_substr((string) $exception?->getMessage(), 0, 2000),
        ]);

        if ($this->alerterSiEchec === []) {
            return;
        }

        $service = app(NotificationService::class);
        foreach ($this->alerterSiEchec as $agent) {
            $service->notifier($agent, new EchecEnvoiEmailNotification($notification));
        }
    }
}
