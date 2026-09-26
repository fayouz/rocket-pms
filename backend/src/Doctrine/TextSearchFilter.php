<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * "?q=dupont jean": every word must appear (case-insensitive) in one of the configured properties.
 * #[ApiFilter(TextSearchFilter::class, properties: ['lastName', 'firstName'])]
 */
final class TextSearchFilter extends AbstractFilter
{
    public const PARAMETER = 'q';

    protected function filterProperty(string $property, mixed $value, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (self::PARAMETER !== $property || !\is_string($value) || '' === trim($value)) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        $fields = array_keys($this->properties ?? []);
        foreach (\array_slice(preg_split('/\s+/', trim($value)) ?: [], 0, 5) as $word) {
            $param = $queryNameGenerator->generateParameterName('q');
            $queryBuilder
                ->andWhere(implode(' OR ', array_map(static fn (string $field) => \sprintf('LOWER(%s.%s) LIKE :%s', $alias, $field, $param), $fields)))
                ->setParameter($param, '%'.addcslashes(mb_strtolower($word), '%_\\').'%');
        }
    }

    public function getDescription(string $resourceClass): array
    {
        return [self::PARAMETER => [
            'property' => null,
            'type' => 'string',
            'required' => false,
            'description' => 'Words searched in: '.implode(', ', array_keys($this->properties ?? [])),
        ]];
    }
}
