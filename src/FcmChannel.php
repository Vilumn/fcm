<?php

namespace NotificationChannels\Fcm;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;

class FcmChannel
{
    /**
     * The maximum number of tokens we can use in a single request
     *
     * @var int
     */
    const TOKENS_PER_REQUEST = 500;

    /**
     * Create a new channel instance.
     */
    public function __construct(protected Dispatcher $events, protected Messaging $client)
    {
        //
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): ?Collection
    {
        $tokens = $notifiable->routeNotificationFor('fcm', $notification);

        if (empty($tokens)) {
            return null;
        }

        $androidCollection = Collection::make([]);
        $iosCollection = Collection::make([]);

        foreach ($tokens as $tknKey => $tknVal) {
            $fcmMessage = $notification->toFcm($notifiable, $tknKey);

            $collection = Collection::make($tknVal)
                ->chunk(self::TOKENS_PER_REQUEST)
                ->map(fn ($tknVal) => ($fcmMessage->client ?? $this->client)->sendMulticast($fcmMessage, $tknVal->all()))
                ->map(fn (MulticastSendReport $report) => $this->checkReportForFailures($notifiable, $notification, $report));

            if ($tknKey === 'android') {
                $androidCollection = $collection;
            } else if ($tknKey === 'ios') {
                $iosCollection = $collection;
            }
        }

        return $androidCollection->merge($iosCollection);
    }

    /**
     * Handle the report for the notification and dispatch any failed notifications.
     */
    protected function checkReportForFailures(mixed $notifiable, Notification $notification, MulticastSendReport $report): MulticastSendReport
    {
        Collection::make($report->getItems())
            ->filter(fn (SendReport $report) => $report->isFailure())
            ->each(fn (SendReport $report) => $this->dispatchFailedNotification($notifiable, $notification, $report));

        return $report;
    }

    /**
     * Dispatch failed event.
     */
    protected function dispatchFailedNotification(mixed $notifiable, Notification $notification, SendReport $report): void
    {
        $this->events->dispatch(new NotificationFailed($notifiable, $notification, self::class, [
            'report' => $report,
        ]));
    }
}
