<?php

namespace Zomunk;

use InvalidArgumentException;
use Throwable;
use Zomunk\Notifier\SenderClient;

/**
 * Signup: store the member locally (so the dashboard can gate deals by tier)
 * and mirror them into the matching Sender group (so campaigns reach them).
 * A Sender outage must not lose the signup, so the local row is written first
 * and the Sender status is recorded alongside it.
 */
final class SubscriberService
{
    public function __construct(
        private SenderClient $sender,
        private array $config,
    ) {
    }

    /**
     * @return array{email:string, tier:string, sender:string, message:string}
     */
    public function register(string $email, ?string $name, string $tier, array $homeAirports = []): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That does not look like a valid email address.');
        }
        $tier = $tier === 'premium' ? 'premium' : 'free';
        $airports = implode(',', array_map('strtoupper', array_filter($homeAirports)));

        $existing = Db::one('SELECT id FROM subscribers WHERE email = ?', [$email]);
        if ($existing === null) {
            Db::execute(
                'INSERT INTO subscribers (email, name, tier, home_airports, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$email, $name, $tier, $airports, 'active', gmdate('Y-m-d H:i:s')],
            );
        } else {
            Db::execute(
                "UPDATE subscribers SET name = ?, tier = ?, home_airports = ?, status = 'active' WHERE id = ?",
                [$name, $tier, $airports, $existing['id']],
            );
        }

        $groupId = $tier === 'premium'
            ? $this->config['sender']['group_premium']
            : $this->config['sender']['group_free'];

        if (!$this->sender->isConfigured() || $groupId === '') {
            Db::execute("UPDATE subscribers SET sender_status = 'unconfigured' WHERE email = ?", [$email]);
            return [
                'email'   => $email,
                'tier'    => $tier,
                'sender'  => 'unconfigured',
                'message' => 'You are on the list. Email alerts are off until Sender is configured.',
            ];
        }

        try {
            $response = $this->sender->upsertSubscriber($email, $name, [$groupId], [
                'home_airports' => $airports,
                'tier'          => $tier,
            ]);
            $senderId = $response['data']['id'] ?? $response['id'] ?? null;
            Db::execute(
                "UPDATE subscribers SET sender_subscriber_id = ?, sender_status = 'synced' WHERE email = ?",
                [$senderId !== null ? (string) $senderId : null, $email],
            );
            return [
                'email'   => $email,
                'tier'    => $tier,
                'sender'  => 'synced',
                'message' => 'You are in. Deals land in your inbox as we find them.',
            ];
        } catch (Throwable $e) {
            Db::execute(
                "UPDATE subscribers SET sender_status = 'error' WHERE email = ?",
                [$email],
            );
            error_log('Sender sync failed for ' . $email . ': ' . $e->getMessage());
            return [
                'email'   => $email,
                'tier'    => $tier,
                'sender'  => 'error',
                'message' => 'You are on the list, but the email provider did not confirm the signup. '
                    . 'We will retry on the next sync.',
            ];
        }
    }

    /** Re-pushes locally-known members Sender never confirmed. */
    public function resyncPending(int $limit = 100): array
    {
        $rows = Db::query(
            "SELECT email, name, tier, home_airports FROM subscribers
             WHERE status = 'active' AND (sender_status IS NULL OR sender_status IN ('error', 'unconfigured'))
             LIMIT $limit",
        );

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->register(
                $row['email'],
                $row['name'],
                $row['tier'],
                array_filter(explode(',', (string) $row['home_airports'])),
            );
        }
        return $results;
    }
}
