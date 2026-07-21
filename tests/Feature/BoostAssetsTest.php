<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Skir\Server\Tests\TestCase;
use SplFileInfo;

final class BoostAssetsTest extends TestCase
{
    private const string GUIDELINE = 'resources/boost/guidelines/core.blade.php';

    private const string SKILL = 'resources/boost/skills/skir-server-development/SKILL.md';

    /** @var array<int, string> */
    private const array REFERENCES = [
        'resources/boost/skills/skir-server-development/references/procedures-and-routing.md',
        'resources/boost/skills/skir-server-development/references/scaffolding.md',
        'resources/boost/skills/skir-server-development/references/structures-and-validation.md',
        'resources/boost/skills/skir-server-development/references/studio-codecs-and-testing.md',
    ];

    #[Test]
    public function it_publishes_a_concise_server_guideline(): void
    {
        $guideline = $this->readPackageFile(self::GUIDELINE);

        $this->assertLessThanOrEqual(150, str_word_count(strip_tags($guideline)));
        $this->assertStringContainsString('php-skir/server', $guideline);
        $this->assertStringContainsString('skir-server-development', $guideline);
        $this->assertStringContainsString('/skirout', $guideline);
        $this->assertStringContainsString('generated', $guideline);
        $this->assertStringContainsString('Laravel', $guideline);
    }

    #[Test]
    public function it_publishes_a_uniquely_named_server_skill_with_valid_frontmatter(): void
    {
        $skill = $this->readPackageFile(self::SKILL);
        $matched = preg_match('/\A---\R(.*?)\R---\R/s', $skill, $matches);

        $this->assertSame(1, $matched);

        $frontmatter = $matches[1];
        $this->assertLessThanOrEqual(1024, strlen($frontmatter));
        $this->assertMatchesRegularExpression('/^name: skir-server-development$/m', $frontmatter);
        $this->assertMatchesRegularExpression('/^description: Use when .+php-skir\/server\.$/m', $frontmatter);
        $this->assertSame(
            'skir-server-development',
            basename(dirname($this->packagePath(self::SKILL))),
        );
    }

    #[Test]
    public function it_bundles_every_server_skill_reference(): void
    {
        $skill = $this->readPackageFile(self::SKILL);

        foreach (self::REFERENCES as $reference) {
            $this->readPackageFile($reference);
            $this->assertStringContainsString(
                'references/'.basename($reference),
                $skill,
            );
        }
    }

    #[Test]
    public function it_keeps_server_guidance_anchored_to_the_released_package(): void
    {
        $guidance = $this->allGuidance();

        $this->assertSourceAnchorsAreDocumented(
            'src/Commands/MakeSkirCommand.php',
            [
                'skir:make',
                '--generate',
                '--all',
                '--module',
                '--method',
                '--style',
                '--controller',
                '--with-requests',
                '--without-requests',
            ],
            $guidance,
        );
        $this->assertSourceAnchorsAreDocumented(
            'src/Commands/MakeSkirRequestCommand.php',
            ['skir:make-request', '{method?}', '{--name=}'],
            $guidance,
        );
        $this->assertSourceAnchorsAreDocumented(
            'config/skir-server.php',
            ['studio_enabled', 'studio_query_key', 'codec'],
            $guidance,
        );

        foreach ([
            'Skir::controller',
            'Skir::method',
            'SkirContext',
            'RequestContext',
            'skir-php-generator',
            'skir-laravel-data-generator',
            'skir-simple-data-objects-generator',
            'spatie/laravel-data',
            'std-out/simple-data-objects',
            'npm install --save-dev skir skir-php-generator',
            'npm install --save-dev skir skir-laravel-data-generator',
            'npm install --save-dev skir skir-simple-data-objects-generator',
            'withoutMagicalCreation',
            'alwaysValidate',
            'makeFromSkirPayload',
            'fromArray',
            'MergeValidationRules',
            'BaseData::from()',
            'config.validation',
            '#[Middleware',
            '#[Authorize',
            'HasMiddleware',
            'authorize()',
            'rules()',
            'studio()',
            'studio(false)',
            'DenseJsonCodec',
            'StandardJsonCodec',
            'Base64DenseJsonCodec',
            'CborCodec',
            'skir_validation_failed',
            'skir_authorization_failed',
            'malformed',
            'duplicate',
            'terminable',
        ] as $requiredGuidance) {
            $this->assertStringContainsStringIgnoringCase($requiredGuidance, $guidance);
        }

        $this->assertStringContainsString('does not protect Studio', $guidance);
        $this->assertStringContainsString('outer route', $guidance);
    }

    #[Test]
    public function it_keeps_server_guidance_portable_and_non_executable(): void
    {
        $guidance = $this->allGuidance();

        foreach ([
            '/Users/',
            '.projects/trackr',
            'make artisan',
            'make composer',
            'make npm',
            'make test',
            'CBOR encrypts',
            'CBOR is secure',
            'edit generated',
        ] as $forbiddenGuidance) {
            $this->assertStringNotContainsStringIgnoringCase($forbiddenGuidance, $guidance);
        }

        $this->assertStringContainsString('serialization', $guidance);
        $this->assertStringContainsString('Do not edit', $guidance);
        $this->assertSame(
            [self::GUIDELINE, self::SKILL, ...self::REFERENCES],
            $this->boostFiles(),
        );
    }

    #[Test]
    public function it_does_not_require_laravel_boost(): void
    {
        $composer = json_decode(
            $this->readPackageFile('composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($composer);
        $this->assertArrayNotHasKey('laravel/boost', $composer['require'] ?? []);
        $this->assertArrayNotHasKey('laravel/boost', $composer['require-dev'] ?? []);
    }

    private function allGuidance(): string
    {
        $guidanceFiles = [self::GUIDELINE, self::SKILL, ...self::REFERENCES];

        return implode("\n", array_map($this->readPackageFile(...), $guidanceFiles));
    }

    /**
     * @param  array<int, string>  $anchors
     */
    private function assertSourceAnchorsAreDocumented(string $sourceFile, array $anchors, string $guidance): void
    {
        $source = $this->readPackageFile($sourceFile);

        foreach ($anchors as $anchor) {
            $this->assertStringContainsString($anchor, $source);
            $this->assertStringContainsString($anchor, $guidance);
        }
    }

    /** @return array<int, string> */
    private function boostFiles(): array
    {
        $boostPath = $this->packagePath('resources/boost');
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($boostPath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            if (! $file->isFile()) {
                continue;
            }

            $files[] = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($file->getPathname(), strlen($this->packagePath()) + 1),
            );
        }

        sort($files);

        return $files;
    }

    private function readPackageFile(string $relativePath): string
    {
        $absolutePath = $this->packagePath($relativePath);

        $this->assertFileExists($absolutePath);
        $this->assertIsReadable($absolutePath);

        $contents = file_get_contents($absolutePath);

        $this->assertIsString($contents);
        $this->assertNotSame('', trim($contents));

        return $contents;
    }

    private function packagePath(string $relativePath = ''): string
    {
        return implode(DIRECTORY_SEPARATOR, array_filter([
            dirname(__DIR__, 2),
            $relativePath,
        ]));
    }
}
