<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Resources\Gateway;

use Saloon\Http\BaseResource;
use Saloon\Http\Response;

abstract class GatewayResource extends BaseResource
{
    /**
     * Unwrap the MCP JSON-RPC response envelope.
     *
     * MCP responses: {"result": {"content": [{"type": "text", "text": "{\"success\":true,...}"}]}}
     *
     * @return array<string, mixed>
     */
    protected function unwrap(Response $response): array
    {
        $body = $response->json();
        $text = $body['result']['content'][0]['text'] ?? '{}';

        return json_decode($text, true) ?? [];
    }
}
