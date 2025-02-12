<?php

declare(strict_types=1);

namespace TomasVotruba\Bladestan\Compiler;

use Illuminate\Contracts\View\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Events\NullDispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\FileViewFinder;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use Throwable;
use TomasVotruba\Bladestan\Blade\PhpLineToTemplateLineResolver;
use TomasVotruba\Bladestan\PhpParser\ArrayStringToArrayConverter;
use TomasVotruba\Bladestan\PhpParser\NodeVisitor\AddLoopVarTypeToForeachNodeVisitor;
use TomasVotruba\Bladestan\PhpParser\NodeVisitor\RemoveEnvVariableNodeVisitor;
use TomasVotruba\Bladestan\PhpParser\NodeVisitor\RemoveEscapeFunctionNodeVisitor;
use TomasVotruba\Bladestan\PhpParser\SimplePhpParser;
use TomasVotruba\Bladestan\TemplateCompiler\NodeFactory\VarDocNodeFactory;
use TomasVotruba\Bladestan\TemplateCompiler\ValueObject\VariableAndType;
use TomasVotruba\Bladestan\ValueObject\IncludedViewAndVariables;
use TomasVotruba\Bladestan\ValueObject\PhpFileContentsWithLineMap;
use Webmozart\Assert\Assert;

final class BladeToPHPCompiler
{
    /**
     * @see https://regex101.com/r/BGw7Lf/1
     * @var string
     */
    private const VIEW_INCLUDE_REGEX = '#\$__env->make\(\'(.*?)\',( \[(.*?)?],)? \\\Illuminate\\\Support\\\Arr::except\(get_defined_vars\(\), \[\'__data\', \'__path\']\)\)->render\(\)#s';

    /**
     * @see https://regex101.com/r/BGw7Lf/1
     * @var string
     */
    private const VIEW_INCLUDE_REPLACE_REGEX = '#echo \$__env->make\(\'%s\',( \[(.*?)?],)? \\\Illuminate\\\Support\\\Arr::except\(get_defined_vars\(\), \[\'__data\', \'__path\']\)\)->render\(\);#s';

    /**
     * @var string
     */
    private const USE_PLACEHOLDER = 'use(%s)';

    /**
     * @var string
     */
    private const INCLUDED_CONTENT_PLACE_HOLDER = <<<STRING
(function () %s {
%s
%s
});
STRING;

    /**
     * @param array<int, array{class: string, alias: string, prefix: string}> $components
     */
    public function __construct(
        private readonly Filesystem $fileSystem,
        private readonly BladeCompiler $bladeCompiler,
        private readonly Standard $printerStandard,
        private readonly VarDocNodeFactory $varDocNodeFactory,
        private readonly FileViewFinder $fileViewFinder,
        private readonly PhpLineToTemplateLineResolver $phpLineToTemplateLineResolver,
        private readonly PhpContentExtractor $phpContentExtractor,
        private readonly ArrayStringToArrayConverter $arrayStringToArrayConverter,
        private readonly FileNameAndLineNumberAddingPreCompiler $fileNameAndLineNumberAddingPreCompiler,
        private readonly SimplePhpParser $simplePhpParser,
        private readonly array $components = [],
    ) {
        // Disable component rendering
        $this->bladeCompiler->withoutComponentTags();

        $this->setupBladeComponents();
    }

