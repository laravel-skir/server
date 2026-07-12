<?php

declare(strict_types=1);

namespace Skir\Server\Scaffolding;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Skir\Server\Attributes\SkirMethod;
use Skir\Server\Exceptions\SkirScaffoldingException;
use Skir\Server\Scaffolding\Manifest\SkirMethodDefinition;
use Throwable;

final class PhpControllerEditor
{
    public function __construct(
        private readonly ControllerMethodRenderer $methodRenderer,
        private readonly PhpSourceValidator $sourceValidator,
    ) {}

    /**
     * @param  list<SkirMethodDefinition>  $methods
     * @param  array<string, string>  $requestClasses
     */
    public function edit(
        string $source,
        string $path,
        string $namespace,
        string $className,
        array $methods,
        array $requestClasses,
        bool $invokable = false,
    ): EditedController {
        $this->validateSelectedIdentities($methods);

        try {
            $this->sourceValidator->validate($source, $path);
        } catch (Throwable $exception) {
            throw SkirScaffoldingException::invalidExistingController($path, $exception);
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $originalStatements = $parser->parse($source);
        } catch (Throwable $exception) {
            throw SkirScaffoldingException::invalidExistingController($path, $exception);
        }

        if ($originalStatements === null) {
            throw SkirScaffoldingException::invalidExistingController(
                $path,
                new \RuntimeException('The parser returned no statements.'),
            );
        }

        $tokens = $parser->getTokens();
        $traverser = new NodeTraverser(new CloningVisitor);
        $statements = $traverser->traverse($originalStatements);
        (new NodeTraverser(new NameResolver(options: [
            'preserveOriginalNames' => true,
            'replaceNodes' => false,
        ])))->traverse($statements);

        [$namespaceNode, $class] = $this->findClass($statements, $namespace, $className, $path);
        $imports = $this->importMap($namespaceNode, $className);
        [$identities, $methodNames, $identityMethods] = $this->existingMethods($class, $path);
        $selectedIdentities = [];
        $missingMethods = [];

        foreach ($methods as $method) {
            $identity = $this->identity($method->enumClass, $method->enumCase);
            $selectedIdentities[$this->identityKey($method->enumClass, $method->enumCase)] = $identity;

            $identityKey = $this->identityKey($method->enumClass, $method->enumCase);

            if (isset($identities[$identityKey])) {
                if ($invokable && strtolower($identityMethods[$identityKey]) !== '__invoke') {
                    throw SkirScaffoldingException::occupiedControllerMethod(
                        $path,
                        '__invoke',
                        $identity,
                    );
                }

                continue;
            }

            $phpMethod = $invokable ? '__invoke' : $method->phpMethod;

            if (isset($methodNames[strtolower($phpMethod)])) {
                throw SkirScaffoldingException::occupiedControllerMethod(
                    $path,
                    $phpMethod,
                    $identity,
                );
            }

            $missingMethods[] = $method;
        }

        $warnings = $this->staleWarnings($path, $identities, $selectedIdentities);

        if ($missingMethods === []) {
            return new EditedController($source, false, $warnings);
        }

        $this->methodRenderer->addImports($imports, $missingMethods, $requestClasses);
        $this->appendImports($namespaceNode, $imports->newImports());

        foreach ($missingMethods as $method) {
            $class->stmts[] = $this->parseMethod($this->methodRenderer->render(
                $method,
                $imports,
                $requestClasses[$method->id()] ?? null,
                $invokable,
            ));
        }

        $rendered = (new Standard)->printFormatPreserving($statements, $originalStatements, $tokens);
        $this->sourceValidator->validate($rendered, $path);

        return new EditedController($rendered, true, $warnings);
    }

    /**
     * @param  list<Stmt>  $statements
     * @return array{Stmt\Namespace_, Stmt\Class_}
     */
    private function findClass(
        array $statements,
        string $namespace,
        string $className,
        string $path,
    ): array {
        foreach ($statements as $statement) {
            if (! $statement instanceof Stmt\Namespace_) {
                continue;
            }

            if ($statement->name?->toString() !== $namespace) {
                continue;
            }

            foreach ($statement->stmts as $namespaceStatement) {
                if (! $namespaceStatement instanceof Stmt\Class_) {
                    continue;
                }

                if ($namespaceStatement->name?->toString() === $className) {
                    return [$statement, $namespaceStatement];
                }
            }
        }

        throw SkirScaffoldingException::missingControllerClass($path, "{$namespace}\\{$className}");
    }

