<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace PhpCsFixer\Console\Command;

use PhpCsFixer\Documentation\DocumentationLocator;
use PhpCsFixer\Documentation\FixerDocumentGenerator;
use PhpCsFixer\Documentation\RuleSetDocumentationGenerator;
use PhpCsFixer\FixerFactory;
use PhpCsFixer\RuleSet\RuleSets;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * @internal
 */
#[AsCommand(name: 'documentation', description: 'Dumps the documentation of the project into its "/doc" directory.')]
final class DocumentationCommand extends Command
{
    /** @TODO PHP 8.0 - remove the property */
    protected static $defaultName = 'documentation';

    /** @TODO PHP 8.0 - remove the property */
    protected static $defaultDescription = 'Dumps the documentation of the project into its "/doc" directory.';

    private Filesystem $filesystem;

    public function __construct(Filesystem $filesystem)
    {
        parent::__construct();
        $this->filesystem = $filesystem;
    }

    protected function configure(): void
    {
        $this->setAliases(['doc']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locator = new DocumentationLocator();

        $fixerFactory = new FixerFactory();
        $fixerFactory->registerBuiltInFixers();
        $fixers = $fixerFactory->getFixers();

        $setDefinitions = RuleSets::getSetDefinitions();

        $fixerDocumentGenerator = new FixerDocumentGenerator($locator);
        $ruleSetDocumentationGenerator = new RuleSetDocumentationGenerator($locator);

        // Array of existing fixer docs.
        // We first override existing files, and then we will delete files that are no longer needed.
        // We cannot remove all files first, as generation of docs is re-using existing docs to extract code-samples for
        // VersionSpecificCodeSample under incompatible PHP version.
        $docForFixerRelativePaths = [];

        foreach ($fixers as $fixer) {
            $docForFixerRelativePaths[] = $locator->getFixerDocumentationFileRelativePath($fixer);
            $this->filesystem->dumpFile(
                $locator->getFixerDocumentationFilePath($fixer),
                $fixerDocumentGenerator->generateFixerDocumentation($fixer)
            );
        }

        foreach (
            (new Finder())->files()
                ->in($locator->getFixersDocumentationDirectoryPath())
                ->notPath($docForFixerRelativePaths) as $file
        ) {
            $this->filesystem->remove($file->getPathname());
        }

        // Fixer doc. index

        $this->filesystem->dumpFile(
            $locator->getFixersDocumentationIndexFilePath(),
            $fixerDocumentGenerator->generateFixersDocumentationIndex($fixers)
        );

        // RuleSet docs.

        foreach ((new Finder())->files()->in($locator->getRuleSetsDocumentationDirectoryPath()) as $file) {
            $this->filesystem->remove($file->getPathname());
        }

        $paths = [];

        foreach ($setDefinitions as $name => $definition) {
            $path = $locator->getRuleSetsDocumentationFilePath($name);
            $paths[$path] = $definition;
            $this->filesystem->dumpFile($path, $ruleSetDocumentationGenerator->generateRuleSetsDocumentation($definition, $fixers));
        }

        // RuleSet doc. index

        $this->filesystem->dumpFile(
            $locator->getRuleSetsDocumentationIndexFilePath(),
            $ruleSetDocumentationGenerator->generateRuleSetsDocumentationIndex($paths)
        );

        $output->writeln('Docs updated.');

        return 0;
    }
}
