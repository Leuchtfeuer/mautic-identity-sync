<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerIdentitySyncBundle\Utility;

use Doctrine\ORM\EntityManager;

class DataProviderUtility {
    protected EntityManager $entityManager;

    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    /**
     * It's not easy to extend the LeadFieldRepository, so we use this utility method to return unique contact field-names
     *
     * @param string $object
     * @return mixed[]|null
     * @throws \Doctrine\DBAL\Exception
     */
    public function getUniqueIdentifierFieldNames(string $object = 'lead'): ?array
    {
        $qb = $this->entityManager->getConnection()->createQueryBuilder();

        $result = $qb->select('f.alias, f.is_unique_identifer as is_unique, f.type, f.object')
            ->from(MAUTIC_TABLE_PREFIX.'lead_fields', 'f')
            ->where($qb->expr()->and(
                $qb->expr()->eq('object', ':object'),
                $qb->expr()->eq('is_unique_identifer', 1),
            ))
            ->setParameter('object', $object)
            ->orderBy('f.field_order', 'ASC')
            ->execute()->fetchAll();

        if (empty($result)) {
            return null;
        }

        $fieldNames = [];
        foreach ($result as $item) {
            $fieldNames[] = $item['alias'];
        }

        return $fieldNames;
    }
}
