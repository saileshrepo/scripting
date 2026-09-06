<?php

namespace Zomunk\Notifier;

use Zomunk\Money;
use Zomunk\Offer;

/**
 * Builds the deal email. Table layout and inline styles on purpose: that is
 * what survives Gmail, Outlook and the rest.
 *
 * No unsubscribe link is hard-coded here — Sender appends the unsubscribe
 * footer required for CAN-SPAM/GDPR to every campaign it sends.
 */
final class EmailTemplate
{
    /**
     * @param array<int, array<string, mixed>> $deals rows from DealRepository
     * @return array{subject:string, html:string}
     */
    public static function digest(array $deals, string $tier, string $baseUrl): array
    {
        return [
            'subject' => self::subject($deals, $tier),
            'html'    => self::html($deals, $tier, $baseUrl),
        ];
    }

    public static function subject(array $deals, string $tier): string
    {
        $prefix = $tier === 'premium' ? 'Premium' : 'New';

        if (count($deals) === 1) {
            $deal = $deals[0];
            return sprintf(
                '%s %s -> %s from %s (%d%% off)',
                $deal['is_mistake'] ? 'Mistake fare:' : $prefix . ' deal:',
                $deal['origin'],
                $deal['destination'],
                Money::format((float) $deal['price'], $deal['currency']),
                (int) round((float) $deal['discount'] * 100),
            );
        }

        $best = max(array_map(static fn($deal) => (float) $deal['discount'], $deals));
        return sprintf(
            '%s %d flight deals from India - up to %d%% off',
            $prefix,
            count($deals),
            (int) round($best * 100),
        );
    }

    public static function html(array $deals, string $tier, string $baseUrl): string
    {
        $rows = '';
        foreach ($deals as $deal) {
            $rows .= self::dealCard($deal, $baseUrl);
        }

        $intro = $tier === 'premium'
            ? 'Everything we found since your last email, mistake fares included.'
            : 'A pick of the deals we found this week. Premium members saw these first.';

        $upsell = $tier === 'premium' ? '' : self::upsell($baseUrl);

        return <<<HTML
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c1d21;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">
  <tr><td style="padding:24px 24px 8px 24px;">
    <div style="font-size:20px;font-weight:700;">Zomunk Lite</div>
    <div style="font-size:14px;color:#5c6070;padding-top:4px;">{$intro}</div>
  </td></tr>
  {$rows}
  {$upsell}
  <tr><td style="padding:16px 24px 24px 24px;border-top:1px solid #eceef2;font-size:12px;color:#8a8f9e;line-height:1.6;">
    Fares move fast and can disappear while you book. We are not a booking site &mdash;
    every link opens Google Flights so you can book with the airline or an OTA.
    <br><a href="{$baseUrl}" style="color:#3355ff;">Open your deal dashboard</a>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    private static function dealCard(array $deal, string $baseUrl): string
    {
        $discount = (int) round((float) $deal['discount'] * 100);
        $price = Money::format((float) $deal['price'], $deal['currency']);
        $typical = Money::format((float) $deal['typical_fare'], $deal['currency']);
        $route = htmlspecialchars($deal['label'] ?? ($deal['origin'] . ' -> ' . $deal['destination']), ENT_QUOTES);
        $dates = htmlspecialchars(
            $deal['depart_date'] . ($deal['return_date'] ? ' - ' . $deal['return_date'] : ''),
            ENT_QUOTES,
        );
        $airline = htmlspecialchars((string) ($deal['carrier_name'] ?: $deal['carrier_code']), ENT_QUOTES);
        $stops = (int) $deal['stops'] === 0
            ? 'Non-stop'
            : sprintf('%d stop%s via %s', (int) $deal['stops'], (int) $deal['stops'] > 1 ? 's' : '',
                htmlspecialchars((string) $deal['layovers'], ENT_QUOTES));
        $duration = Offer::formatMinutes((int) $deal['duration_minutes']);
        $bag = (int) $deal['bag_included'] === 1 ? 'Checked bag included' : 'Cabin bag only';
        $bookingUrl = htmlspecialchars((string) $deal['booking_url'], ENT_QUOTES);
        $dealUrl = $baseUrl . '/deal.php?id=' . (int) $deal['id'];
        $badge = (int) $deal['is_mistake'] === 1
            ? '<span style="background:#ffe8d6;color:#b34700;font-size:11px;font-weight:700;padding:3px 8px;border-radius:99px;margin-left:8px;">MISTAKE FARE</span>'
            : '';

        return <<<HTML
  <tr><td style="padding:12px 24px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eceef2;border-radius:10px;">
      <tr><td style="padding:16px;">
        <div style="font-size:16px;font-weight:700;">{$route}{$badge}</div>
        <div style="font-size:13px;color:#5c6070;padding-top:6px;">{$dates} &middot; {$airline} &middot; {$stops} &middot; {$duration}</div>
        <div style="padding-top:12px;">
          <span style="font-size:26px;font-weight:700;">{$price}</span>
          <span style="font-size:13px;color:#8a8f9e;text-decoration:line-through;padding-left:8px;">{$typical}</span>
          <span style="background:#e6f7ec;color:#0d7a3d;font-size:12px;font-weight:700;padding:4px 8px;border-radius:99px;margin-left:8px;">{$discount}% off</span>
        </div>
        <div style="font-size:12px;color:#5c6070;padding-top:8px;">{$bag}</div>
        <div style="padding-top:14px;">
          <a href="{$bookingUrl}" style="background:#3355ff;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 16px;border-radius:8px;display:inline-block;">See this fare</a>
          <a href="{$dealUrl}" style="color:#3355ff;font-size:13px;text-decoration:none;padding-left:12px;">Details</a>
        </div>
      </td></tr>
    </table>
  </td></tr>
HTML;
    }

    private static function upsell(string $baseUrl): string
    {
        return <<<HTML
  <tr><td style="padding:8px 24px 16px 24px;">
    <div style="background:#f4f6ff;border-radius:10px;padding:16px;font-size:13px;color:#25304f;">
      <strong>Missing deals?</strong> Free members see a slice of economy deals, on a delay.
      Premium members get every deal the moment we find it &mdash; mistake fares, peak-season
      drops and business class included.
      <a href="{$baseUrl}/subscribe.php?tier=premium" style="color:#3355ff;">Go premium</a>
    </div>
  </td></tr>
HTML;
    }
}
