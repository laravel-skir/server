<?php

declare(strict_types=1);

namespace Skir\Server\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\ControllerClassValidator;
use Skir\Server\Scaffolding\ControllerScaffolding;
use Skir\Server\Scaffolding\ControllerScaffoldingResult;
use Skir\Server\Scaffolding\ControllerStyle;
use Skir\Server\Scaffolding\Manifest\ManifestRepository;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Skir\Server\Scaffolding\Manifest\SkirModuleDefinition;
use Skir\Server\Scaffolding\RequestNamespaceValidator;
use Skir\Server\Scaffolding\ScaffoldingSelection;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final class MakeSkirCommand extends Command
{
    protected $signature = 'skir:make
        {--generate : Run the configured Skir generator before scaffolding}
        {--all : Scaffold every method in the configured manifests}
        {--module=* : Scaffold every method in a module}
        {--method=* : Scaffold an exact RPC method}
        {--style= : Controller style: module, invokable, or single}
        {--controller= : Fully qualified class for the single controller style}
        {--with-requests : Generate form requests for object request methods}
        {--without-requests : Do not generate form requests}';

    protected $description = 'Scaffold Laravel controllers and form requests from Skir manifests';

    public function __construct(
        private readonly ManifestRepository $manifests,
        private readonly ControllerScaffolding $scaffolder,
        private readonly GeneratorRunner $generator,
        private readonly ControllerClassValidator $controllerClassValidator,
        private readonly RequestNamespaceValidator $requestNamespaceValidator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->validateOptionCombinations();
            [$style, $singleController, $withFormRequests] = $this->resolvePreferences();

            $generatorStatus = $this->generateDefinitions();

            if ($generatorStatus !== self::SUCCESS) {
                return $generatorStatus;
            }

            $this->manifests->reload();
            $selection = new ScaffoldingSelection(
                $this->selectedMethods(),
                $style,
                $singleController,
                $withFormRequests,
            );
            $this->preview($selection);

            if ($this->input->isInteractive()) {
                if (! confirm('Create these files?', default: true)) {
                    $this->components->info(
                        'Scaffolding cancelled. No controller or request files were written; the generator may have updated definitions or manifests.',
                    );

                    return self::SUCCESS;
                }
            }

            $result = $this->scaffolder->scaffold($selection);
        } catch (SkirScaffoldingException|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->displayResult($result);

        return self::SUCCESS;
    }

    private function validateOptionCombinations(): void
    {
        if ($this->option('with-requests') && $this->option('without-requests')) {
            throw new InvalidArgumentException('Options [--with-requests] and [--without-requests] cannot be used together.');
        }

        $modules = $this->stringListOption('module');
        $methods = $this->stringListOption('method');

        if ($this->option('all')) {
            if ($modules !== [] || $methods !== []) {
                throw new InvalidArgumentException('Option [--all] cannot be used together with [--module] or [--method].');
            }
        }

        if (! $this->input->isInteractive()) {
            if (! $this->option('all') && $modules === [] && $methods === []) {
                throw new InvalidArgumentException(
                    'A concrete Skir selection is required in non-interactive mode. Pass [--all], [--module], or [--method].',
                );
            }
        }

        $controller = $this->option('controller');
        $styleOption = $this->option('style');

        if (is_string($controller)) {
            $hasExplicitStyle = is_string($styleOption) && $styleOption !== '';

            if ($hasExplicitStyle) {
                if ($styleOption !== ControllerStyle::Single->value) {
                    throw new InvalidArgumentException('Option [--controller] may only be used with the [single] controller style.');
                }
            }
        }

        if ($this->option('generate')) {
            $this->generatorCommand();
        }
    }

    private function generateDefinitions(): int
    {
        if (! $this->option('generate')) {
            return self::SUCCESS;
        }

        $command = $this->generatorCommand();
        $this->components->info('Generating Skir definitions and server manifest...');
        try {
            $status = $this->generator->run(
                $command,
                base_path(),
                null,
                function (string $type, string $buffer): void {
                    $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
                },
            );
        } catch (Throwable $exception) {
            $this->components->error(
                'Skir generator failed to start or run. '.get_debug_type($exception).'.',
            );

            return self::FAILURE;
        }

        if ($status === self::SUCCESS) {
            return self::SUCCESS;
        }

        $this->components->error("Skir generator failed with exit code [{$status}]. No files were scaffolded.");

        return $status;
    }

    /** @return list<string> */
    private function generatorCommand(): array
    {
        $command = config('skir-server.generator_command');

        if (! is_array($command) || ! array_is_list($command) || $command === []) {
            throw new InvalidArgumentException(
                'Skir generator configuration [generator_command] must be a non-empty argument list.',
            );
        }

        foreach ($command as $argument) {
            if (! is_string($argument) || trim($argument) === '') {
                throw new InvalidArgumentException(
                    'Skir generator configuration [generator_command] must contain only non-empty string arguments.',
                );
            }
        }

        return $command;
    }

    /** @return array{ControllerStyle, string|null, bool} */
    private function resolvePreferences(): array
    {
        $styleOption = $this->option('style');
        $controllerOption = $this->option('controller');
        $hasExplicitStyle = is_string($styleOption) && $styleOption !== '';
        $hasExplicitLayout = $hasExplicitStyle || is_string($controllerOption);
        $shouldPromptForStyle = $this->input->isInteractive() && ! $hasExplicitLayout;
        $style = $shouldPromptForStyle
            ? $this->interactiveStyle()
            : $this->configuredStyle();
        $singleController = $style === ControllerStyle::Single
            ? $this->singleController()
            : null;
        $withFormRequests = $this->withFormRequests();

        if ($style !== ControllerStyle::Single) {
            $this->controllerClassValidator->resolveControllerNamespace();
        }

        if ($withFormRequests) {
            $this->requestNamespaceValidator->resolve();
        }

        return [$style, $singleController, $withFormRequests];
    }

    /** @return list<SkirMethodDefinition> */
    private function selectedMethods(): array
    {
        $methods = $this->manifests->methods();
        $modules = $this->manifests->modules();

        if ($this->option('all')) {
            return array_values($methods);
        }

        $moduleSelectors = $this->stringListOption('module');
        $methodSelectors = $this->stringListOption('method');

        if ($this->input->isInteractive() && $moduleSelectors === [] && $methodSelectors === []) {
            [$moduleSelectors, $methodSelectors] = $this->interactiveSelectors($modules, $methods);
        }

        $selected = [];

        foreach ($moduleSelectors as $selector) {
            $module = $this->resolveModule($selector, $modules);

            foreach ($module->methods as $method) {
                $selected[$method->id()] = $method;
            }
        }

        foreach ($methodSelectors as $selector) {
            $method = $this->resolveMethod($selector, $methods);
            $selected[$method->id()] = $method;
        }

        if ($selected === []) {
            throw new InvalidArgumentException('Select at least one Skir module or method.');
        }

        return array_values($selected);
    }

    /**
     * @param  list<SkirModuleDefinition>  $modules
     * @param  array<string, SkirMethodDefinition>  $methods
     * @return array{list<string>, list<string>}
     */
    private function interactiveSelectors(array $modules, array $methods): array
    {
        $options = [];

        foreach ($modules as $module) {
            $options["module:{$module->name}"] = "Module: {$module->name}";
        }

        foreach ($methods as $method) {
            $options["method:{$method->id()}"] = "Method: {$method->id()}";
        }

        $selected = multiselect(
            label: 'Select Skir modules or methods',
            options: $options,
            required: 'Select at least one Skir module or method.',
        );
        $moduleSelectors = [];
        $methodSelectors = [];

        foreach ($selected as $value) {
            if (str_starts_with($value, 'module:')) {
                $moduleSelectors[] = substr($value, strlen('module:'));

                continue;
            }

            $methodSelectors[] = substr($value, strlen('method:'));
        }

        return [$moduleSelectors, $methodSelectors];
    }

    /** @param list<SkirModuleDefinition> $modules */
    private function resolveModule(string $selector, array $modules): SkirModuleDefinition
    {
        foreach ($modules as $module) {
            if ($module->name === $selector) {
                return $module;
            }
        }

        $matches = array_values(array_filter(
            $modules,
            static fn (SkirModuleDefinition $module): bool => strcasecmp($module->name, $selector) === 0,
        ));

        if (count($matches) > 1) {
            throw new InvalidArgumentException("Skir module selector [{$selector}] is ambiguous; use its exact case-sensitive name.");
        }

        if ($matches === []) {
            throw new InvalidArgumentException("Unknown Skir module [{$selector}]. Check the generated Skir manifest.");
        }

        return $matches[0];
    }

    /** @param array<string, SkirMethodDefinition> $methods */
    private function resolveMethod(string $selector, array $methods): SkirMethodDefinition
    {
        $exact = $methods[$selector] ?? null;

        if ($exact instanceof SkirMethodDefinition) {
            return $exact;
        }

        $matches = array_values(array_filter(
            $methods,
            static fn (SkirMethodDefinition $method): bool => strcasecmp($method->id(), $selector) === 0,
        ));

        if (count($matches) > 1) {
            throw new InvalidArgumentException("Skir method selector [{$selector}] is ambiguous; use its exact case-sensitive ID.");
        }

        if ($matches === []) {
            throw SkirScaffoldingException::unknownMethod($selector);
        }

        return $matches[0];
    }

    private function configuredStyle(): ControllerStyle
    {
        $option = $this->option('style');
        $controller = $this->option('controller');

        if (is_string($option) && $option !== '') {
            $style = $option;
        } elseif (is_string($controller)) {
            $style = ControllerStyle::Single->value;
        } else {
            $style = config('skir-server.scaffolding.controller_style');
        }

        if (! is_string($style)) {
            throw new InvalidArgumentException('Skir scaffolding configuration [controller_style] must be a supported string.');
        }

        $controllerStyle = ControllerStyle::tryFrom($style);

        if ($controllerStyle === null) {
            throw SkirScaffoldingException::unsupportedControllerStyle($style);
        }

        return $controllerStyle;
    }

    private function interactiveStyle(): ControllerStyle
    {
        $default = $this->configuredStyle();
        $style = select(
            label: 'Controller style',
            options: [
                ControllerStyle::Module->value => 'One controller per module',
                ControllerStyle::Invokable->value => 'One invokable controller per method',
                ControllerStyle::Single->value => 'One controller for this selection',
            ],
            default: $default->value,
        );

        return ControllerStyle::from((string) $style);
    }

    private function singleController(): string
    {
        $option = $this->option('controller');

        if (is_string($option)) {
            return $this->controllerClassValidator->resolve($option);
        }

        $configured = config('skir-server.scaffolding.single_controller', 'App\\Skir\\SkirController');

        if (! is_string($configured) || trim($configured) === '') {
            throw new InvalidArgumentException(
                'Skir scaffolding configuration [single_controller] must be a non-empty controller class.',
            );
        }

        if (! $this->input->isInteractive()) {
            return $this->controllerClassValidator->resolve($configured);
        }

        $controller = text(
            label: 'Single controller class',
            default: $configured,
            required: true,
        );

        return $this->controllerClassValidator->resolve($controller);
    }

    private function withFormRequests(): bool
    {
        if ($this->option('with-requests')) {
            return true;
        }

        if ($this->option('without-requests')) {
            return false;
        }

        $configured = config('skir-server.scaffolding.form_requests');

        if (! is_bool($configured)) {
            throw new InvalidArgumentException('Skir scaffolding configuration [form_requests] must be a boolean.');
        }

        if (! $this->input->isInteractive()) {
            return $configured;
        }

        return confirm('Generate form requests?', default: $configured);
    }

    private function preview(ScaffoldingSelection $selection): void
    {
        $this->newLine();
        $this->components->info('Selected RPC methods');

        foreach ($selection->methods as $method) {
            $this->line("  - {$method->id()}");
        }

        $controller = $selection->singleController === null
            ? $selection->style->value
            : "{$selection->style->value} ({$selection->singleController})";
        $requests = $selection->withFormRequests ? 'generate' : 'skip';
        $this->line("Controller layout: {$controller}");
        $this->line('Controller destinations:');

        foreach ($this->controllerDestinations($selection) as $destination) {
            $this->line("  - {$destination}");
        }

        $this->line("Form requests: {$requests}");
    }

    /** @return list<string> */
    private function controllerDestinations(ScaffoldingSelection $selection): array
    {
        if ($selection->style === ControllerStyle::Single) {
            return [$selection->singleController ?? ''];
        }

        $configuredNamespace = config('skir-server.scaffolding.controller_namespace', 'App\\Skir');
        $namespace = is_string($configuredNamespace) ? trim($configuredNamespace, '\\') : 'App\\Skir';
        $destinations = [];

        foreach ($selection->methods as $method) {
            $moduleNamespace = str_replace('.', '\\', $method->module);
            $className = $selection->style === ControllerStyle::Invokable
                ? "{$method->name}Controller"
                : last(explode('.', $method->module)).'Controller';
            $destinations["{$namespace}\\{$moduleNamespace}\\{$className}"] = true;
        }

        return array_keys($destinations);
    }

    private function displayResult(ControllerScaffoldingResult $result): void
    {
        foreach ($result->createdPaths as $path) {
            $this->components->info("Created: {$path}");
        }

        foreach ($result->updatedPaths as $path) {
            $this->components->info("Updated: {$path}");
        }

        foreach ($result->unchangedPaths as $path) {
            $this->line("<comment>Unchanged: {$path}</comment>");
        }

        foreach ($result->warnings as $warning) {
            $this->components->warn("Warning: {$warning}");
        }

        if ($result->registrations !== []) {
            $this->components->info('Route registrations');

            foreach ($result->registrations as $registration) {
                $this->line("  {$registration->snippet()}");
            }
        }

        if ($result->createdPaths === [] && $result->updatedPaths === []) {
            $this->components->info('Skir scaffolding is already up to date.');
        }
    }

    /** @return list<string> */
    private function stringListOption(string $name): array
    {
        $values = $this->option($name);

        if (! is_array($values)) {
            return [];
        }

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("Option [--{$name}] requires a non-empty selector.");
            }
        }

        return array_values($values);
    }
}
