<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Skir\Server\Codecs\DenseJsonCodec;
use Skir\Server\SkirServerServiceProvider;
use Skir\Server\Tests\TestCase;

final class SkirServerConfigurationTest extends TestCase
{
    #[Test]
    public function studio_is_disabled_by_default(): void
    {
        $this->assertFalse(config('skir-server.studio_enabled'));
    }

    #[Test]
    public function studio_uses_the_studio_query_key_by_default(): void
    {
        $this->assertSame('studio', config('skir-server.studio_query_key'));
    }

    #[Test]
    public function dense_json_is_the_default_codec(): void
    {
        $this->assertSame(DenseJsonCodec::class, config('skir-server.codec'));
    }

    #[Test]
    public function package_configuration_is_publishable(): void
    {
        $publishablePaths = ServiceProvider::pathsToPublish(
            SkirServerServiceProvider::class,
            'skir-server-config',
        );

        $this->assertCount(1, $publishablePaths);
        $this->assertSame([config_path('skir-server.php')], array_values($publishablePaths));

        $sourcePath = array_key_first($publishablePaths);

        $this->assertIsString($sourcePath);
        $this->assertFileExists($sourcePath);
    }
}