    private function importMap(Stmt\Namespace_ $namespace, string $className): PhpImportMap
    {
        $imports = new PhpImportMap($className);

        foreach ($namespace->stmts as $statement) {
            if ($statement instanceof Stmt\Use_) {
                if ($statement->type !== Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }

                foreach ($statement->uses as $use) {
                    if ($use->type !== Stmt\Use_::TYPE_UNKNOWN && $use->type !== Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }

                    $imports->registerExisting(
                        $use->name->toString(),
                        $use->alias?->toString() ?? $use->name->getLast(),
                    );
                }

                continue;
            }

            if (! $statement instanceof Stmt\GroupUse) {
                continue;
            }

            foreach ($statement->uses as $use) {
                $type = $use->type === Stmt\Use_::TYPE_UNKNOWN ? $statement->type : $use->type;

                if ($type !== Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }

                $class = $statement->prefix->toString().'\\'.$use->name->toString();
                $imports->registerExisting($class, $use->alias?->toString() ?? $use->name->getLast());
            }
        }

        $names = (new NodeFinder)->findInstanceOf($namespace->stmts, Name::class);

        foreach ($names as $name) {
            if ($name instanceof Name\FullyQualified || $name instanceof Name\Relative) {
                continue;
            }

            $imports->reserveAlias($name->getFirst());
        }

        return $imports;
    }

    /**
     * @return array{array<string, string>, array<string, true>, array<string, string>}
     */
    private function existingMethods(
        Stmt\Class_ $class,
        string $path,
    ): array {
        $identities = [];
        $methodNames = [];
        $identityMethods = [];

        foreach ($class->getMethods() as $method) {
            $methodNames[strtolower($method->name->toString())] = true;
            $identity = $this->methodIdentity($method);

            if ($identity === null) {
                continue;
            }

            [$enumClass, $enumCase] = $identity;
            $key = $this->identityKey($enumClass, $enumCase);

            if (isset($identities[$key])) {
                throw SkirScaffoldingException::duplicateControllerIdentity($path, $identities[$key]);
            }

            $identities[$key] = $this->identity($enumClass, $enumCase);
            $identityMethods[$key] = $method->name->toString();
        }

        return [$identities, $methodNames, $identityMethods];
    }

    /** @return array{string, string}|null */
    private function methodIdentity(Stmt\ClassMethod $method): ?array
    {
        foreach ($method->attrGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                if (strcasecmp($this->resolvedName($attribute->name), SkirMethod::class) !== 0) {
                    continue;
                }

                $value = $attribute->args[0]->value ?? null;

                if (! $value instanceof ClassConstFetch) {
                    continue;
                }

                if (! $value->class instanceof Name) {
                    continue;
                }

                if (! $value->name instanceof Node\Identifier) {
                    continue;
                }

                return [$this->resolvedName($value->class), $value->name->toString()];
            }
        }

        return null;
    }

    private function resolvedName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return $resolved instanceof Name ? $resolved->toString() : $name->toString();
    }

    /** @param array<string, string> $newImports */
    private function appendImports(Stmt\Namespace_ $namespace, array $newImports): void
    {
        if ($newImports === []) {
            return;
        }

        $position = 0;

        foreach ($namespace->stmts as $index => $statement) {
            if ($statement instanceof Stmt\Use_ || $statement instanceof Stmt\GroupUse) {
                $position = $index + 1;
            }
        }

        $uses = [];

        foreach ($newImports as $class => $alias) {
            $shortName = class_basename($class);
            $uses[] = new Stmt\Use_([
                new Stmt\UseUse(
                    new Name($class),
                    $alias === $shortName ? null : new Node\Identifier($alias),
                ),
            ]);
        }

        array_splice($namespace->stmts, $position, 0, $uses);
    }

    private function parseMethod(string $methodSource): Stmt\ClassMethod
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $statements = $parser->parse("<?php class Generated {\n{$methodSource}\n}");
        $class = $statements[0] ?? null;
        $method = $class instanceof Stmt\Class_ ? $class->getMethods()[0] ?? null : null;

        if (! $method instanceof Stmt\ClassMethod) {
            throw new \LogicException('Rendered Skir controller method could not be parsed.');
        }

        return $method;
    }

    /**
     * @param  array<string, string>  $existing
     * @param  array<string, string>  $selected
     * @return list<string>
     */
    private function staleWarnings(string $path, array $existing, array $selected): array
    {
        $warnings = [];

        foreach ($existing as $key => $identity) {
            if (isset($selected[$key])) {
                continue;
            }

            $warnings[] = "Skir controller [{$path}] contains stale Skir method [{$identity}]; it was not removed.";
        }

        return $warnings;
    }

    private function identity(string $enumClass, string $enumCase): string
    {
        return "{$enumClass}::{$enumCase}";
    }

    private function identityKey(string $enumClass, string $enumCase): string
    {
        return strtolower($enumClass).'::'.$enumCase;
    }

    /** @param list<SkirMethodDefinition> $methods */
    private function validateSelectedIdentities(array $methods): void
    {
        $selected = [];

        foreach ($methods as $method) {
            $key = strtolower($method->enumClass.'::'.$method->enumCase);

            if (isset($selected[$key])) {
                throw SkirScaffoldingException::duplicateControllerSelectionIdentity(
                    $selected[$key]->id(),
                    $method->id(),
                    $selected[$key]->enumClass.'::'.$selected[$key]->enumCase,
                );
            }

            $selected[$key] = $method;
        }
    }
}
