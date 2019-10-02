<?php

declare(strict_types=1);

/*
 * This file is part of Zenify
 * Copyright (c) 2014 Tomas Votruba (http://tomasvotruba.cz)
 */

namespace Zenify\DoctrineMigrations\DI;

use Arachne\EventDispatcher\DI\EventDispatcherExtension;
use Doctrine\DBAL\Migrations\Tools\Console\Command\AbstractCommand;
use Flame\Modules\Providers\IParametersProvider;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\ServiceDefinition;
use Symfony\Component\Console\Application;
use Zenify\DoctrineMigrations\CodeStyle\CodeStyle;
use Zenify\DoctrineMigrations\Configuration\Configuration;
use Zenify\DoctrineMigrations\EventSubscriber\ChangeCodingStandardEventSubscriber;
use Zenify\DoctrineMigrations\EventSubscriber\RegisterMigrationsEventSubscriber;
use Zenify\DoctrineMigrations\EventSubscriber\SetConsoleOutputEventSubscriber;
use Zenify\DoctrineMigrations\Exception\DI\MissingExtensionException;
use Zenify\DoctrineMigrations\IMigrationsProvider;

final class MigrationsExtension extends CompilerExtension
{

	/**
	 * @var string[]
	 */
	private $defaults = [
		'table' => 'doctrine_migrations',
		'column' => 'version',
		'directory' => '%appDir%/../migrations',
		'namespace' => 'Migrations',
		'codingStandard' => CodeStyle::INDENTATION_TABS,
		'versionsOrganization' => NULL,
	];

	/**
	 * @var string[]
	 */
	private $subscribers = [
		ChangeCodingStandardEventSubscriber::class,
		RegisterMigrationsEventSubscriber::class,
		SetConsoleOutputEventSubscriber::class,
	];


	public function loadConfiguration()
	{
		$this->ensureEventDispatcherExtensionIsRegistered();

		$containerBuilder = $this->getContainerBuilder();

		Compiler::loadDefinitions(
			$containerBuilder,
			$this->loadFromFile(__DIR__ . '/../config/services.neon')['services']
		);

		foreach ($this->subscribers as $key => $subscriber) {
			$containerBuilder->addDefinition($this->prefix('listener' . $key))
				->setClass($subscriber)
				->addTag(EventDispatcherExtension::TAG_SUBSCRIBER);
		}

		$config = $this->getValidatedConfig();

		$containerBuilder->addDefinition($this->prefix('codeStyle'))
			->setClass(CodeStyle::class)
			->setArguments([$config['codingStandard']]);

		$configurationDefinition = $this->addConfigurationDefinition($config);

		foreach ($this->compiler->getExtensions() as $extension) {
			if ($extension instanceof IMigrationsProvider) {
				$configurationDefinition->addSetup('registerMigrationsFromDirectory', [$extension->getMigrationsDir()]);
			}
		}
	}


	public function beforeCompile()
	{
		$containerBuilder = $this->getContainerBuilder();
		$containerBuilder->prepareClassList();

		$this->setConfigurationToCommands();
		$this->loadCommandsToApplication();
	}


	private function addConfigurationDefinition(array $config): ServiceDefinition
	{
		$containerBuilder = $this->getContainerBuilder();
		$configurationDefinition = $containerBuilder->addDefinition($this->prefix('configuration'));
		$configurationDefinition
			->setClass(Configuration::class)
			->addSetup('setMigrationsTableName', [$config['table']])
			->addSetup('setMigrationsColumnName', [$config['column']])
			->addSetup('setMigrationsDirectory', [$config['directory']])
			->addSetup('setMigrationsNamespace', [$config['namespace']]);

		if ($config['versionsOrganization'] === Configuration::VERSIONS_ORGANIZATION_BY_YEAR) {
			$configurationDefinition->addSetup('setMigrationsAreOrganizedByYear');

		} elseif ($config['versionsOrganization'] === Configuration::VERSIONS_ORGANIZATION_BY_YEAR_AND_MONTH) {
			$configurationDefinition->addSetup('setMigrationsAreOrganizedByYearAndMonth');
		}

		return $configurationDefinition;
	}


	private function setConfigurationToCommands()
	{
		$containerBuilder = $this->getContainerBuilder();
		$configurationDefinition = $containerBuilder->getDefinition($containerBuilder->getByType(Configuration::class));

		foreach ($containerBuilder->findByType(AbstractCommand::class) as $commandDefinition) {
			$commandDefinition->addSetup('setMigrationConfiguration', ['@' . $configurationDefinition->getClass()]);
		}
	}


	private function loadCommandsToApplication()
	{
		$containerBuilder = $this->getContainerBuilder();
		$applicationDefinition = $containerBuilder->getDefinition($containerBuilder->getByType(Application::class));
		foreach ($containerBuilder->findByType(AbstractCommand::class) as $name => $commandDefinition) {
			$applicationDefinition->addSetup('add', ['@' . $name]);
		}
	}


	private function getValidatedConfig() : array
	{
		$configuration = $this->getConfig($this->defaults);
		$this->validateConfig($configuration);
		$configuration['directory'] = $this->getContainerBuilder()->expand($configuration['directory']);

		return $configuration;
	}


	private function ensureEventDispatcherExtensionIsRegistered()
	{
		if ( ! $this->compiler->getExtensions(EventDispatcherExtension::class)) {
			throw new MissingExtensionException(
				sprintf('Please register required extension "%s" to your config.', EventDispatcherExtension::class)
			);
		}
	}

}
