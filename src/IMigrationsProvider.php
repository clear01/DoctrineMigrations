<?php
declare(strict_types = 1);

namespace Zenify\DoctrineMigrations;

interface IMigrationsProvider
{
	public function getMigrationsDir(): string;

}