<?php
declare(strict_types=1);

namespace Forum\Models;

/**
 * Shared shape of every Forum table — same pattern as Contacts\Models\
 * ModelBase: an `id` column (the skeleton's Audit listener reads it),
 * keepSnapshots(true) so the audit log records every change, and
 * created_at/updated_at written from PHP so the values are identical on
 * PostgreSQL, MySQL and SQLite instead of each engine's own
 * CURRENT_TIMESTAMP rendering.
 *
 * Namespaced and PSR-4 autoloaded through Composer because the service is
 * resolved from the shared DI during other modules' requests, from the
 * cron worker and from the tests, where Module::registerAutoloaders()
 * never runs.
 */
abstract class ModelBase extends \Phalcon\Mvc\Model
{
    public $id;
    public $created_at;
    public $updated_at;

    abstract protected function tableName(): string;

    public function initialize(): void
    {
        $this->setSource($this->tableName());
        $this->keepSnapshots(true);
    }

    public function beforeValidationOnCreate(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->created_at = $this->created_at ?? $now;
        $this->updated_at = $now;
    }

    public function beforeValidationOnUpdate(): void
    {
        $this->updated_at = gmdate('Y-m-d H:i:s');
    }
}
