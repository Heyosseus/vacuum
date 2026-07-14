<?php

declare(strict_types=1);

namespace Heyosseus\Vacuum\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The schemas no panel reports on.
 *
 * PostgreSQL's own catalogs are noisy, large, and not something anybody can act
 * on: nobody is going to lower the fillfactor of pg_attribute. Every query asks
 * this the same question, so it is asked in one place.
 */
final readonly class IgnoredSchemas
{
    public function __construct(private Repository $config) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $ignored = $this->config->get('vacuum.ignored_schemas', []);

        if (! is_array($ignored)) {
            return [];
        }

        $schemas = [];

        foreach ($ignored as $schema) {
            if (is_string($schema)) {
                $schemas[] = $schema;
            }
        }

        return $schemas;
    }
}
