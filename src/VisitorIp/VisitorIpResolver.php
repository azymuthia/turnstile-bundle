<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\VisitorIp;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tells the verifier which IP address to send to Cloudflare as `remoteip`.
 *
 * Behind Cloudflare, Request::getClientIp() is an edge address unless the edges are trusted proxies;
 * an application there implements this to read `CF-Connecting-IP` instead.
 */
interface VisitorIpResolver
{
    public function resolve(Request $request): ?string;
}
