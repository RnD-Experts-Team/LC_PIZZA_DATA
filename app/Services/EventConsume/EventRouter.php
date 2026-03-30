<?php

namespace App\Services\EventConsume;

use Exception;

class EventRouter
{
    private array $map;
    public function __construct()
    {
        $devMode = (bool) config('nats.dev_mode');

        $authPrefix = $devMode
            ? 'auth.testing.v1'
            : 'auth.v1';


        $this->map = [
            // USERS
            "{$authPrefix}.user.created" => \App\Services\EventConsume\Handlers\UserCreatedHandler::class,
            "{$authPrefix}.user.updated" => \App\Services\EventConsume\Handlers\UserUpdatedHandler::class,
            "{$authPrefix}.user.deleted" => \App\Services\EventConsume\Handlers\UserDeletedHandler::class,

            // ASSIGNMENTS => replicate qa_auditor into user_store_roles
            "{$authPrefix}.assignment.user_role_store.assigned" => \App\Services\EventConsume\Handlers\UserStoreRoleAssignedHandler::class,
            "{$authPrefix}.assignment.user_role_store.removed" => \App\Services\EventConsume\Handlers\UserStoreRoleRemovedHandler::class,
            "{$authPrefix}.assignment.user_role_store.toggled" => \App\Services\EventConsume\Handlers\UserStoreRoleToggledHandler::class,
            "{$authPrefix}.assignment.user_role_store.bulk_assigned" => \App\Services\EventConsume\Handlers\UserStoreRoleBulkAssignedHandler::class,
        ];
    }

    public function resolve(string $subject): string
    {
        if (!isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
