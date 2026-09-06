<?php

namespace Zomunk\Notifier;

use RuntimeException;
use Zomunk\HttpClient;

/**
 * Sender (sender.net) REST API v2 client.
 *
 * Base URL   https://api.sender.net/v2
 * Auth       Authorization: Bearer <API access token>   (Settings -> API access tokens)
 *
 * Only the handful of endpoints this project needs are wrapped, and every path
 * is a constant below so it is a one-line fix if Sender moves one. Run
 * `php bin/sender-check.php` after configuring the token: it calls the groups
 * endpoint and prints the group ids to put in .env, which doubles as a check
 * that the token, base URL and paths are all correct for your account.
 */
final class SenderClient
{
    private const BASE = 'https://api.sender.net/v2';

    private const PATH_GROUPS            = '/groups';
    private const PATH_SUBSCRIBERS       = '/subscribers';
    private const PATH_SUBSCRIBER_GROUPS = '/subscribers/groups/%s';   // %s = group id
    private const PATH_CAMPAIGNS         = '/campaigns';
    private const PATH_CAMPAIGN_SEND     = '/campaigns/%s/send';       // %s = campaign id

    public function __construct(
        private string $token,
        private ?HttpClient $http = null,
    ) {
        $this->http ??= new HttpClient();
    }

    public function isConfigured(): bool
    {
        return trim($this->token) !== '';
    }

    /** @return array<int, array{id:string, title:string, subscribers:int|null}> */
    public function listGroups(): array
    {
        $response = $this->get(self::PATH_GROUPS);
        $groups = [];
        foreach ($response['data'] ?? [] as $group) {
            $groups[] = [
                'id'          => (string) ($group['id'] ?? ''),
                'title'       => (string) ($group['title'] ?? $group['name'] ?? ''),
                'subscribers' => $group['subscribers_count'] ?? $group['active_subscribers'] ?? null,
            ];
        }
        return $groups;
    }

    public function createGroup(string $title): array
    {
        return $this->post(self::PATH_GROUPS, ['title' => $title]);
    }

    /**
     * Adds (or updates) a subscriber and puts them in the given groups.
     * Sender treats a repeat email as an update, so this is safe to call on
     * every signup without checking first.
     *
     * @param string[] $groupIds
     */
    public function upsertSubscriber(
        string $email,
        ?string $firstName = null,
        array $groupIds = [],
        array $fields = [],
    ): array {
        $payload = array_filter([
            'email'     => $email,
            'firstname' => $firstName,
            'groups'    => $groupIds !== [] ? array_values($groupIds) : null,
            'fields'    => $fields !== [] ? $fields : null,
        ], static fn($value) => $value !== null);

        return $this->post(self::PATH_SUBSCRIBERS, $payload);
    }

    /** @param string[] $emails */
    public function addSubscribersToGroup(string $groupId, array $emails): array
    {
        return $this->post(
            sprintf(self::PATH_SUBSCRIBER_GROUPS, rawurlencode($groupId)),
            ['subscribers' => array_values($emails)],
        );
    }

    /**
     * Creates an email campaign aimed at one or more groups.
     *
     * @param string[] $groupIds
     * @return string campaign id
     */
    public function createCampaign(
        string $title,
        string $subject,
        string $html,
        array $groupIds,
        string $fromEmail,
        string $fromName,
        ?string $replyTo = null,
    ): string {
        $response = $this->post(self::PATH_CAMPAIGNS, [
            'title'        => $title,
            'subject'      => $subject,
            'from'         => $fromEmail,
            'from_name'    => $fromName,
            'reply_to'     => $replyTo ?? $fromEmail,
            'content_type' => 'html',
            'content'      => $html,
            'groups'       => array_values($groupIds),
        ]);

        $id = $response['data']['id'] ?? $response['id'] ?? null;
        if ($id === null) {
            throw new RuntimeException(
                'Sender did not return a campaign id: ' . json_encode($response, JSON_UNESCAPED_SLASHES)
            );
        }
        return (string) $id;
    }

    public function sendCampaign(string $campaignId): array
    {
        return $this->post(sprintf(self::PATH_CAMPAIGN_SEND, rawurlencode($campaignId)), []);
    }

    // --- transport ----------------------------------------------------------

    private function get(string $path): array
    {
        return $this->unwrap($this->http->getJson(self::BASE . $path, [], $this->headers()), 'GET ' . $path);
    }

    private function post(string $path, array $body): array
    {
        return $this->unwrap($this->http->postJson(self::BASE . $path, $body, $this->headers()), 'POST ' . $path);
    }

    private function headers(): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SENDER_API_TOKEN is not set.');
        }
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    private function unwrap(array $response, string $context): array
    {
        if ($response['status'] === 401) {
            throw new RuntimeException("Sender rejected the API token (401) on $context.");
        }
        if ($response['status'] === 429) {
            throw new RuntimeException("Sender rate limit hit (429) on $context. Retry later.");
        }
        if ($response['status'] >= 400) {
            throw new RuntimeException(
                sprintf('Sender %s failed (%d): %s', $context, $response['status'], substr($response['body'], 0, 400))
            );
        }
        return is_array($response['json']) ? $response['json'] : [];
    }
}
