<?php

namespace Sunnysideup\OrderUptime;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Permission;
use SilverStripe\ORM\FieldType\DBDatetime;
use Sunnysideup\Ecommerce\Model\Process\OrderStatusLogs\OrderStatusLogSubmitted;

/**
 * Monitors OrderStatusLogSubmitted DataObject creation rates by comparing the current period
 * against a baseline built from the same time window
 * over the previous X weeks.
 *
 * The window auto-widens if the baseline data is too sparse to be reliable.
 *
 * Usage:  GET /orderstatuslogsubmitted-monitor/check
 * GET /orderstatuslogsubmitted-monitor/check?weeks=8&maxwindow=8
 */
class OrderStatusLogSubmittedCreationMonitorController extends Controller
{
    private static string $url_segment = 'orderstatuslogsubmitted-monitor';

    private static array $allowed_actions = [
        'check',
    ];

    /** List of IPs allowed to access this endpoint without logging in */
    private static array $allowed_ips = [
        // '192.168.1.1', // Example: Add your monitoring tool's IPs here via YAML config
    ];

    private static int $default_lookback_weeks = 6;

    /** Lookback window: hours prior to current time */
    private static int $default_window_hours = 1;

    /** Maximum lookback window the system will widen to (hours) */
    private static int $max_window_hours = 8;

    /** Minimum number of historical weeks with at least 1 creation to trust the baseline */
    private static int $min_active_weeks = 3;

    /** Z-score threshold */
    private static float $z_threshold = 2.0;

    protected function init()
    {
        parent::init();

        $isAdmin = Permission::check('ADMIN');
        $isAllowedIp = $this->isAllowedIp($this->getRequest());

        // Block if they are neither an admin nor arriving from a trusted IP
        if (!$isAdmin && !$isAllowedIp) {
            $this->httpError(403, 'Access denied. IP not allowlisted.');
        }
    }

    public function check(HTTPRequest $request): HTTPResponse
    {
        // Safely cap inputs so even trusted monitors can't exhaust the database
        $weeks = min((int) ($request->getVar('weeks') ?: static::config()->get('default_lookback_weeks')), 12);
        $windowHrs = (int) ($request->getVar('window') ?: static::config()->get('default_window_hours'));
        $maxWindow = min((int) ($request->getVar('maxwindow') ?: static::config()->get('max_window_hours')), 24);

        $minActive  = (int) static::config()->get('min_active_weeks');
        $zThreshold = (float) static::config()->get('z_threshold');

        // Use SilverStripe's DBDatetime for mockability (useful for unit tests)
        $nowStr = DBDatetime::now()->getValue();
        $now    = new \DateTime($nowStr);

        // --- Adaptive widening loop -------------------------------------------
        $historicalCounts = [];
        $activeWeeks      = 0;
        $attempts         = [];

        while ($windowHrs <= $maxWindow) {
            $historicalCounts = $this->gatherHistoricalCounts($now, $weeks, $windowHrs);
            $activeWeeks      = $this->countActiveWeeks($historicalCounts);

            $attempts[] = [
                'window_hours' => $windowHrs,
                'active_weeks' => $activeWeeks,
                'sufficient'   => $activeWeeks >= $minActive,
            ];

            if ($activeWeeks >= $minActive) {
                break; // we have enough data
            }

            // Widen: double the window, but don't exceed the cap
            $nextWindow = $windowHrs * 2;

            if ($nextWindow > $maxWindow) {
                if ($windowHrs < $maxWindow) {
                    $windowHrs = $maxWindow;
                } else {
                    break;
                }
            } else {
                $windowHrs = $nextWindow;
            }
        }

        $reliable = $activeWeeks >= $minActive;

        // --- Current period count ---------------------------------------------
        $currentStart = (clone $now)->modify("-{$windowHrs} hours");
        $currentEnd   = clone $now;

        $currentCount = OrderStatusLogSubmitted::get()->filter([
            'Created:GreaterThanOrEqual' => $currentStart->format('Y-m-d H:i:s'),
            'Created:LessThanOrEqual'    => $currentEnd->format('Y-m-d H:i:s'),
        ])->count();

        // --- Statistics -------------------------------------------------------
        $counts   = array_column($historicalCounts, 'count');
        $n        = count($counts);
        $mean     = $n > 0 ? array_sum($counts) / $n : 0;

        $variance = 0;
        foreach ($counts as $c) {
            $variance += ($c - $mean) ** 2;
        }
        $stdDev = $n > 1 ? sqrt($variance / ($n - 1)) : 0;

        $zScore = ($stdDev > 0) ? ($currentCount - $mean) / $stdDev : 0;

        // --- Verdict & HTTP Status Code ---------------------------------------
        $httpStatusCode = 200; // Default to healthy

        if (!$reliable) {
            $verdict = 'insufficient_data';
            $detail  = sprintf(
                'Only %d of %d historical weeks had any creations, even at a %d-hour window. Cannot draw a reliable conclusion.',
                $activeWeeks,
                $weeks,
                $windowHrs
            );
        } elseif (abs($zScore) <= $zThreshold) {
            $verdict = 'normal';
            $detail  = 'Current creation rate is within expected range.';
        } elseif ($zScore > $zThreshold) {
            $verdict = 'above_expected';
            $detail  = 'Significantly MORE OrderStatusLogSubmitted objects created than usual.';
        } else {
            $verdict = 'below_expected';
            $detail  = 'Significantly FEWER OrderStatusLogSubmitted objects created than usual. Immediate attention required.';

            // Return 503 so monitoring infrastructure (Pingdom, DataDog, etc.) trips an alert
            $httpStatusCode = 503;
        }

        // --- Response Formatting ----------------------------------------------
        $data = ['verdict' => $verdict];

        if (Permission::check('ADMIN')) {
            $data = [
                'current_period' => [
                    'from'  => $currentStart->format('Y-m-d H:i:s'),
                    'to'    => $currentEnd->format('Y-m-d H:i:s'),
                    'count' => $currentCount,
                ],
                'baseline' => [
                    'weeks_analysed'    => $n,
                    'active_weeks'      => $activeWeeks,
                    'min_active_needed' => $minActive,
                    'reliable'          => $reliable,
                    'day_of_week'       => $now->format('l'),
                    'final_window_hrs'  => $windowHrs,
                    'mean'              => round($mean, 2),
                    'std_dev'           => round($stdDev, 2),
                    'historical_data'   => $historicalCounts,
                ],
                'widening_attempts' => $attempts,
                'analysis' => [
                    'z_score'     => round($zScore, 2),
                    'z_threshold' => $zThreshold,
                    'verdict'     => $verdict,
                    'detail'      => $detail,
                ]
            ];
        }

        return $this->jsonResponse($data, $httpStatusCode);
    }

