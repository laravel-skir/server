<?php

declare(strict_types=1);

namespace Skir\Server\Commands;

use Illuminate\Console\Command;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\FormRequestScaffolder;
use Skir\Server\Scaffolding\Manifest\ManifestRepository;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;

use function Laravel\Prompts\select;

final class MakeSkirRequestCommand extends Command
{
    protected $signature = 'skir:make-request {method?} {--name=}';

    protected $description = 'Create a Laravel form request for a Skir method';

    public function __construct(
        private readonly ManifestRepository $manifests,
        private readonly FormRequestScaffolder $scaffolder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $methodId = $this->methodId();
            $method = $this->findMethod($methodId);
            $name = $this->option('name');
            $name = is_string($name) ? $name : null;
            $path = $this->scaffolder->scaffold($method, $name);
        } catch (SkirScaffoldingException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Created Skir form request [{$path}].");

        return self::SUCCESS;
    }

    private function methodId(): string
    {
        $methodId = $this->argument('method');

        if (is_string($methodId) && $methodId !== '') {
            return $methodId;
        }

        if (! $this->input->isInteractive()) {
            throw SkirScaffoldingException::missingMethod();
        }

        $options = [];

        foreach ($this->manifests->methods() as $candidate) {
            if ($candidate->requestClass === null) {
                continue;
            }

            $options[$candidate->id()] = $candidate->id();
        }

        if ($options === []) {
            throw SkirScaffoldingException::noObjectRequestMethods();
        }

        return (string) select(
            label: 'Select a Skir method',
            options: $options,
        );
    }

    private function findMethod(string $methodId): SkirMethodDefinition
    {
        $method = $this->manifests->methods()[$methodId] ?? null;

        if (! $method instanceof SkirMethodDefinition) {
            throw SkirScaffoldingException::unknownMethod($methodId);
        }

        return $method;
    }
}
