<?php

declare(strict_types=1);

namespace Zangra\SyliusBarcodePrintPlugin\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\Persistence\ObjectManager;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelPrinter;
use Zangra\SyliusBarcodePrintPlugin\Entity\LabelTemplate;

/**
 * Keeps at most one default printer and one default label template: when an entity is saved as default,
 * the flag is cleared on every other entity of the same class.
 */
final class SingleDefaultListener
{
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->unsetOthers($args->getObject(), $args->getObjectManager());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->unsetOthers($args->getObject(), $args->getObjectManager());
    }

    private function unsetOthers(object $entity, ObjectManager $manager): void
    {
        if (!$entity instanceof LabelPrinter && !$entity instanceof LabelTemplate) {
            return;
        }
        if (!$entity->isDefault() || !$manager instanceof EntityManagerInterface) {
            return;
        }

        $manager->createQueryBuilder()
            ->update($entity::class, 'e')
            ->set('e.isDefault', ':false')
            ->where('e.id <> :id')
            ->andWhere('e.isDefault = :true')
            ->setParameter('false', false)
            ->setParameter('true', true)
            ->setParameter('id', $entity->getId())
            ->getQuery()
            ->execute()
        ;
    }
}
