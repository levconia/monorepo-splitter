<?php

declare(strict_types=1);

namespace Levconia\MonorepoSplitter;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\StyleInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

final class SplitMonorepoCommand extends Command
{
    public const FILE_ARGUMENT = 'file';

    public const SPLIT_REMOTE_PREFIX = 'split';

    private string $splitRemotePrefix = self::SPLIT_REMOTE_PREFIX;

    private StyleInterface $io;

    protected function configure(): void
    {
        $this->setName('split');
        $this->addArgument(self::FILE_ARGUMENT, InputArgument::OPTIONAL, 'Configuration file', 'composer.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $file = $input->getArgument(self::FILE_ARGUMENT);
        if (!file_exists($file)) {
            throw new \InvalidArgumentException(sprintf('Cannot find index JSON file "%s".', $file));
        }

        $data = $this->getFileContent($file);
        $mapping = $this->getMapping($data);

        foreach ($mapping as $splitName => $configuration) {
            foreach ($configuration['branches'] as $branch) {
                $this->split(
                    sprintf('%s-%s', $this->splitRemotePrefix, $splitName),
                    $configuration['prefix'],
                    $configuration['target'],
                    $branch,
                );
            }
        }

        return self::SUCCESS;
    }

    private function getFileContent(string $file): array
    {
        try {
            return json_decode(file_get_contents($file), true, 20, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \LogicException(sprintf('%s is not a valid json file', $file));
        }
    }

    private function split(
        string $splitName,
        string $prefix,
        string $target,
        string $branch
    ): void {
        $branchName = sprintf('%s/%s', $splitName, $branch);

        $this->runCommand(
            [
                'splitsh-lite',
                sprintf('--prefix=%s', $prefix),
                sprintf('--target=refs/heads/%s', $branchName),
                '--scratch',
            ],
        );
        $this->runCommand(['git', 'remote', 'add', $splitName, $target], true);
        $this->runCommand(['git', 'push', '-f', '-u', $splitName, sprintf('%s:%s', $branchName, $branch)]);
        $this->runCommand(['git', 'remote', 'remove', $splitName]);
        $this->runCommand(['git', 'branch', '-D', $branchName]);
    }

    private function runCommand(array $command, bool $suspendError = false): void
    {
        $this->io->note(sprintf('Running: %s', implode(' ', $command)));
        $process = new Process($command);
        $returnCode = $process->run(null, ['LC_ALL' => 'C']);

        foreach ($process as $type => $data) {
            if ($process::ERR === $type) {
                ('' === $data ?: $this->io->note(trim($data)));
            }
        }

        if (0 !== $returnCode && false === $suspendError) {
            throw new \LogicException(
                sprintf("Unexpected return code %s \n%s", $returnCode, $process->getErrorOutput()),
            );
        }

        if ('' !== $process->getOutput()) {
            $this->io->text($process->getOutput());
        }
    }

    private function getMapping(array $configuration): array
    {
        $mapping = $configuration['extra']['subtree-split'] ?? [];
        if ([] === $mapping) {
            throw new \LogicException('Configuration file does not contain subtree split mapping');
        }

        return $mapping;
    }
}
