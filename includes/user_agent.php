<?php
/** Lightweight heuristic classification; user agents are not verified identities. */
declare(strict_types=1);
if (!defined('BMETRICS_ENTRY')) { http_response_code(404); exit; }
function classify_user_agent(string $agent): array
{
    if (preg_match('/bot|crawler|spider|slurp|headless/i', $agent)) {
        return ['Bot', 'Bot / crawler'];
    }
    $device = $agent === '' ? 'Unknown' : 'Desktop';
    if (preg_match('/ipad|tablet|kindle|silk|playbook/i', $agent) ||
        (stripos($agent, 'Android') !== false && stripos($agent, 'Mobile') === false)) {
        $device = 'Tablet';
    } elseif (preg_match('/mobile|iphone|ipod|iemobile/i', $agent)) {
        $device = 'Mobile';
    }
    $browser = 'Other';
    foreach ([
        'Edge' => '/Edg(?:e|A|iOS)?\//i', 'Opera' => '/OPR\/|Opera|OPiOS/i',
        'Samsung Internet' => '/SamsungBrowser\//i', 'Firefox' => '/Firefox\/|FxiOS\//i',
        'Chrome' => '/Chrome\/|CriOS\//i', 'Safari' => '/Safari\//i',
        'Internet Explorer' => '/MSIE|Trident/i',
    ] as $name => $pattern) {
        if (preg_match($pattern, $agent)) { $browser = $name; break; }
    }
    return [$device, $browser];
}
