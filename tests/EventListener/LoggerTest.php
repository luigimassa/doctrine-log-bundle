<?php

namespace Mb\DoctrineLogBundle\Tests\EventListener;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Mb\DoctrineLogBundle\EventListener\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function testPrePersist()
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: array(__DIR__ . "/src"),
            isDevMode: true,
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('getEventManager')
            ->willReturn(new EventManager());
        $entityManager = new EntityManager($connection, $config);
        $this->assertInstanceOf(EntityManager::class, $entityManager);
        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('postPersist')
            ->with($this->isInstanceOf(LifecycleEventArgs::class));
        $logger->expects($this->once())
            ->method('postUpdate')
            ->with($this->isInstanceOf(LifecycleEventArgs::class));
        $logger->expects($this->once())
            ->method('postRemove')
            ->with($this->isInstanceOf(LifecycleEventArgs::class));

        $entityManager->getEventManager()->addEventListener('postPersist', $logger);
        $entityManager->getEventManager()->addEventListener('postUpdate', $logger);
        $entityManager->getEventManager()->addEventListener('postRemove', $logger);

        $eventArgs = $this->createMock(LifecycleEventArgs::class);
        $entityManager->getEventManager()->dispatchEvent('postPersist', $eventArgs);
        $entityManager->getEventManager()->dispatchEvent('postUpdate', $eventArgs);
        $entityManager->getEventManager()->dispatchEvent('postRemove', $eventArgs);

        $eventArgs = $this->createMock(PostFlushEventArgs::class);
        $logger->expects($this->once())
            ->method('postFlush')
            ->with($this->isInstanceOf(PostFlushEventArgs::class));
        $entityManager->getEventManager()->addEventListener('postFlush', $logger);
        $entityManager->getEventManager()->dispatchEvent('postFlush', $eventArgs);

    }


}
