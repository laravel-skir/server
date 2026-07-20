<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Skir\Runtime\Field;
use Skir\Runtime\MethodDescriptor;
use Skir\Runtime\Type;
use Skir\Runtime\Variant;
use Skir\Server\ProcedureRegistry;
use Skir\Server\SkirServer;
use Skir\Server\Studio\StudioRenderer;
use Skir\Server\Tests\TestCase;

final class StudioRendererTest extends TestCase
{
    #[Test]
    public function it_renders_an_empty_state_when_no_procedures_are_registered(): void
    {
        Route::skirRpc('/empty-studio')->studio();

        $this
            ->get('/empty-studio?studio')
            ->assertOk()
            ->assertSee('No Skir procedures are registered for this endpoint.');
    }

    #[Test]
    public function head_requests_can_open_studio_with_a_suppressed_body(): void
    {
        Route::skirRpc('/head-studio')->studio();

        $this
            ->call('HEAD', '/head-studio?studio')
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertContent('');
    }

    #[Test]
    public function it_serializes_nested_type_metadata(): void
    {
        $server = new SkirServer(new ProcedureRegistry);
        $server->addMethod($this->complexDescriptor(), fn (array $request): array => $request);

        $metadata = $this->metadataFromHtml(app(StudioRenderer::class)->render($server, '/skir'));

        $this->assertSame([
            'endpoint' => '/skir',
            'methods' => [[
                'name' => 'ComplexMethod',
                'number' => 7001,
                'request' => [
                    'kind' => 'struct',
                    'fields' => [
                        [
                            'name' => 'items',
                            'number' => 0,
                            'removed' => false,
                            'type' => [
                                'kind' => 'array',
                                'item' => ['kind' => 'string'],
                            ],
                        ],
                        [
                            'name' => null,
                            'number' => 1,
                            'removed' => true,
                        ],
                        [
                            'name' => 'choice',
                            'number' => 2,
                            'removed' => false,
                            'type' => [
                                'kind' => 'enum',
                                'variants' => [
                                    [
                                        'name' => 'None',
                                        'number' => 0,
                                    ],
                                    [
                                        'name' => 'Value',
                                        'number' => 1,
                                        'payload' => ['kind' => 'int32'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'response' => [
                    'kind' => 'optional',
                    'item' => ['kind' => 'bytes'],
                ],
            ]],
        ], $metadata);
    }

    #[Test]
    public function it_escapes_endpoint_and_method_names(): void
    {
        $server = new SkirServer(new ProcedureRegistry);
        $server->addMethod(
            new MethodDescriptor('<script>method</script>', 7002, Type::string(), Type::string()),
            fn (string $request): string => $request,
        );

        $html = app(StudioRenderer::class)->render($server, '/<script>endpoint</script>');
        $metadata = $this->metadataFromHtml($html);

        $this->assertStringNotContainsString('<script>method</script>', $html);
        $this->assertStringNotContainsString('/<script>endpoint</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;method&lt;/script&gt;', $html);
        $this->assertStringContainsString('/&lt;script&gt;endpoint&lt;/script&gt;', $html);
        $this->assertSame('<script>method</script>', $metadata['methods'][0]['name']);
        $this->assertSame('/<script>endpoint</script>', $metadata['endpoint']);
    }

    private function complexDescriptor(): MethodDescriptor
    {
        return new MethodDescriptor(
            'ComplexMethod',
            7001,
            Type::struct([
                Field::value('items', 0, Type::array(Type::string())),
                Field::removed(1),
                Field::value('choice', 2, Type::enum([
                    Variant::constant('None', 0),
                    Variant::wrapper('Value', 1, Type::int32()),
                ])),
            ]),
            Type::optional(Type::bytes()),
        );
    }

    /**
     * @return array{
     *     endpoint: string,
     *     methods: array<int, array<string, mixed>>
     * }
     */
    private function metadataFromHtml(string $html): array
    {
        $matched = preg_match('/data-skir-studio="([^"]*)"/', $html, $matches);

        $this->assertSame(1, $matched);

        $json = html_entity_decode($matches[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $metadata = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($metadata);

        return $metadata;
    }
}