    /**
     * @param array<VariableAndType> $variablesAndTypes
     */
    public function compileContent(
        string $filePath,
        string $fileContents,
        array $variablesAndTypes
    ): PhpFileContentsWithLineMap {
        Assert::allIsInstanceOf($variablesAndTypes, VariableAndType::class);

        // Precompile contents to add template file name and line numbers
        $fileContents = $this->fileNameAndLineNumberAddingPreCompiler
            ->completeLineCommentsToBladeContents($filePath, $fileContents);

        // Extract PHP content from HTML and PHP mixed content
        $compiledBlade = $this->bladeCompiler->compileString($fileContents);
        $rawPhpContent = $this->phpContentExtractor->extract($compiledBlade);

        $includes = $this->getIncludes($rawPhpContent);

        $allVariablesList = array_map(
            static fn (VariableAndType $variableAndType): string => $variableAndType->getVariable(),
            $variablesAndTypes
        );

        // Recursively fetch and compile includes
        while ($includes !== []) {
            foreach ($includes as $include) {
                try {
                    $includedFilePath = $this->fileViewFinder->find($include->getIncludedViewName());
                    $includedFileContents = $this->fileSystem->get($includedFilePath);

                    $preCompiledContents = $this->fileNameAndLineNumberAddingPreCompiler
                        ->completeLineCommentsToBladeContents($includedFilePath, $includedFileContents);
                    $compiledContent = $this->bladeCompiler->compileString($preCompiledContents);
                    $includedContent = $this->phpContentExtractor->extract($compiledContent, false);
                } catch (Throwable) {
                    $includedContent = '';
                }

                $includedViewVariables = implode(
                    PHP_EOL,
                    array_map(
                        static fn (string $key, string $value): string => '$' . $key . ' = ' . $value . ';',
                        array_keys($include->getVariablesAndValues()),
                        $include->getVariablesAndValues()
                    )
                );

                $usedVariablesString = implode(
                    ', ',
                    array_map(static fn (string $variable): string => '$' . $variable, $allVariablesList)
                );
                $rawPhpContent = preg_replace(
                    sprintf(self::VIEW_INCLUDE_REPLACE_REGEX, preg_quote($include->getIncludedViewName())),
                    sprintf(
                        self::INCLUDED_CONTENT_PLACE_HOLDER,
                        $usedVariablesString !== '' ? sprintf(self::USE_PLACEHOLDER, $usedVariablesString) : '',
                        $includedViewVariables,
                        $includedContent
                    ),
                    $rawPhpContent
                ) ?? $rawPhpContent;

                foreach (array_keys($include->getVariablesAndValues()) as $variable) {
                    if (in_array($variable, $allVariablesList, true)) {
                        continue;
                    }

                    $allVariablesList[] = $variable;
                }
            }

            $includes = $this->getIncludes($rawPhpContent);
        }

        $decoratedPhpContent = $this->decoratePhpContent($rawPhpContent, $variablesAndTypes);
        $phpLinesToTemplateLines = $this->phpLineToTemplateLineResolver->resolve($decoratedPhpContent);
        return new PhpFileContentsWithLineMap($decoratedPhpContent, $phpLinesToTemplateLines);
    }

    /**
     * @param VariableAndType[] $variablesAndTypes
     */
    private function decoratePhpContent(string $phpContent, array $variablesAndTypes): string
    {
        $stmts = $this->simplePhpParser->parse($phpContent);

        $this->traverseStmtsWithVisitors($stmts, [
            // get rid of $__env variables
            new RemoveEnvVariableNodeVisitor(),
            // get rid of e() function calls
            new RemoveEscapeFunctionNodeVisitor(),
            new AddLoopVarTypeToForeachNodeVisitor(),
        ]);

        // Add @var docs to top of file
        $docNodes = $this->varDocNodeFactory->createDocNodes($variablesAndTypes);
        $stmts = array_merge($docNodes, $stmts);

        return $this->printerStandard->prettyPrintFile($stmts) . PHP_EOL;
    }

    /**
     * @param Stmt[] $stmts
     * @param NodeVisitorAbstract[] $nodeVisitors
     * @return Node[]
     */
    private function traverseStmtsWithVisitors(array $stmts, array $nodeVisitors): array
    {
        $nodeTraverser = new NodeTraverser();
        foreach ($nodeVisitors as $nodeVisitor) {
            $nodeTraverser->addVisitor($nodeVisitor);
        }

        return $nodeTraverser->traverse($stmts);
    }

    /**
     * @return IncludedViewAndVariables[]
     */
    private function getIncludes(string $compiled): array
    {
        preg_match_all(self::VIEW_INCLUDE_REGEX, $compiled, $includes);

        $return = [];

        foreach ($includes[1] as $i => $include) {
            $arrayString = trim((string) $includes[2][$i], ' ,');

            $array = $this->arrayStringToArrayConverter->convert($arrayString);

            $return[] = new IncludedViewAndVariables($include, $array);
        }

        return $return;
    }

    private function setupBladeComponents(): void
    {
        $currentWorkingDirectory = getcwd();

        if ($currentWorkingDirectory === false) {
            return;
        }

        // Hack to make the compiler work
        $application = new Application($currentWorkingDirectory);
        $application->bind(
            \Illuminate\Contracts\Foundation\Application::class,
            static fn (): Application => $application
        );
        $application->bind(
            Factory::class,
            fn (): \Illuminate\View\Factory => new \Illuminate\View\Factory(
                new EngineResolver(),
                $this->fileViewFinder,
                new NullDispatcher(new Dispatcher())
            )
        );

        $application->alias('view', 'foo');

        // Register components
        foreach ($this->components as $component) {
            $this->bladeCompiler->component($component['class'], $component['alias'], $component['prefix']);
        }
    }
}
