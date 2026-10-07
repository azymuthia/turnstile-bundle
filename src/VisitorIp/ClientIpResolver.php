<?php

declare(strict_types=1);

namespace Azymuthia\TurnstileBundle\VisitorIp;

use Symfony\Component\HttpFoundation\Request;

final class ClientIpResolver implements VisitorIpResolver
{
    public function resolve(Request $request): ?string
    {
        return $request->getClientIp();
    }
}
