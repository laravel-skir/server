<?php

declare(strict_types=1);

namespace LaravelSkir\Server\Studio;

use LaravelSkir\Runtime\Field;
use LaravelSkir\Runtime\MethodDescriptor;
use LaravelSkir\Runtime\Type;
use LaravelSkir\Runtime\Variant;
use LaravelSkir\Server\RegisteredProcedure;
use LaravelSkir\Server\SkirServer;

final class StudioRenderer
{
    public function render(SkirServer $server, string $endpoint): string
    {
        $metadata = [
            'endpoint' => $endpoint,
            'methods' => array_values(array_map(
                fn (RegisteredProcedure $procedure): array => $this->methodToArray($procedure->descriptor),
                $server->procedures(),
            )),
        ];

        $encodedMetadata = htmlspecialchars(
            json_encode($metadata, JSON_THROW_ON_ERROR),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $methodRows = implode('', array_map(
            fn (array $method): string => $this->renderMethod($method),
            $metadata['methods'],
        ));

        if ($methodRows === '') {
            $methodRows = '<p class="empty">No Skir procedures are registered for this endpoint.</p>';
        }

        $escapedEndpoint = $this->escape($endpoint);

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Skir RPC Studio</title>
    <style>
        :root {
            color-scheme: light;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #172033;
            background: #f7f8fb;
        }

        body {
            margin: 0;
            padding: 32px;
        }

        main {
            max-width: 960px;
            margin: 0 auto;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 28px;
            line-height: 1.2;
        }

        .endpoint {
            margin: 0 0 24px;
            color: #536179;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        .method {
            margin-bottom: 12px;
            border: 1px solid #d9dfeb;
            border-radius: 8px;
            background: #ffffff;
            padding: 16px;
        }

        .method h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }

        dl {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 8px 16px;
            margin: 0;
        }

        dt {
            color: #536179;
        }

        dd {
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        .empty {
            color: #536179;
        }
    </style>
</head>
<body>
    <main data-skir-studio="{$encodedMetadata}">
        <h1>Skir RPC Studio</h1>
        <p class="endpoint">{$escapedEndpoint}</p>
        {$methodRows}
    </main>
</body>
</html>
HTML;
    }

    /**
     * @return array{
     *     name: string,
     *     number: int,
     *     request: array<string, mixed>,
     *     response: array<string, mixed>
     * }
     */
    private function methodToArray(MethodDescriptor $descriptor): array
    {
        return [
            'name' => $descriptor->name,
            'number' => $descriptor->number,
            'request' => $this->typeToArray($descriptor->requestType),
            'response' => $this->typeToArray($descriptor->responseType),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function typeToArray(Type $type): array
    {
        $payload = [
            'kind' => $type->kind->value,
        ];

        if ($type->itemType !== null) {
            $payload['item'] = $this->typeToArray($type->itemType);
        }

        if ($type->fields !== []) {
            $payload['fields'] = array_map(
                fn (Field $field): array => $this->fieldToArray($field),
                $type->fields,
            );
        }

        if ($type->variants !== []) {
            $payload['variants'] = array_map(
                fn (Variant $variant): array => $this->variantToArray($variant),
                $type->variants,
            );
        }

        return $payload;
    }

    /**
     * @return array{name: string|null, number: int, removed: bool, type?: array<string, mixed>}
     */
    private function fieldToArray(Field $field): array
    {
        $payload = [
            'name' => $field->name,
            'number' => $field->number,
            'removed' => $field->removed,
        ];

        if ($field->type !== null) {
            $payload['type'] = $this->typeToArray($field->type);
        }

        return $payload;
    }

    /**
     * @return array{name: string, number: int, payload?: array<string, mixed>}
     */
    private function variantToArray(Variant $variant): array
    {
        $payload = [
            'name' => $variant->name,
            'number' => $variant->number,
        ];

        if ($variant->payloadType !== null) {
            $payload['payload'] = $this->typeToArray($variant->payloadType);
        }

        return $payload;
    }

    /**
     * @param  array{
     *     name: string,
     *     number: int,
     *     request: array<string, mixed>,
     *     response: array<string, mixed>
     * }  $method
     */
    private function renderMethod(array $method): string
    {
        $name = $this->escape($method['name']);
        $number = (string) $method['number'];
        $request = $this->escape(json_encode($method['request'], JSON_THROW_ON_ERROR));
        $response = $this->escape(json_encode($method['response'], JSON_THROW_ON_ERROR));

        return <<<HTML
<section class="method">
    <h2>{$name}</h2>
    <dl>
        <dt>Number</dt>
        <dd>{$number}</dd>
        <dt>Request</dt>
        <dd>{$request}</dd>
        <dt>Response</dt>
        <dd>{$response}</dd>
    </dl>
</section>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
