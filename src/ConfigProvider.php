<?php

declare(strict_types=1);

namespace HyperfAi\RouterMap;

use HyperfAi\RouterMap\Command\RouteMapCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'commands' => [
                RouteMapCommand::class,
            ],
        ];
    }
}
