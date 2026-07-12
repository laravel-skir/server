<?php

declare(strict_types=1);

namespace Skir\Server\Tests\Feature\Scaffolding;

use PHPUnit\Framework\Attributes\Test;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\PhpSourceValidator;
use Skir\Server\Scaffolding\ScaffoldingFilesystem;
use Skir\Server\Tests\TestCase;

final class PhpSourceValidatorTest extends TestCase
{
    #[Test]
    public function semantic_lint_failures_are_wrapped_and_the_preflight_file_is_removed(): void
    {
        $temporaryDirectory = sys_get_temp_dir().'/skir-source-validator-'.bin2hex(random_bytes(8));
        mkdir($temporaryDirectory, 0777, true);
        $validator = new PhpSourceValidator(new ScaffoldingFilesystem, $temporaryDirectory);

        try {
            $validator->validate(
                '<?php function invalid(never $value): void {}',
                '/application/InvalidController.php',
            );
            self::fail('A semantically invalid PHP source file should fail lint.');
        } catch (SkirScaffoldingException $exception) {
            self::assertStringContainsString('does not compile', $exception->getMessage());
            self::assertStringContainsString('never', strtolower($exception->getMessage()));
        } finally {
            self::assertSame([], glob($temporaryDirectory.'/*') ?: []);
            rmdir($temporaryDirectory);
        }
    }
}
