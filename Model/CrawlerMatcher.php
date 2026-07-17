<?php
/**
 * Copyright © Citecue. All rights reserved.
 * See LICENSE for license details.
 */
declare(strict_types=1);

namespace Citecue\Delivery\Model;

/**
 * Pure, dependency-free User-Agent matcher. Mirrors citecue_app's
 * shared/utils/deliveryCrawlers.ts exactly:
 *
 *  - case-insensitive substring match of each crawler token against the UA;
 *  - when several tokens match, the LONGEST token wins (so "ChatGPT-User"
 *    beats a shorter overlapping token and "GoogleOther" is never shadowed);
 *  - a crawler is only servable when it actually fetches pages
 *    (fetchesPages=true — robots.txt-only tokens like Google-Extended never
 *    send real requests).
 */
class CrawlerMatcher
{
    /**
     * Longest-token-wins match over the full registry (including
     * non-fetching tokens, same as upstream matchDeliveryCrawler).
     *
     * @param array<int, array{id: string, token: string, fetchesPages: bool}> $crawlers
     * @param string|null $userAgent
     * @return array{id: string, token: string, fetchesPages: bool}|null
     */
    public static function match(array $crawlers, ?string $userAgent): ?array
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }
        $ua = strtolower($userAgent);
        $best = null;
        foreach ($crawlers as $crawler) {
            $token = (string)($crawler['token'] ?? '');
            if ($token === '' || strpos($ua, strtolower($token)) === false) {
                continue;
            }
            if ($best === null || strlen($token) > strlen((string)$best['token'])) {
                $best = $crawler;
            }
        }
        return $best;
    }

    /**
     * The serve decision: a known crawler that actually fetches pages.
     * Mirrors upstream shouldServeDeliveryCrawler().
     *
     * @param array<int, array{id: string, token: string, fetchesPages: bool}> $crawlers
     * @param string|null $userAgent
     * @return array{id: string, token: string, fetchesPages: bool}|null
     */
    public static function matchServable(array $crawlers, ?string $userAgent): ?array
    {
        $match = self::match($crawlers, $userAgent);
        return ($match !== null && !empty($match['fetchesPages'])) ? $match : null;
    }
}
