<?php

namespace Zomunk\Notifier;

use Throwable;
use Zomunk\DealRepository;

/**
 * Turns pending deals into one Sender campaign per tier and records what was
 * sent, so a deal is never emailed to the same tier twice.
 */
final class DealMailer
{
    public function __construct(
        private SenderClient $sender,
        private DealRepository $repository,
        private array $config,
        private $logger = null,
    ) {
        $this->logger ??= static function (string $line): void {
            fwrite(STDOUT, $line . PHP_EOL);
        };
    }

    /**
     * @return array{tier:string, deals:int, campaign_id:?string, status:string}
     */
    public function sendTier(string $tier, bool $dryRun = false, int $limit = 25): array
    {
        $groupId = $tier === 'premium'
            ? $this->config['sender']['group_premium']
            : $this->config['sender']['group_free'];

        $deals = $this->repository->dealsAwaitingNotification($tier, $limit);
        if ($deals === []) {
            $this->log("[$tier] nothing pending");
            return ['tier' => $tier, 'deals' => 0, 'campaign_id' => null, 'status' => 'idle'];
        }

        $digest = EmailTemplate::digest($deals, $tier, $this->config['base_url']);
        $this->log(sprintf('[%s] %d deal(s): "%s"', $tier, count($deals), $digest['subject']));

        if ($dryRun) {
            $path = $this->config['root'] . "/var/preview-$tier.html";
            file_put_contents($path, $digest['html']);
            $this->log("[$tier] dry run, preview written to $path");
            return ['tier' => $tier, 'deals' => count($deals), 'campaign_id' => null, 'status' => 'dry-run'];
        }

        if (!$this->sender->isConfigured() || $groupId === '') {
            $error = "SENDER_API_TOKEN or the $tier group id is not configured";
            $this->log("[$tier] $error");
            foreach ($deals as $deal) {
                $this->repository->recordNotification((int) $deal['id'], $tier, 'skipped', null, $error);
            }
            return ['tier' => $tier, 'deals' => count($deals), 'campaign_id' => null, 'status' => 'skipped'];
        }

        try {
            $campaignId = $this->sender->createCampaign(
                sprintf('Zomunk Lite %s deals %s', $tier, gmdate('Y-m-d H:i')),
                $digest['subject'],
                $digest['html'],
                [$groupId],
                $this->config['sender']['from_email'],
                $this->config['sender']['from_name'],
                $this->config['sender']['reply_to'],
            );
            $this->sender->sendCampaign($campaignId);
        } catch (Throwable $e) {
            $this->log('[' . $tier . '] send failed: ' . $e->getMessage());
            // Recorded as failed rather than left pending, so a broken token
            // cannot silently re-send the same digest on the next cron tick.
            foreach ($deals as $deal) {
                $this->repository->recordNotification((int) $deal['id'], $tier, 'failed', null, $e->getMessage());
            }
            return ['tier' => $tier, 'deals' => count($deals), 'campaign_id' => null, 'status' => 'failed'];
        }

        foreach ($deals as $deal) {
            $this->repository->recordNotification((int) $deal['id'], $tier, 'sent', $campaignId);
        }
        $this->log("[$tier] campaign $campaignId sent to " . count($deals) . ' deal(s)');

        return ['tier' => $tier, 'deals' => count($deals), 'campaign_id' => $campaignId, 'status' => 'sent'];
    }

    private function log(string $line): void
    {
        ($this->logger)($line);
    }
}
