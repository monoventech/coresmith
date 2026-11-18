<?php

// Copyright 2026 Monoven Ltd. All rights reserved.
// Licensed under the Elastic License v2 (ELv2).
// See LICENSE file in the project root.

declare(strict_types=1);

namespace App\Stacks;

use App\Services\Runtime\RepositoryFacts;

final class JsServers
{
    private const LABELS = [
        'express' => 'Express', 'fastify' => 'Fastify', 'hono' => 'Hono', '@hono/node-server' => 'Hono', 'koa' => 'Koa', '@nestjs/core' => 'NestJS',
        '@hapi/hapi' => 'hapi', 'restify' => 'Restify', 'polka' => 'Polka', '@adonisjs/core' => 'AdonisJS', 'h3' => 'h3', 'elysia' => 'Elysia',
        'socket.io' => 'Socket.IO', 'ws' => 'ws', '@feathersjs/feathers' => 'Feathers', '@loopback/core' => 'LoopBack', 'sails' => 'Sails',
        '@remix-run/serve' => 'Remix', '@react-router/serve' => 'React Router', 'json-server' => 'JSON Server', '@strapi/strapi' => 'Strapi',
        '@keystone-6/core' => 'Keystone', 'payload' => 'Payload', '@medusajs/medusa' => 'Medusa', 'micro' => 'Micro', '@tinyhttp/app' => 'tinyhttp',
        'hyper-express' => 'HyperExpress', 'srvx' => 'srvx',
    ];

    public static function in(RepositoryFacts $facts): ?string
    {
        foreach (array_keys(self::LABELS) as $package) {
            if ($facts->dependency($package) !== null) {
                return $package;
            }
        }

        return null;
    }

    public static function knows(string $package): bool
    {
        return isset(self::LABELS[$package]);
    }

    public static function label(string $package): string
    {
        return self::LABELS[$package] ?? $package;
    }
}
