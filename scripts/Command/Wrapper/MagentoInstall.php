<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace MagentoDevBox\Command\Wrapper;

require_once __DIR__ . '/../../AbstractCommand.php';

use MagentoDevBox\AbstractCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArgvInput;

/**
 * Command for Magento final steps
 */
class MagentoInstall extends AbstractCommand
{
    /**
     * @var array
     */
    private $optionsConfig;

    /**
     * @var array
     */
    private $sharedData = [];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('magento:install')
            ->setDescription('Setup Magento and all components')
            ->setHelp('This command allows you to setup Magento and all components.');
    }

    /**
     * Perform delayed configuration
     *
     * @return void
     */
    public function postConfigure()
    {
        parent::configure();
    }

    /**
     * {@inheritdoc}
     *
     * @throws CommandNotFoundException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->executeWrappedCommands(
            [
                'magento:download',
                'magento:setup',
                'magento:setup:redis',
                'magento:setup:varnish',
                'magento:setup:elasticsearch',
                'magento:setup:integration-tests',
                'magento:finalize'
            ],
            $input,
            $output
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getOptionsConfig()
    {
        $optionsConfig = [];

        if ($this->optionsConfig === null) {
            /** @var AbstractCommand $command */
            foreach ($this->getApplication()->all() as $command) {
                if ($command instanceof AbstractCommand && !$command instanceof self) {
                    $optionsConfig = array_replace($optionsConfig, $command->getOptionsConfig());
                }
            }

            foreach ($optionsConfig as $optionName => $optionConfig) {
                $optionsConfig[$optionName]['initial'] = false;
            }

            $this->optionsConfig = $optionsConfig;
        }

        return $this->optionsConfig;
    }

    /**
     * Execute wrapped commands
     *
     * @param array|string $commandNames
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws CommandNotFoundException
     */
    private function executeWrappedCommands($commandNames, InputInterface $input, OutputInterface $output)
    {
        $commandNames = (array)$commandNames;

        foreach ($commandNames as $commandName) {
            $this->executeWrappedCommand($commandName, $input, $output);
        }
    }

    /**
     * Execute wrapped command
     *
     * @param string $commandName
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws CommandNotFoundException
     */
    private function executeWrappedCommand($commandName, InputInterface $input, OutputInterface $output)
    {
        /** @var AbstractCommand $command */
        $command = $this->getApplication()->get($commandName);
        $arguments = [null, $commandName];

        //Set values for options supported by the current command
        foreach ($command->getOptionsConfig() as $optionName => $optionConfig) {
            //Only if option is not virtual (defined as a valid CLI option)
            //And only if value was passed through CLI or was set in one of previously executed commands
            if (!$this->getConfigValue('virtual', $optionConfig, false)
                && ($input->hasParameterOption('--' . $optionName) || array_key_exists($optionName, $this->sharedData))
            ) {
                //Value set in previously executed command overwrites value originally passed through CLI
                $optionValue = array_key_exists($optionName, $this->sharedData)
                    ? $this->sharedData[$optionName]
                    : $input->getOption($optionName);

                //Value transformation for boolean type
                if ($this->getConfigValue('boolean', $optionConfig, false)) {
                    $optionValue = $optionValue ? static::SYMBOL_BOOLEAN_TRUE : static::SYMBOL_BOOLEAN_FALSE;
                }

                $arguments[] = sprintf('--%s=%s', $optionName, $optionValue);
            }
        }

        //Manually create new input for the command so it passes validation
        $commandInput = new ArgvInput($arguments);
        $commandInput->setInteractive($input->isInteractive());
        $command->run($commandInput, $output);

        //Store values that were set during current command execution for future commands
        foreach ($command->getValueSetStates() as $optionName => $optionState) {
            if ($optionState) {
                $this->sharedData[$optionName] = $commandInput->getOption($optionName);
            }
        }
    }
}
