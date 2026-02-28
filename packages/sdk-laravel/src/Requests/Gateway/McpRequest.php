<?php

declare(strict_types=1);

namespace HardImpact\Orbit\Requests\Gateway;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

abstract class McpRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    private static int $idCounter = 1;

    public function resolveEndpoint(): string
    {
        return '';
    }

    abstract protected function toolName(): string;

    /** @return array<string, mixed> */
    protected function toolArguments(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => $this->toolName(),
                'arguments' => $this->toolArguments(),
            ],
            'id' => self::$idCounter++,
        ];
    }

    /**
     * Unwrap the MCP JSON-RPC response envelope.
     *
     * MCP responses: {"result": {"content": [{"type": "text", "text": "{\"success\":true,...}"}]}}
     *
     * @return array<string, mixed>
     */
    public static function unwrap(Response $response): array
    {
        $body = $response->json();
        $text = $body['result']['content'][0]['text'] ?? '{}';

        return json_decode($text, true) ?? [];
    }
}
