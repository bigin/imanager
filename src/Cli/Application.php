<?php

declare(strict_types=1);

namespace Imanager\Cli;

use Imanager\Cli\Command\DumpCommand;
use Imanager\Cli\Command\FtsRebuildCommand;
use Imanager\Cli\Command\MigrateFromV1Command;
use Imanager\Cli\Command\OptimizeCommand;
use Imanager\Cli\Command\RepairCommand;
use Imanager\Cli\Command\SchemaMigrateCommand;
use Imanager\Cli\Command\SchemaStatusCommand;
use Imanager\Imanager;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('iManager', Imanager::VERSION);

        $this->add(new SchemaStatusCommand());
        $this->add(new SchemaMigrateCommand());
        $this->add(new MigrateFromV1Command());
        $this->add(new FtsRebuildCommand());
        $this->add(new OptimizeCommand());
        $this->add(new RepairCommand());
        $this->add(new DumpCommand());
    }
}
