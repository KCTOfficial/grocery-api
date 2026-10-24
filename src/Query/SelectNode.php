<?php

declare(strict_types=1);

namespace GroceryRestable\Query;

/**
 * Parsed "select" parameter: columns and embedded relations (recursive).
 */
final class SelectNode
{
    public bool $star = false;

    /** @var list<array{column: string, alias: string}> */
    public array $columns = [];

    /** @var array<string, array{relation: string, alias: string, node: SelectNode}> keyed by output alias */
    public array $embeds = [];

    /**
     * True when the client sent a select parameter (otherwise resource defaults are used).
     */
    public bool $explicit = false;

    public static function star(): self
    {
        $node = new self();
        $node->star = true;

        return $node;
    }

    public function addEmbed(string $relation, ?string $alias = null, ?SelectNode $node = null): void
    {
        $alias ??= $relation;
        $this->embeds[$alias] = ['relation' => $relation, 'alias' => $alias, 'node' => $node ?? self::star()];
    }

    public function depth(): int
    {
        $max = 0;
        foreach ($this->embeds as $embed) {
            $max = max($max, 1 + $embed['node']->depth());
        }

        return $max;
    }
}