    /**
     * Checks if the request is originating from an allowlisted IP address
     */
    private function isAllowedIp(HTTPRequest $request): bool
    {
        $allowedIps = static::config()->get('allowed_ips') ?? [];
        if (empty($allowedIps)) {
            return false;
        }

        // Check for Cloudflare's real IP header first
        $clientIp = $request->getHeader('CF-Connecting-IP');

        // Fallback to standard SilverStripe IP resolution
        if (!$clientIp) {
            $clientIp = $request->getIP();
        }

        return in_array($clientIp, $allowedIps, true);
    }

    /**
     * Gather creation counts for the same timeframe over the past $weeks weeks.
     */
    private function gatherHistoricalCounts(\DateTime $now, int $weeks, int $windowHrs): array
    {
        $results = [];

        for ($w = 1; $w <= $weeks; $w++) {
            $anchor = (clone $now)->modify("-{$w} weeks");

            $windowStart = (clone $anchor)->modify("-{$windowHrs} hours");
            $windowEnd   = clone $anchor;

            $count = OrderStatusLogSubmitted::get()->filter([
                'Created:GreaterThanOrEqual' => $windowStart->format('Y-m-d H:i:s'),
                'Created:LessThanOrEqual'    => $windowEnd->format('Y-m-d H:i:s'),
            ])->count();

            $results[] = [
                'week'  => $w,
                'from'  => $windowStart->format('Y-m-d H:i:s'),
                'to'    => $windowEnd->format('Y-m-d H:i:s'),
                'count' => $count,
            ];
        }

        return $results;
    }

    /**
     * How many of the historical weeks had at least one creation?
     */
    private function countActiveWeeks(array $historicalCounts): int
    {
        return count(array_filter($historicalCounts, fn($row) => $row['count'] > 0));
    }

    private function jsonResponse(array $data, int $code = 200): HTTPResponse
    {
        $response = HTTPResponse::create();
        $response->setStatusCode($code);
        $response->addHeader('Content-Type', 'application/json');
        $response->setBody(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $response;
    }
}
