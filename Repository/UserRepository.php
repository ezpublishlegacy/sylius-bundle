<?php

namespace xrow\syliusBundle\Repository;

use xrow\syliusBundle\Entity\User as SyliusUser;

use Sylius\Bundle\CoreBundle\Doctrine\ORM\UserRepository as SyliusProductRepository;

use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;

/**
* UserRepository
*
* This class was generated by the Doctrine ORM. Add your own custom
* repository methods below.
*/
class UserRepository extends EntityRepository
{
    private $container;
    private $eZAPIRepository;

    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
        $this->eZAPIRepository = $this->container->get('ezpublish.api.repository'); // eZ\Publish\Core\SignalSlot\ContentService
    }

    public function getContainer()
    {
        $this->container = $container;
    }

    public function find($contentobject_id)
    {
        if($this->container) {
            $contentObject = $this->eZAPIRepository->getContentService()->loadContent($contentobject_id); // eZ\Publish\Core\Repository\Values\Content\Content
            $user = $this->createNew();
            $user->setEZObject($contentObject);
            return $user;
        }
        else {
            throw new InvalidArgumentException('ContainerInterface container not set.');
        }
    }
}